<?php

namespace App\Modules\Inbox\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Services\HumanHandoffService;
use App\Modules\Inbox\Services\TypingPresence;
use App\Modules\Inbox\Services\WebchatDriver;
use App\Modules\Inbox\Services\WebchatPresence;
use App\Modules\Inbox\Services\WidgetPayloadBuilder;
use App\Modules\Inbox\Services\WidgetVisitorPushService;
use App\Modules\Shared\Models\Conversation;
use App\Services\StorageManager;
use App\Support\WebchatVisitorToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Pusher\Pusher;

/**
 * Public, anonymous API consumed by the embedded website chat widget. No session
 * / CSRF. Access is scoped by: (1) the unguessable widget_key, (2) a per-widget
 * domain whitelist, and (3) a signed visitor session token bound to one
 * conversation. Realtime uses private Pusher channels with polling fallback.
 */
class ChatWidgetPublicController extends Controller
{
    public function __construct(
        private readonly WebchatDriver $driver,
        private readonly StorageManager $storageManager,
        private readonly HumanHandoffService $humanHandoff,
        private readonly WebchatPresence $presence,
        private readonly WidgetPayloadBuilder $payloads,
        private readonly WidgetVisitorPushService $visitorPush,
    ) {}

    /** POST /widget/v1/session — start or restore a visitor's chat session. */
    public function session(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'visitor_id' => ['nullable', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'max:190'],
            'avatar' => ['nullable', 'string', 'max:512'],
            'external_id' => ['nullable', 'string', 'max:190'],
            'user_hash' => ['nullable', 'string', 'max:128'],
            'push' => ['nullable', 'array'],
            'push.token' => ['nullable', 'string', 'max:255'],
        ]);

        $widget = $this->resolveWidget($data['key']);
        $this->assertDomainAllowed($widget, $request);

        $identity = $this->resolveIdentity($widget, $data);
        // IP is derived by the server and can never be spoofed through the
        // public widget payload.
        $identity['ip_address'] = $request->ip();
        $resume = $this->verifiedResumePayload($request, $widget, (string) ($data['visitor_id'] ?? ''));

        if ($resume) {
            $visitorId = (string) $resume['v'];
            $conversation = Conversation::where('id', (int) $resume['c'])
                ->where('workspace_id', $widget->workspace_id)
                ->where('channel_account_id', $widget->channel_account_id)
                ->first();
        } else {
            // Never mint access to a previous thread from a caller-supplied
            // visitor id alone. A valid encrypted token is required to resume;
            // otherwise the server creates a new, isolated browser identity.
            $visitorId = (string) Str::uuid();
            $conversation = null;
        }

        if ($conversation) {
            // A visitor can sign in after the anonymous widget session was
            // created. Refresh the bound contact instead of silently keeping
            // the old "Customer N" identity forever.
            $conversation = $this->driver->syncConversationIdentity($conversation, $visitorId, $identity);
        } else {
            $conversation = $this->driver->resolveConversation($widget, $visitorId, $identity);
        }
        $this->presence->touch($conversation, $request->ip());
        $this->visitorPush->register($widget, $conversation, $visitorId, $data['push']['token'] ?? null);
        $token = WebchatVisitorToken::issue($conversation->id, $widget->widget_key, $visitorId);

        return response()->json([
            'visitor_id' => $visitorId,
            'conversation_id' => $conversation->id,
            'token' => $token,
            'config' => $widget->publicConfig(),
            'online' => $this->isOnline($widget),
            'messages' => $this->payloads->messages($conversation->id, $widget, 0),
            'handoff' => $this->payloads->handoff($widget, $conversation),
        ]);
    }

    /** POST /widget/v1/messages — visitor sends a message. */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'message' => ['nullable', 'string', 'max:4000'],
            'type' => ['nullable', 'in:text,audio,image'],
            'attachment' => [
                'nullable', 'file', 'max:10240',
                'mimes:jpg,jpeg,png,webp,mp3,aac,m4a,amr,ogg,oga,wav,webm',
            ],
            'client_message_id' => ['nullable', 'string', 'max:64'],
        ]);

        $widget = $this->resolveWidget($data['key']);
        $this->assertDomainAllowed($widget, $request);
        $payload = $this->authVisitor($request, $widget);

        // Append to the exact conversation the session token is bound to (never
        // re-resolve by device id — see WebchatDriver::recordInboundMessage).
        $conversation = Conversation::where('id', $payload['c'])
            ->where('workspace_id', $widget->workspace_id)
            ->first();
        abort_if($conversation === null, 404, 'Conversation not found.');
        $this->presence->touch($conversation, $request->ip());

        $type = $data['type'] ?? 'text';
        $body = trim((string) ($data['message'] ?? ''));
        $messagePayload = [];

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $mimeType = $file->getMimeType() ?? 'application/octet-stream';
            $isAudioRecording = str_starts_with($mimeType, 'audio/')
                || in_array($mimeType, ['video/webm', 'application/ogg'], true);
            $isImage = str_starts_with($mimeType, 'image/');
            abort_unless($isAudioRecording || $isImage, 422, 'Only image uploads and audio recordings are accepted here.');

            $type = $isImage ? 'image' : 'audio';
            $storedPath = $this->storageManager->prefixedPath('message-media/'.$file->hashName());
            $this->storageManager->disk()->putFileAs(dirname($storedPath), $file, basename($storedPath));

            $messagePayload = [
                'preview_url' => $this->browserSafePublicUrl($this->storageManager->disk()->url($storedPath)),
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $mimeType,
                'caption' => $body !== '' ? $body : null,
            ];
            $body = $body !== ''
                ? $body
                : ($isImage ? ($file->getClientOriginalName() ?: 'Image attachment') : 'Voice message');
        }

        abort_if($type === 'text' && $body === '', 422, 'Message body is required.');

        app(TypingPresence::class)->setVisitor($conversation, false);
        $message = $this->driver->recordInboundMessage($conversation, $payload['v'], $body, $type, $messagePayload);

        $messagePayloadData = $this->payloads->message($message, $widget);
        if (! empty($data['client_message_id'])) {
            $messagePayloadData['client_message_id'] = (string) $data['client_message_id'];
        }

        return response()->json([
            'message' => $messagePayloadData,
            'handoff' => $this->payloads->handoff($widget, $conversation->refresh()),
        ]);
    }

    /** GET /widget/v1/messages?after=ID — poll for new messages. */
    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'after' => ['nullable', 'integer'],
        ]);

        $widget = $this->resolveWidget($data['key']);
        $this->assertDomainAllowed($widget, $request);
        $payload = $this->authVisitor($request, $widget);

        $conversation = Conversation::where('id', (int) $payload['c'])
            ->where('workspace_id', $widget->workspace_id)
            ->where('channel_account_id', $widget->channel_account_id)
            ->firstOrFail();
        $agentTyping = app(TypingPresence::class)->agent($conversation);
        $this->presence->touch($conversation, $request->ip());

        return response()->json([
            'messages' => $this->payloads->messages((int) $payload['c'], $widget, (int) ($data['after'] ?? 0)),
            'online' => $this->isOnline($widget),
            'handoff' => $this->payloads->handoff($widget, $conversation),
            'agent_typing' => [
                'is_typing' => $agentTyping !== null,
                'name' => $agentTyping['user_name'] ?? null,
            ],
            'command' => $this->presence->command($conversation),
        ]);
    }

    /** POST /widget/v1/typing — short-lived visitor typing presence. */
    public function typing(Request $request, TypingPresence $typingPresence): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'is_typing' => ['required', 'boolean'],
        ]);

        $widget = $this->resolveWidget($data['key']);
        $this->assertDomainAllowed($widget, $request);
        $payload = $this->authVisitor($request, $widget);

        $conversation = Conversation::where('id', (int) $payload['c'])
            ->where('workspace_id', $widget->workspace_id)
            ->where('channel_account_id', $widget->channel_account_id)
            ->firstOrFail();

        $typingPresence->setVisitor($conversation, (bool) $data['is_typing']);
        $this->presence->touch($conversation, $request->ip());

        return response()->json(['ok' => true]);
    }

    /** POST /widget/v1/broadcasting/auth — private Pusher channel auth for visitors. */
    public function broadcastingAuth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'socket_id' => ['required', 'string', 'max:64'],
            'channel_name' => ['required', 'string', 'max:255'],
        ]);

        $widget = $this->resolveWidget($data['key']);
        $this->assertDomainAllowed($widget, $request);
        $payload = $this->authVisitor($request, $widget);

        $conversationId = (int) $payload['c'];
        $channelName = (string) $data['channel_name'];

        abort_unless($channelName === "private-widget-conversation.{$conversationId}", 403, 'Invalid channel.');

        Conversation::where('id', $conversationId)
            ->where('workspace_id', $widget->workspace_id)
            ->where('channel_account_id', $widget->channel_account_id)
            ->firstOrFail();

        $config = config('broadcasting.connections.pusher');
        abort_if(empty($config['key']) || empty($config['secret']) || empty($config['app_id']), 503, 'Realtime is not configured.');

        $pusher = new Pusher(
            (string) $config['key'],
            (string) $config['secret'],
            (string) $config['app_id'],
            (array) ($config['options'] ?? []),
        );

        return response()->json(json_decode($pusher->authorizeChannel($channelName, (string) $data['socket_id']), true));
    }

    /** POST /widget/v1/handoff — visitor asks to continue with a person. */
    public function handoff(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string']]);
        $widget = $this->resolveWidget($data['key']);
        $this->assertDomainAllowed($widget, $request);
        $payload = $this->authVisitor($request, $widget);

        $conversation = Conversation::where('id', (int) $payload['c'])
            ->where('workspace_id', $widget->workspace_id)
            ->where('channel_account_id', $widget->channel_account_id)
            ->firstOrFail();

        abort_unless($widget->hasActiveAiChatbot(), 422, 'AI chat is not enabled for this widget.');
        abort_unless($this->payloads->hasTwoCustomerMessages($conversation), 422, 'Human Agent becomes available after two messages.');

        $conversation = $this->humanHandoff->request($conversation, 'widget_button');

        return response()->json([
            'handoff' => $this->payloads->handoff($widget, $conversation),
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function resolveWidget(string $key): ChatWidget
    {
        return ChatWidget::where('widget_key', $key)->where('enabled', true)->firstOrFail();
    }

    /**
     * Build the trusted identity from client-supplied fields. When the widget has
     * identity verification enabled, we ONLY trust an identity accompanied by a
     * valid HMAC (client signs the external_id — or email — with the widget's
     * identity_secret on their server). Otherwise the identity is accepted as-is
     * (unverified) so simple sites still get names/emails.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveIdentity(ChatWidget $widget, array $data): array
    {
        $identity = array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'avatar' => $data['avatar'] ?? null,
            'external_id' => $data['external_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (empty($identity)) {
            return [];
        }

        if ($widget->identity_verification) {
            $signedValue = (string) ($data['external_id'] ?? ($data['email'] ?? ''));
            $provided = (string) ($data['user_hash'] ?? '');
            $expected = hash_hmac('sha256', $signedValue, (string) $widget->identity_secret);

            if ($signedValue === '' || $provided === '' || ! hash_equals($expected, $provided)) {
                return []; // unverified → treat visitor as anonymous
            }

            $identity['identity_verified'] = true;
        } else {
            // Names/emails may still improve the agent experience, but an
            // unsigned public external_id must never unlock another customer's
            // cross-device history. Cross-device identity requires HMAC mode.
            unset($identity['external_id']);
            $identity['identity_verified'] = false;
        }

        return $identity;
    }

    /**
     * A browser may resume only the conversation named by its valid encrypted
     * token. Supplying a visitor UUID without that token is intentionally
     * insufficient because the embed key is public.
     *
     * @return array{c:int,w:string,v:string,e:int}|null
     */
    private function verifiedResumePayload(Request $request, ChatWidget $widget, string $visitorId): ?array
    {
        $token = $request->headers->get('X-Widget-Token') ?: (string) $request->input('token');
        $payload = $token ? WebchatVisitorToken::verify($token, $widget->widget_key) : null;

        if (! $payload || $visitorId === '' || ! hash_equals((string) $payload['v'], $visitorId)) {
            return null;
        }

        return $payload;
    }

    /** Reject requests whose Origin/Referer host isn't in the widget whitelist. */
    private function assertDomainAllowed(ChatWidget $widget, Request $request): void
    {
        $allowed = $widget->allowed_domains ?? [];
        if (empty($allowed)) {
            return; // no whitelist configured → allow any site
        }

        $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer') ?: '';
        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);

        foreach ($allowed as $d) {
            $d = strtolower(preg_replace(['/^https?:\/\//', '/^www\./', '/\/.*$/'], '', (string) $d));
            if ($d !== '' && ($host === $d || str_ends_with($host, '.'.$d))) {
                return;
            }
        }

        abort(403, 'This widget is not allowed on this domain.');
    }

    /**
     * Verify the visitor session token (header X-Widget-Token, or body token).
     *
     * @return array{c:int,w:string,v:string,e:int}
     */
    private function authVisitor(Request $request, ChatWidget $widget): array
    {
        $token = $request->headers->get('X-Widget-Token') ?: (string) $request->input('token');
        $payload = $token ? WebchatVisitorToken::verify($token, $widget->widget_key) : null;

        abort_if($payload === null, 401, 'Invalid or expired session.');

        return $payload;
    }

    /** Keep same-host local-disk URLs safe for an HTTPS public widget. */
    private function browserSafePublicUrl(?string $url): ?string
    {
        if (! $url || ! str_starts_with(strtolower($url), 'http://')) {
            return $url;
        }

        $assetHost = parse_url($url, PHP_URL_HOST);
        $requestHost = request()->getHost();
        $shouldUseHttps = request()->isSecure() || app()->environment('production');

        if ($shouldUseHttps && $assetHost && strcasecmp($assetHost, $requestHost) === 0) {
            return 'https://'.substr($url, strlen('http://'));
        }

        return $url;
    }

    /** Whether the widget is inside its configured working hours (default: always). */
    private function isOnline(ChatWidget $widget): bool
    {
        $wh = $widget->working_hours_json;
        if (empty($wh) || empty($wh['enabled'])) {
            return true;
        }

        try {
            $now = now()->setTimezone($wh['timezone'] ?? 'UTC');
        } catch (\Throwable) {
            $now = now();
        }

        $dayKey = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][(int) $now->format('w')];
        $sched = $wh['schedule'][$dayKey] ?? null;
        if (empty($sched) || empty($sched['enabled'])) {
            return false;
        }

        $cur = (int) $now->format('H') * 60 + (int) $now->format('i');
        [$oh, $om] = array_pad(explode(':', (string) ($sched['open'] ?? '00:00')), 2, '0');
        [$ch, $cm] = array_pad(explode(':', (string) ($sched['close'] ?? '23:59')), 2, '0');

        return $cur >= ((int) $oh * 60 + (int) $om) && $cur < ((int) $ch * 60 + (int) $cm);
    }
}
