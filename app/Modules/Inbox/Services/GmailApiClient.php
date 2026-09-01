<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GmailApiClient
{
    public const SCOPES = 'openid email profile https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send';

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        $app = $this->appCredentials();

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $app['client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent select_account',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $app = $this->appCredentials();
        $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);
        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException($response->json('error_description') ?: 'Google did not issue an access token.');
        }

        return $response->json();
    }

    public function profile(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo');
        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'Unable to read the Google mailbox profile.');
        }

        return $response->json();
    }

    public function syncInbox(ChannelAccount $account): array
    {
        $meta = $account->meta_json ?? [];
        $since = isset($meta['last_synced_at'])
            ? Carbon::parse($meta['last_synced_at'])->subDay()
            : now()->subDays(7);
        $list = $this->request($account)->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', [
            'labelIds' => 'INBOX',
            'q' => 'after:'.$since->timestamp,
            'maxResults' => 200,
        ]);
        if (! $list->successful()) {
            throw new RuntimeException($this->error($list->json(), 'Gmail mailbox sync failed.'));
        }

        $messages = [];
        foreach ($list->json('messages', []) as $summary) {
            $response = $this->request($account)->get(
                'https://gmail.googleapis.com/gmail/v1/users/me/messages/'.rawurlencode((string) $summary['id']),
                ['format' => 'full'],
            );
            if ($response->successful()) {
                $messages[] = $this->normalise($response->json());
            }
        }
        $account->update(['meta_json' => array_merge($meta, [
            'last_synced_at' => now()->toIso8601String(),
            'last_sync_error' => null,
        ])]);

        return $messages;
    }

    public function sendMessage(ChannelAccount $account, string $to, string $subject, string $body, array $cc = [], array $bcc = [], array $attachments = []): string
    {
        return $this->send($account, $to, $subject, $body, null, null, $cc, $bcc, $attachments);
    }

    public function sendReply(ChannelAccount $account, string $to, string $subject, string $body, ?string $inReplyTo, ?string $threadId, array $attachments = []): string
    {
        return $this->send($account, $to, $subject, $body, $inReplyTo, $threadId, [], [], $attachments);
    }

    public function verify(ChannelAccount $account): bool
    {
        return $this->request($account)->get('https://gmail.googleapis.com/gmail/v1/users/me/profile')->successful();
    }

    private function send(ChannelAccount $account, string $to, string $subject, string $body, ?string $inReplyTo = null, ?string $threadId = null, array $cc = [], array $bcc = [], array $attachments = []): string
    {
        $from = (string) ($account->meta_json['email'] ?? '');
        $headers = [
            'From: '.$this->header($account->display_name ?: $from).' <'.$from.'>',
            'To: '.$to,
            'Subject: '.$this->header($subject),
            'MIME-Version: 1.0',
        ];
        if ($cc !== []) {
            $headers[] = 'Cc: '.implode(', ', $cc);
        }
        if ($bcc !== []) {
            $headers[] = 'Bcc: '.implode(', ', $bcc);
        }
        if ($inReplyTo) {
            $messageId = '<'.trim($inReplyTo, '<>').'>';
            $headers[] = 'In-Reply-To: '.$messageId;
            $headers[] = 'References: '.$messageId;
        }

        if (empty($attachments)) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: quoted-printable';
            $raw = implode("\r\n", $headers)."\r\n\r\n".quoted_printable_encode(nl2br(e($body)));
        } else {
            $boundary = '=_mail_'.md5(uniqid((string) mt_rand(), true));
            $headers[] = 'Content-Type: multipart/mixed; boundary="'.$boundary.'"';

            $bodyPart = "--{$boundary}\r\n"
                ."Content-Type: text/html; charset=UTF-8\r\n"
                ."Content-Transfer-Encoding: quoted-printable\r\n\r\n"
                .quoted_printable_encode(nl2br(e($body)))."\r\n";

            $attParts = '';
            foreach ($attachments as $att) {
                $rawBytes = $att['raw_bytes'] ?? (file_exists($att['path'] ?? '') ? file_get_contents($att['path']) : null);
                if ($rawBytes === null) {
                    continue;
                }
                $filename = $att['filename'] ?? 'attachment';
                $mimeType = $att['mime_type'] ?? 'application/octet-stream';
                $encodedFile = chunk_split(base64_encode($rawBytes), 76, "\r\n");

                $attParts .= "--{$boundary}\r\n"
                    ."Content-Type: {$mimeType}; name=\"".addslashes($filename)."\"\r\n"
                    ."Content-Transfer-Encoding: base64\r\n"
                    ."Content-Disposition: attachment; filename=\"".addslashes($filename)."\"\r\n\r\n"
                    .$encodedFile;
            }

            $raw = implode("\r\n", $headers)."\r\n\r\n".$bodyPart.$attParts."--{$boundary}--\r\n";
        }

        $payload = ['raw' => $this->base64Url($raw)];
        if ($threadId) {
            $payload['threadId'] = $threadId;
        }
        $response = $this->request($account)->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', $payload);
        if (! $response->successful() || ! $response->json('id')) {
            throw new RuntimeException($this->error($response->json(), 'Google rejected the email.'));
        }

        return 'gmail:'.$response->json('id');
    }

    private function normalise(array $message): array
    {
        $headers = collect(data_get($message, 'payload.headers', []))
            ->mapWithKeys(fn (array $header) => [strtolower((string) ($header['name'] ?? '')) => (string) ($header['value'] ?? '')]);
        [$name, $address] = $this->address($this->decodeHeader((string) $headers->get('from', '')));

        return [
            'id' => 'gmail:'.(string) ($message['id'] ?? ''),
            'internetMessageId' => trim((string) $headers->get('message-id', ''), '<>'),
            'conversationId' => (string) ($message['threadId'] ?? $message['id'] ?? ''),
            'subject' => $this->decodeHeader((string) $headers->get('subject', '(no subject)')),
            'from' => ['emailAddress' => ['address' => $address, 'name' => $name]],
            'receivedDateTime' => isset($message['internalDate'])
                ? Carbon::createFromTimestampMs((int) $message['internalDate'])->toIso8601String()
                : now()->toIso8601String(),
            'bodyPreview' => (string) ($message['snippet'] ?? ''),
            'body' => ['content' => $this->body((array) ($message['payload'] ?? []))],
            'isRead' => ! in_array('UNREAD', $message['labelIds'] ?? [], true),
            'hasAttachments' => $this->hasAttachment((array) ($message['payload'] ?? [])),
        ];
    }

    private function body(array $part): string
    {
        $mime = strtolower((string) ($part['mimeType'] ?? ''));
        $data = data_get($part, 'body.data');
        if (is_string($data) && ($mime === 'text/html' || $mime === 'text/plain')) {
            return $this->decode($data);
        }
        $plain = '';
        foreach ($part['parts'] ?? [] as $child) {
            $content = $this->body($child);
            if ($content !== '') {
                if (strtolower((string) ($child['mimeType'] ?? '')) === 'text/html') {
                    return $content;
                }
                $plain = $plain ?: $content;
            }
        }

        return $plain;
    }

    private function hasAttachment(array $part): bool
    {
        if (! empty($part['filename'])) {
            return true;
        }
        foreach ($part['parts'] ?? [] as $child) {
            if ($this->hasAttachment($child)) {
                return true;
            }
        }

        return false;
    }

    private function address(string $value): array
    {
        if (preg_match('/^(.*?)\s*<([^>]+)>$/', $value, $matches)) {
            return [trim($matches[1], " \t\n\r\0\x0B\""), strtolower(trim($matches[2]))];
        }

        return ['', strtolower(trim($value))];
    }

    private function request(ChannelAccount $account): PendingRequest
    {
        return Http::withToken($this->accessToken($account))->acceptJson()->timeout(30);
    }

    private function accessToken(ChannelAccount $account): string
    {
        return Cache::lock('gmail-token:'.$account->id, 15)->block(5, function () use ($account): string {
            $account->refresh();
            $credentials = $account->credentials ?? [];
            if (! empty($credentials['access_token']) && now()->addMinute()->lt($credentials['expires_at'] ?? now()->subMinute())) {
                return $credentials['access_token'];
            }
            if (empty($credentials['refresh_token'])) {
                throw new RuntimeException('Google mailbox authorization has expired. Reconnect the account.');
            }
            $app = $this->appCredentials();
            $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'client_id' => $app['client_id'],
                'client_secret' => $app['client_secret'],
                'refresh_token' => $credentials['refresh_token'],
                'grant_type' => 'refresh_token',
            ]);
            if (! $response->successful() || ! $response->json('access_token')) {
                throw new RuntimeException($response->json('error_description') ?: 'Google token refresh failed.');
            }
            $tokens = $response->json();
            $credentials = array_merge($credentials, [
                'access_token' => $tokens['access_token'],
                'expires_at' => now()->addSeconds(max(60, ((int) ($tokens['expires_in'] ?? 3600)) - 60))->toIso8601String(),
            ]);
            $account->update(['credentials' => $credentials]);

            return $credentials['access_token'];
        });
    }

    private function appCredentials(): array
    {
        $config = IntegrationConfig::forProvider('oauth_google_mail');
        $credentials = $config?->credentials ?? [];
        if (! $config?->enabled || empty($credentials['client_id']) || empty($credentials['client_secret'])) {
            throw new RuntimeException('Google Mail OAuth is not configured by the system administrator.');
        }

        return ['client_id' => trim((string) $credentials['client_id']), 'client_secret' => (string) $credentials['client_secret']];
    }

    private function decode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);

        return (string) base64_decode($value, true);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function header(string $value): string
    {
        return mb_encode_mimeheader(str_replace(["\r", "\n"], '', $value), 'UTF-8');
    }

    private function decodeHeader(string $value): string
    {
        return function_exists('iconv_mime_decode')
            ? (iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $value)
            : $value;
    }

    private function error(array $payload, string $fallback): string
    {
        return (string) data_get($payload, 'error.message', data_get($payload, 'error_description', $fallback));
    }
}
