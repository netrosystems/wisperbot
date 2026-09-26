<?php

namespace App\Services\Marketing;

use Illuminate\Http\Request;

/**
 * Marketing-measurement consent recorded by the public cookie banner.
 *
 * The banner writes a first-party cookie the server can read (it is excluded
 * from cookie encryption). The server never assumes consent: only an explicit
 * `granted` value allows Conversions API events. Where the banner applies an
 * implied default (outside European time zones) it writes that value itself,
 * so browser and server decisions always agree.
 */
class MarketingConsent
{
    public const COOKIE = 'wb_marketing_consent';

    public const GRANTED = 'granted';

    public const DENIED = 'denied';

    public static function granted(?Request $request): bool
    {
        return $request?->cookie(self::COOKIE) === self::GRANTED;
    }
}
