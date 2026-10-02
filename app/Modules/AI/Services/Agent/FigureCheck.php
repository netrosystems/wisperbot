<?php

namespace App\Modules\AI\Services\Agent;

/**
 * The mechanical figure check every Smart Bot reply passes (v1 and engine
 * v2): a price, quantity, size, percentage or duration in the reply must
 * appear in the evidence it was given. The same value written another way
 * (other digit scripts, 1,50,000, 24/7, "percent", "hrs") still matches.
 */
class FigureCheck
{
    /** Digits and separators of other scripts, mapped to ASCII for the figure check. */
    public const DIGITS = [
        '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9',
        '٫' => '.', '٬' => ',',
    ];

    /**
     * True when the text states a price, quantity, size or duration that does not
     * appear in the evidence. Single-digit counts (step numbers) are ignored
     * unless they carry a unit or currency.
     */
    public function unsupported(string $text, string $evidence): bool
    {
        $figures = function (string $value): array {
            $value = strtr(mb_strtolower($value), self::DIGITS);
            // Thousands separators, including South Asian grouping (1,50,000).
            $value = (string) preg_replace('/(?<=\d),(?=\d{2,3}\b)/u', '', $value);
            // The same value written another way: "24/7" states 24 hours.
            $value = (string) preg_replace('/\b24\s*(?:\/|x|×)\s*7\b/u', '24 hours', $value);
            $value = (string) preg_replace('/(?<=\d)\s*(?:percent|per cent)\b/u', '%', $value);
            preg_match_all('/([$€£৳₹¥₨₦₱₩₺₫₪₽﷼]\s*)?(\d+(?:\.\d+)?)\s*(gb|mb|tb|kb|tk|taka|usd|bdt|eur|gbp|inr|pkr|aed|sar|%|days?|hours?|hrs?|weeks?|months?|years?|minutes?|mins?)?(?![\w])/u', $value, $matches, PREG_SET_ORDER);
            $found = [];
            foreach ($matches as $match) {
                $number = str_contains($match[2], '.') ? rtrim(rtrim($match[2], '0'), '.') : ltrim($match[2], '0');
                $number = $number === '' ? '0' : $number;
                $unit = (string) ($match[3] ?? '');
                $unit = match (true) {
                    in_array($unit, ['tk', 'taka', 'bdt'], true) => 'bdt',
                    str_starts_with($unit, 'min') => 'minute',
                    in_array($unit, ['hr', 'hrs'], true) => 'hour',
                    $unit !== '' && ! in_array($unit, ['gb', 'mb', 'tb', 'kb', 'usd', 'eur', 'gbp', '%'], true) => rtrim($unit, 's'),
                    default => $unit,
                };
                $significant = $unit !== '' || trim($match[1]) !== '' || strlen($match[2]) >= 2;
                $found[] = ['number' => $number, 'unit' => $unit, 'significant' => $significant];
            }

            return $found;
        };

        $available = $figures($evidence);
        foreach ($figures($text) as $figure) {
            if (! $figure['significant']) {
                continue;
            }
            // Sizes and percentages need the same unit; currency and time may match a
            // bare number in the evidence (for example "৳128" against "128tk").
            $strictUnit = in_array($figure['unit'], ['gb', 'mb', 'tb', 'kb', '%'], true);
            $supported = array_filter($available, fn (array $candidate): bool => $candidate['number'] === $figure['number']
                && ($figure['unit'] === '' || $candidate['unit'] === $figure['unit'] || (! $strictUnit && $candidate['unit'] === '')));
            if ($supported === []) {
                return true;
            }
        }

        return false;
    }

    /** Digits of other scripts written as ASCII digits. */
    public static function toLatin(string $text): string
    {
        return strtr($text, self::DIGITS);
    }
}
