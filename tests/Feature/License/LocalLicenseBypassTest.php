<?php

namespace Tests\Feature\License;

use App\Services\License\LicenseManager;
use Tests\TestCase;

class LocalLicenseBypassTest extends TestCase
{
    public function test_license_verification_is_disabled_only_in_local_environment(): void
    {
        config()->set([
            'license.verify' => true,
            'license.product_id' => 'product-id',
            'license.api_key' => 'api-key',
            'license.server_url' => 'https://licenses.example.test',
        ]);

        $originalEnvironment = app()->environment();

        try {
            app()->detectEnvironment(fn () => 'local');
            $this->assertFalse(app(LicenseManager::class)->enabled());

            app()->detectEnvironment(fn () => 'production');
            $this->assertTrue(app(LicenseManager::class)->enabled());
        } finally {
            app()->detectEnvironment(fn () => $originalEnvironment);
        }
    }
}
