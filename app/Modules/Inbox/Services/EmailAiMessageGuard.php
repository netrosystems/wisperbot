<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Message;

class EmailAiMessageGuard
{
    public function reason(Message $message, ChannelAccount $account): ?string
    {
        if ($account->ai_eligible_from_at && $message->sent_at && $message->sent_at->lt($account->ai_eligible_from_at)) {
            return 'historical_email';
        }

        $payload = $message->payload ?? [];
        $sender = strtolower((string) ($payload['from_address'] ?? data_get($message, 'conversation.contact.email', '')));
        $mailbox = strtolower((string) ($account->meta_json['email'] ?? ''));
        if ($sender !== '' && ($sender === $mailbox || preg_match('/(^|[._-])(no-?reply|do-?not-?reply|mailer-daemon)([._@-]|$)/i', $sender))) {
            return 'automated_email';
        }

        $subject = strtolower((string) ($payload['subject'] ?? ''));
        $autoSubmitted = strtolower((string) ($payload['auto_submitted'] ?? ''));
        $precedence = strtolower((string) ($payload['precedence'] ?? ''));
        if (($autoSubmitted !== '' && $autoSubmitted !== 'no')
            || in_array($precedence, ['bulk', 'junk', 'list'], true)
            || ! empty($payload['list_id'])
            || preg_match('/(out of office|automatic reply|auto.?reply|delivery status notification|undeliver(?:ed|able)|mail delivery failed)/i', $subject)) {
            return 'automated_email';
        }

        if (! empty($payload['is_spam']) || ! empty($payload['is_trash'])) {
            return 'filtered_email';
        }

        if ($this->authoredBody((string) $message->body) === '') {
            return ! empty($payload['has_attachments']) ? 'attachment_only' : 'empty_message';
        }

        return null;
    }

    public function promptBody(Message $message): string
    {
        $body = $this->authoredBody((string) $message->body);
        $subject = html_entity_decode(strip_tags((string) ($message->payload['subject'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $subject = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject));
        $subject = mb_substr($subject, 0, 998);
        $prompt = $subject !== '' ? "Email subject: {$subject}\n\n{$body}" : $body;

        return mb_substr($prompt, 0, 12000);
    }

    private function authoredBody(string $body): string
    {
        $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = preg_split('/\R(?:On .+ wrote:|From:\s|-----Original Message-----)/iu', $body, 2)[0] ?? $body;
        $body = preg_split('/\R--\s*\R/u', $body, 2)[0] ?? $body;
        $body = preg_replace('/[\t ]+/', ' ', $body) ?? $body;
        $body = preg_replace('/\R{3,}/', "\n\n", $body) ?? $body;

        return trim($body);
    }
}
