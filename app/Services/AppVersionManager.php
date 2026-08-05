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

    /**
     * Increment the patch version once for each newly deployed Git revision.
     *
     * Keeping the deployed revision in .env makes this idempotent: running the
     * deployment finalisation command again for the same code never creates a
     * misleading extra release number.
     *
     * @return array{version: string, changed: bool}
     */
    public function bumpForRelease(string $revision): array
    {
        $revision = trim($revision);

        if ($revision === '') {
            throw new InvalidArgumentException('A deployment revision is required to create an automatic version.');
        }

        if (hash_equals($this->currentReleaseRevision(), $revision)) {
            return ['version' => $this->current(), 'changed' => false];
        }

        $version = $this->bump('patch');
        (new EnvWriter($this->envPath))->set(['APP_RELEASE_COMMIT' => $revision]);

        return ['version' => $version, 'changed' => true];
    }

    public function currentReleaseRevision(): string
    {
        if (! is_file($this->envPath)) {
            return '';
        }

        $contents = (string) file_get_contents($this->envPath);

        return preg_match('/^APP_RELEASE_COMMIT\s*=\s*["\']?([^\s"\']+)["\']?\s*$/mi', $contents, $matches)
            ? trim($matches[1])
            : '';
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
