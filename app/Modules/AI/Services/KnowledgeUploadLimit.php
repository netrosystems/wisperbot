<?php

namespace App\Modules\AI\Services;

/** The largest knowledge file a client can upload: 20 MB, or less when PHP allows less. */
class KnowledgeUploadLimit
{
    public function maxKb(): int
    {
        $appMaxKb = 20 * 1024;
        $serverMaxKb = min(
            $this->iniSizeToKb(ini_get('upload_max_filesize')),
            $this->iniSizeToKb(ini_get('post_max_size')),
        );

        // Leave room for multipart form overhead so a file at the exact PHP
        // limit does not get rejected by the web server before Laravel sees it.
        $serverMaxKb = max(1024, $serverMaxKb - 512);

        return min($appMaxKb, $serverMaxKb);
    }

    private function iniSizeToKb(string|false $value): int
    {
        if ($value === false || trim($value) === '') {
            return PHP_INT_MAX;
        }

        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024),
            'm' => (int) ($number * 1024),
            'k' => (int) $number,
            default => (int) ceil($number / 1024),
        };
    }
}
