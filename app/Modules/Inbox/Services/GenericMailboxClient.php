<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class GenericMailboxClient
{
    public function verify(ChannelAccount $account): bool
    {
        $this->open($account, true);
        $this->mailer($account);

        return true;
    }

    public function messages(ChannelAccount $account): array
    {
        $imap = $this->open($account);
        $meta = $account->meta_json ?? [];
        $since = isset($meta['last_synced_at']) ? Carbon::parse($meta['last_synced_at'])->subDay() : now()->subDays(7);
        $uids = imap_search($imap, 'SINCE "'.$since->format('d-M-Y').'"', SE_UID) ?: [];
        $messages = [];

        foreach (array_slice($uids, -200) as $uid) {
            $overview = imap_fetch_overview($imap, (string) $uid, FT_UID)[0] ?? null;
            if (! $overview) {
                continue;
            }
            $header = imap_headerinfo($imap, imap_msgno($imap, $uid));
            $rawHeaders = (string) imap_fetchheader($imap, (string) $uid, FT_UID);
            $content = $this->messageContent($imap, (int) $uid);
            $from = $header->from[0] ?? null;
            $messages[] = [
                'id' => 'imap:'.$account->id.':'.$uid,
                'internetMessageId' => trim((string) ($overview->message_id ?? 'imap-'.$account->id.'-'.$uid), '<>'),
                'conversationId' => trim((string) ($overview->references ?? $overview->in_reply_to ?? $overview->message_id ?? $uid), '<>'),
                'subject' => isset($overview->subject) ? imap_utf8($overview->subject) : '(no subject)',
                'from' => ['emailAddress' => [
                    'address' => $from ? ($from->mailbox.'@'.$from->host) : '',
                    'name' => $from?->personal ? imap_utf8($from->personal) : '',
                ]],
                'receivedDateTime' => isset($overview->date) ? date(DATE_ATOM, strtotime($overview->date)) : now()->toIso8601String(),
                'bodyPreview' => mb_substr(trim(strip_tags($content['plain'] ?: $content['html'])), 0, 500),
                'body' => [
                    'content' => $content['html'] ?: $content['plain'],
                    'contentType' => $content['html'] !== '' ? 'html' : 'text',
                ],
                'isRead' => ! empty($overview->seen),
                'hasAttachments' => $content['has_attachments'],
                'attachments' => $content['attachments'],
                'autoSubmitted' => $this->headerValue($rawHeaders, 'Auto-Submitted'),
                'precedence' => $this->headerValue($rawHeaders, 'Precedence'),
                'listId' => $this->headerValue($rawHeaders, 'List-Id'),
            ];
        }
        imap_close($imap);
        $account->update(['meta_json' => array_merge($meta, [
            'last_synced_at' => now()->toIso8601String(),
            'last_sync_error' => null,
        ])]);

        return $messages;
    }

    public function send(
        ChannelAccount $account,
        string $to,
        string $subject,
        string $body,
        ?string $inReplyTo = null,
        array $cc = [],
        array $bcc = [],
        array $attachments = []
    ): string {
        $credentials = $account->credentials ?? [];
        $mailer = $this->mailer($account);
        $sent = $mailer->html(nl2br(e($body)), function ($message) use ($credentials, $to, $subject, $inReplyTo, $cc, $bcc, $attachments): void {
            $message->to($to)->subject($subject)->from($credentials['username'], $credentials['from_name'] ?? null);
            if ($cc !== []) {
                $message->cc($cc);
            }
            if ($bcc !== []) {
                $message->bcc($bcc);
            }
            if ($inReplyTo) {
                $message->getHeaders()->addTextHeader('In-Reply-To', '<'.trim($inReplyTo, '<>').'>');
                $message->getHeaders()->addTextHeader('References', '<'.trim($inReplyTo, '<>').'>');
            }
            foreach ($attachments as $att) {
                if (! empty($att['raw_bytes'])) {
                    $message->attachData($att['raw_bytes'], $att['filename'] ?? 'attachment', [
                        'mime' => $att['mime_type'] ?? 'application/octet-stream',
                    ]);
                } elseif (! empty($att['path']) && file_exists($att['path'])) {
                    $message->attach($att['path'], [
                        'as' => $att['filename'] ?? basename($att['path']),
                        'mime' => $att['mime_type'] ?? 'application/octet-stream',
                    ]);
                }
            }
        });

        return $sent?->getMessageId() ?: 'smtp:'.bin2hex(random_bytes(12));
    }

    private function open(ChannelAccount $account, bool $close = false): mixed
    {
        if (! function_exists('imap_open')) {
            throw new RuntimeException('The PHP IMAP extension is required for generic mailbox sync.');
        }
        $c = $account->credentials ?? [];
        $flags = match ($c['imap_encryption'] ?? 'ssl') {
            'tls' => '/tls', 'none' => '/notls', default => '/ssl',
        };
        if (! ($c['verify_tls'] ?? true)) {
            $flags .= '/novalidate-cert';
        }
        $mailbox = sprintf('{%s:%d/imap%s}INBOX', $c['imap_host'], $c['imap_port'], $flags);
        $imap = @imap_open($mailbox, $c['username'], $c['password'], 0, 1);
        if (! $imap) {
            throw new RuntimeException('IMAP connection failed: '.(imap_last_error() ?: 'unknown error'));
        }
        if ($close) {
            imap_close($imap);
        }

        return $imap;
    }

    private function mailer(ChannelAccount $account): mixed
    {
        $c = $account->credentials ?? [];

        return Mail::build([
            'transport' => 'smtp',
            'host' => $c['smtp_host'],
            'port' => (int) $c['smtp_port'],
            'encryption' => ($c['smtp_encryption'] ?? 'tls') === 'none' ? null : $c['smtp_encryption'],
            'username' => $c['username'],
            'password' => $c['password'],
            'timeout' => 20,
            'verify_peer' => (bool) ($c['verify_tls'] ?? true),
        ]);
    }

    private function headerValue(string $headers, string $name): string
    {
        return preg_match('/^'.preg_quote($name, '/').':\s*(.+(?:\R[ \t].+)*)/mi', $headers, $matches)
            ? trim((string) preg_replace('/\R[ \t]+/', ' ', $matches[1]))
            : '';
    }

    /** @return array{plain:string,html:string,has_attachments:bool,attachments:array<int, array<string, mixed>>} */
    private function messageContent(mixed $imap, int $uid): array
    {
        $structure = imap_fetchstructure($imap, (string) $uid, FT_UID);
        if (! $structure) {
            return ['plain' => '', 'html' => '', 'has_attachments' => false, 'attachments' => []];
        }

        $plain = '';
        $html = '';
        $hasAttachments = false;
        $attachments = [];
        $this->collectContent($imap, $uid, $structure, '', $plain, $html, $hasAttachments, $attachments);

        return [
            'plain' => $plain,
            'html' => $html,
            'has_attachments' => $hasAttachments,
            'attachments' => $attachments,
        ];
    }

    private function collectContent(mixed $imap, int $uid, object $part, string $partNumber, string &$plain, string &$html, bool &$hasAttachments, array &$attachments): void
    {
        $parameters = array_merge($part->parameters ?? [], $part->dparameters ?? []);
        $filename = collect($parameters)
            ->first(fn ($parameter) => in_array(strtolower((string) ($parameter->attribute ?? '')), ['filename', 'name'], true));
        $name = $filename ? imap_utf8((string) ($filename->value ?? '')) : '';
        $named = $name !== '';
        $disposition = strtolower((string) ($part->disposition ?? ''));
        if ($named || in_array($disposition, ['attachment', 'inline'], true) && (int) ($part->bytes ?? 0) > 0) {
            $hasAttachments = true;
            if ($named || $disposition === 'attachment') {
                $raw = $partNumber === ''
                    ? (string) imap_body($imap, $uid, FT_UID | FT_PEEK)
                    : (string) imap_fetchbody($imap, $uid, $partNumber, FT_UID | FT_PEEK);
                $decoded = $this->decodePart($raw, (int) ($part->encoding ?? 0));
                if ($name !== '' && strlen($decoded) <= 10 * 1024 * 1024) {
                    $attachments[] = [
                        'filename' => $name,
                        'mime_type' => $this->partMimeType($part),
                        'size' => strlen($decoded),
                        'raw_bytes' => $decoded,
                    ];
                }

                return;
            }
        }

        if (! empty($part->parts)) {
            foreach ($part->parts as $index => $child) {
                $number = $partNumber === '' ? (string) ($index + 1) : $partNumber.'.'.($index + 1);
                $this->collectContent($imap, $uid, $child, $number, $plain, $html, $hasAttachments, $attachments);
            }

            return;
        }

        if ((int) ($part->type ?? -1) !== 0) {
            return;
        }
        $raw = $partNumber === ''
            ? (string) imap_body($imap, $uid, FT_UID | FT_PEEK)
            : (string) imap_fetchbody($imap, $uid, $partNumber, FT_UID | FT_PEEK);
        $decoded = $this->decodePart($raw, (int) ($part->encoding ?? 0));
        $subtype = strtolower((string) ($part->subtype ?? 'plain'));
        if ($subtype === 'plain' && $plain === '') {
            $plain = $decoded;
        } elseif ($subtype === 'html' && $html === '') {
            $html = $decoded;
        }
    }

    private function decodePart(string $raw, int $encoding): string
    {
        return match ($encoding) {
            3 => (string) base64_decode($raw, true),
            4 => quoted_printable_decode($raw),
            default => $raw,
        };
    }

    private function partMimeType(object $part): string
    {
        $primary = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'][(int) ($part->type ?? 7)] ?? 'application';

        return $primary.'/'.strtolower((string) ($part->subtype ?? 'octet-stream'));
    }
}
