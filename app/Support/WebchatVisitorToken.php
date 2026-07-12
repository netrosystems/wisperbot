<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * Opaque, tamper-proof session token issued to an anonymous website chat
 * visitor. It binds a browser session to exactly ONE conversation + widget so a
 * visitor can only read/write their own thread. Encrypted with the app key
 * (self-contained, no DB lookup) and carries an expiry.
 */
class WebchatVisitorToken
{
    /** Issue a token bound to a conversation + widget + visitor id. */
    public static function issue(int $conversationId, string $widgetKey, string $visitorId, int $ttlHours = 720): string
    {
        return Crypt::encryptString(json_encode([
            'c' => $conversationId,
            'w' => $widgetKey,
            'v' => $visitorId,
            'e' => now()->addHours($ttlHours)->getTimestamp(),
        ]));
    }

    /**
     * Verify a token against the widget it must belong to. Returns the decoded
     * payload (['c'=>convId,'w'=>key,'v'=>visitorId,'e'=>exp]) or null if the
     * token is invalid, tampered, for another widget, or expired.
     *
     * @return array{c:int,w:string,v:string,e:int}|null
     */
    public static function verify(string $token, string $widgetKey): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }
        if (($data['w'] ?? null) !== $widgetKey) {
            return null;
        }
        if ((int) ($data['e'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return $data;
    }
}
