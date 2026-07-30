<?php

namespace App\Services;

use App\Services\Install\EnvWriter;
use InvalidArgumentException;

class AppVersionManager
{
    public function __construct(private ?string $envPath = null)
    {
        $this->envPath ??= base_path('.env');
    }

    public function current(): string
    {
        if (is_file($this->envPath)) {
            $contents = (string) file_get_contents($this->envPath);

            if (preg_match('/^APP_VERSION\s*=\s*["\']?v?(\d+\.\d+\.\d+)["\']?\s*$/mi', $contents, $matches)) {
                return $matches[1];
            }
        }

        return $this->normalize((string) config('app.version', '1.0.0'));
    }

    public function bump(string $part = 'patch'): string
    {
        $part = strtolower(trim($part));

        if (! in_array($part, ['major', 'minor', 'patch'], true)) {
            throw new InvalidArgumentException('Version part must be major, minor, or patch.');
        }

        [$major, $minor, $patch] = array_map('intval', explode('.', $this->current()));

        if ($part === 'major') {
            $major++;
            $minor = 0;
            $patch = 0;
        } elseif ($part === 'minor') {
            $minor++;
            $patch = 0;
        } else {
            $patch++;
        }

        return $this->write("$major.$minor.$patch");
    }

    public function set(string $version): string
    {
        return $this->write($this->normalize($version));
    }

    private function write(string $version): string
    {
        (new EnvWriter($this->envPath))->set(['APP_VERSION' => $version]);

        return $version;
    }

    private function normalize(string $version): string
    {
        $version = ltrim(trim($version), 'vV');

        if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new InvalidArgumentException('Version must use semantic format, for example 1.0.1.');
        }

        return $version;
    }
}
