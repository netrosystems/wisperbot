<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LegacyLicenseRemovalTest extends TestCase
{
    public function test_legacy_license_routes_and_middleware_are_not_registered(): void
    {
        $this->assertFalse(Route::has('license.show'));
        $this->assertFalse(Route::has('license.activate'));
        $this->assertFalse(Route::has('install.activate-license'));
        $this->assertFalse(Route::has('admin.license.index'));
        $this->assertFalse(Route::has('admin.license.activate'));
        $this->assertFalse(Route::has('admin.license.deactivate'));

        $this->assertFalse(class_exists(\App\Http\Middleware\EnsureLicensed::class));
        $this->assertFalse(class_exists(\App\Services\License\LicenseManager::class));
    }
}
