<?php

namespace App\Modules\Inbox\Jobs;

use App\Events\MessageReceived;
use App\Modules\Inbox\Services\ConversationOwnershipService;
use App\Modules\Inbox\Services\EmailContentSanitizer;
use App\Modules\Inbox\Services\GenericMailboxClient;
use App\Modules\Inbox\Services\GmailApiClient;
use App\Modules\Inbox\Services\MicrosoftGraphMailClient;
use App\Modules\Inbox\Services\SegmentAiPolicyService;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Services\Media\AttachmentService;
use App\Services\StorageManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SyncEmailAccountJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return (string) $this->channelAccountId;
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(public readonly int $channelAccountId) {}

    public function handle(GmailApiClient $google, MicrosoftGraphMailClient $microsoft, GenericMailboxClient $generic): void
    {
        $account = ChannelAccount::where('channel', 'email')->where('status', 'active')->find($this->channelAccountId);
        if (! $account) {
            return;
        }

        try {
            $items = match ($account->provider) {
                'gmail' => $google->syncInbox($account),
                'microsoft_365' => $microsoft->syncInbox($account),
                default => $generic->messages($account),
            };
            foreach ($items as $item) {
                $this->ingest($account, $item);
            }
        } catch (Throwable $e) {
            $meta = $account->meta_json ?? [];
            // Keep the account active so the queue retry and next scheduled poll
            // can recover from a temporary provider/network failure.
            $account->update(['meta_json' => array_merge($meta, ['last_sync_error' => $e->getMessage()])]);
            Log::warning('Email mailbox sync failed', ['channel_account_id' => $account->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function ingest(ChannelAccount $account, array $item): void
    {
        $providerId = trim((string) ($item['id'] ?? ''));
        if ($providerId === '' || Message::where('channel', 'email')
            ->where('provider_message_id', $providerId)
            ->whereHas('conversation', fn ($query) => $query->where('channel_account_id', $account->id))
            ->exists()) {
            return;
        }
        $address = strtolower(trim((string) data_get($item, 'from.emailAddress.address')));
        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $name = trim((string) data_get($item, 'from.emailAddress.name'));
        [$first, $last] = array_pad(preg_split('/\s+/u', $name, 2) ?: [], 2, null);
        $contact = Contact::firstOrCreate(
            ['workspace_id' => $account->workspace_id, 'email' => $address],
            [
                'first_name' => $first ?: $address,
                'last_name' => $last,
                'source' => 'email',
                'opt_in_email' => false,
                'opt_in_sms' => false,
                'opt_in_whatsapp' => false,
            ],
        );
        $thread = (string) ($item['conversationId'] ?? $item['internetMessageId'] ?? $providerId);
        $conversation = Conversation::firstOrCreate(
            [
                'workspace_id' => $account->workspace_id,
                'channel_account_id' => $account->id,
                'contact_id' => $contact->id,
                'external_thread_id' => substr($thread, 0, 128),
            ],
            ['status' => 'open', 'assigned_to' => app(SegmentAiPolicyService::class)->initialHandler($account)],
        );
        $rawBody = (string) data_get($item, 'body.content', $item['bodyPreview'] ?? '');
        $contentType = strtolower((string) data_get($item, 'body.contentType', 'text'));
        $sanitizer = app(EmailContentSanitizer::class);
        $htmlBody = $contentType === 'html' ? $sanitizer->sanitize($rawBody) : '';
        $body = $contentType === 'html' ? $sanitizer->plainText($htmlBody) : trim($rawBody);
        $headers = collect($item['internetMessageHeaders'] ?? [])
            ->mapWithKeys(fn (array $header) => [strtolower((string) ($header['name'] ?? '')) => (string) ($header['value'] ?? '')]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'email',
            'type' => 'text',
            'body' => $body,
            'payload' => array_filter([
                'subject' => (string) ($item['subject'] ?? '(no subject)'),
                'html_body' => $htmlBody ?: null,
                'internet_message_id' => (string) ($item['internetMessageId'] ?? ''),
                'thread_id' => (string) ($item['conversationId'] ?? ''),
                'has_attachments' => (bool) ($item['hasAttachments'] ?? false) || ! empty($item['attachments']),
                'from_address' => $address,
                'auto_submitted' => (string) ($item['autoSubmitted'] ?? $headers->get('auto-submitted', '')),
                'precedence' => (string) ($item['precedence'] ?? $headers->get('precedence', '')),
                'list_id' => (string) ($item['listId'] ?? $headers->get('list-id', '')),
                'is_spam' => (bool) ($item['isSpam'] ?? false),
                'is_trash' => (bool) ($item['isTrash'] ?? false),
            ], fn ($value) => $value !== null),
            'status' => 'delivered',
            'provider_message_id' => $providerId,
            'sent_by' => 'human',
            'sent_at' => $item['receivedDateTime'] ?? now(),
        ]);
        $attachments = $this->storeAttachments($item['attachments'] ?? []);
        if ($attachments !== []) {
            $payload = $message->payload ?? [];
            $payload['attachments'] = $attachments;
            $payload['has_attachments'] = true;
            $message->update(['payload' => $payload]);
        }
        $reopened = app(ConversationOwnershipService::class)->prepareInbound(
            $conversation,
            $message->sent_at,
            1,
        );
        MessageReceived::dispatch($message, $reopened);
    }

    private function storeAttachments(array $sourceAttachments): array
    {
        $storage = app(StorageManager::class);
        $attachmentService = app(AttachmentService::class);
        $allowedExtensions = explode(',', AttachmentService::ALLOWED_MIMES);
        $stored = [];

        foreach ($sourceAttachments as $attachment) {
            $bytes = $attachment['raw_bytes'] ?? null;
            $filename = trim((string) ($attachment['filename'] ?? ''));
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (! is_string($bytes) || $bytes === '' || strlen($bytes) > AttachmentService::MAX_FILE_KILOBYTES * 1024
                || $filename === '' || ! in_array($extension, $allowedExtensions, true)) {
                continue;
            }

            $mimeType = strtolower(trim(explode(';', (string) ($attachment['mime_type'] ?? 'application/octet-stream'))[0]));
            $path = $storage->prefixedPath('message-media/email/'.Str::random(40).'.'.$extension);
            if (! $storage->disk()->put($path, $bytes)) {
                continue;
            }

            $url = $storage->disk()->url($path);
            $stored[] = [
                'name' => $filename,
                'filename' => $filename,
                'url' => $url,
                'preview_url' => $url,
                'path' => $path,
                'size' => strlen($bytes),
                'size_bytes' => strlen($bytes),
                'mime_type' => $mimeType,
                'content_type' => $mimeType,
                'type' => $attachmentService->inferMessageType($mimeType, $extension),
            ];
        }

        return $stored;
    }
}
