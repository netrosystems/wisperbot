<?php

namespace Tests\Unit;

use App\Services\AppVersionManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AppVersionManagerTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = tempnam(sys_get_temp_dir(), 'wisperbot-version-');
        file_put_contents($this->envPath, "APP_NAME=WisperBot\nAPP_VERSION=1.0.0\n");
    }

    protected function tearDown(): void
    {
        foreach ([$this->envPath, $this->envPath.'.version.lock'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_it_increments_semantic_version_parts(): void
    {
        $versions = new AppVersionManager($this->envPath);

        $this->assertSame('1.0.1', $versions->bump());
        $this->assertSame('1.1.0', $versions->bump('minor'));
        $this->assertSame('2.0.0', $versions->bump('major'));
        $this->assertStringContainsString('APP_VERSION=2.0.0', file_get_contents($this->envPath));
    }

    public function test_it_accepts_an_exact_version_with_optional_v_prefix(): void
    {
        $versions = new AppVersionManager($this->envPath);

        $this->assertSame('3.4.5', $versions->set('v3.4.5'));
        $this->assertSame('3.4.5', $versions->current());
    }

    public function test_it_rejects_an_invalid_version(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AppVersionManager($this->envPath))->set('release-one');
    }

    public function test_it_advances_only_once_for_each_deployed_revision(): void
    {
        $versions = new AppVersionManager($this->envPath);

        $first = $versions->bumpForRelease('abc123');
        $sameRelease = $versions->bumpForRelease('abc123');
        $next = $versions->bumpForRelease('def456');

        $this->assertSame(['version' => '1.0.1', 'changed' => true], $first);
        $this->assertSame(['version' => '1.0.1', 'changed' => false], $sameRelease);
        $this->assertSame(['version' => '1.0.2', 'changed' => true], $next);
        $this->assertStringContainsString('APP_RELEASE_COMMIT=def456', file_get_contents($this->envPath));
    }

    public function test_release_lock_does_not_cause_duplicate_versions(): void
    {
        $firstManager = new AppVersionManager($this->envPath);
        $secondManager = new AppVersionManager($this->envPath);

        $this->assertTrue($firstManager->bumpForRelease('release-commit')['changed']);
        $this->assertFalse($secondManager->bumpForRelease('release-commit')['changed']);
        $this->assertSame('1.0.1', $secondManager->current());
    }
}
