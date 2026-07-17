<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace-scoped CRUD for website live-chat widgets. Each widget owns one
 * `webchat` channel_account so its conversations land in the omnichannel inbox;
 * the AI toggle simply writes ai_chatbot_id into that account's meta_json, which
 * the existing AutoReplyListener reads to auto-answer.
 */
class ChatWidgetController extends Controller
{
    public function index(Request $request): Response
    {
        $widgets = ChatWidget::where('workspace_id', $this->workspaceId($request))->latest()->get();

        return Inertia::render('Chat/Widgets/Index', [
            'widgets' => $widgets,
            'embedBase' => rtrim(url('/'), '/'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Chat/Widgets/Create', [
            'chatbots' => $this->chatbots($request),
        ]);
    }

    public function edit(Request $request, ChatWidget $chatWidget): Response
    {
        $this->assertOwner($request, $chatWidget);

        return Inertia::render('Chat/Widgets/Edit', [
            'widget' => $chatWidget,
            'chatbots' => $this->chatbots($request),
            'embedBase' => rtrim(url('/'), '/'),
            // Server-side only (the model hides identity_secret); shown to the
            // client so they can HMAC-sign logged-in users on their backend.
            'identitySecret' => $chatWidget->identity_secret,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $workspaceId = $this->workspaceId($request);

        $channelAccount = ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => 'webchat',
            'status' => 'active',
            'display_name' => $data['name'] ?: 'Website chat',
            'meta_json' => $this->metaFor($data),
        ]);

        ChatWidget::create(array_merge($data, [
            'workspace_id' => $workspaceId,
            'channel_account_id' => $channelAccount->id,
        ]));

        return redirect()->route('client.inbox.chat-widgets.index')->with('success', 'Chat widget created.');
    }

    public function update(Request $request, ChatWidget $chatWidget): RedirectResponse
    {
        $this->assertOwner($request, $chatWidget);
        $data = $this->validated($request);

        $chatWidget->update($data);

        $chatWidget->channelAccount?->update([
            'display_name' => $data['name'] ?: 'Website chat',
            'meta_json' => $this->metaFor($data),
        ]);

        return back()->with('success', 'Widget updated.');
    }

    public function destroy(Request $request, ChatWidget $chatWidget): RedirectResponse
    {
        $this->assertOwner($request, $chatWidget);

        // Keep the channel_account (mark inactive) so past conversations still
        // resolve in the inbox — just deactivate and remove the widget config.
        $chatWidget->channelAccount?->update(['status' => 'inactive']);
        $chatWidget->delete();

        return redirect()->route('client.inbox.chat-widgets.index')->with('success', 'Widget deleted.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:128'],
            'title' => ['nullable', 'string', 'max:128'],
            'subtitle' => ['nullable', 'string', 'max:160'],
            'welcome_message' => ['nullable', 'string', 'max:1000'],
            'agent_name' => ['nullable', 'string', 'max:64'],
            'avatar_url' => ['nullable', 'string', 'max:512'],
            'primary_color' => ['nullable', 'string', 'max:16'],
            'position' => ['required', 'in:bottom_right,bottom_left'],
            'launcher_text' => ['nullable', 'string', 'max:64'],
            'ai_chatbot_id' => ['nullable', 'integer'],
            'prechat_fields' => ['nullable', 'array'],
            'offline_message' => ['nullable', 'string', 'max:512'],
            'allowed_domains' => ['nullable', 'array'],
            'working_hours_json' => ['nullable', 'array'],
        ]);

        // Coerce booleans explicitly (Inertia may omit unchecked toggles).
        $data['ai_enabled'] = $request->boolean('ai_enabled');
        $data['require_prechat'] = $request->boolean('require_prechat');
        $data['identity_verification'] = $request->boolean('identity_verification');
        $data['enabled'] = $request->has('enabled') ? $request->boolean('enabled') : true;

        return $data;
    }

    /**
     * The webchat channel_account's meta_json. ai_chatbot_id is only set when AI
     * is enabled — that single field is what AutoReplyListener keys off to
     * auto-answer this widget's conversations.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function metaFor(array $data): array
    {
        return ! empty($data['ai_enabled']) && ! empty($data['ai_chatbot_id'])
            ? ['ai_chatbot_id' => (int) $data['ai_chatbot_id']]
            : [];
    }

    private function chatbots(Request $request)
    {
        return AiChatbot::where('workspace_id', $this->workspaceId($request))
            ->where('enabled', true)
            ->get(['id', 'name']);
    }

    private function workspaceId(Request $request): int
    {
        return $request->user()->current_workspace_id ?? $request->user()->workspace_id;
    }

    private function assertOwner(Request $request, ChatWidget $widget): void
    {
        abort_unless((int) $widget->workspace_id === $this->workspaceId($request), 403);
    }
}
