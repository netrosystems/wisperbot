<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SecureHeaders;
use Illuminate\Http\Request;
use Tests\TestCase;

class SecureHeadersTest extends TestCase
{
    public function test_csp_allows_the_wisperbot_support_widget_script_and_api(): void
    {
        $request = Request::create('/');
        $response = (new SecureHeaders)->handle(
            $request,
            fn () => response('ok')
        );

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression(
            '/script-src [^;]*https:\\/\\/wisperbot\\.com/',
            $csp
        );
        $this->assertMatchesRegularExpression(
            '/connect-src [^;]*https:\\/\\/wisperbot\\.com/',
            $csp
        );
    }
}
