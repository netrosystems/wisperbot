<?php

namespace Tests\Unit;

use App\Modules\Integrations\Services\Clients\GoogleClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleClientContractTest extends TestCase
{
    public function test_refresh_token_and_sheets_request_use_google_contracts(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1'], 200),
            'sheets.googleapis.com/*' => Http::response([
                'updates' => ['updatedRange' => 'Sheet1!A1', 'updatedCells' => 1],
            ], 200),
        ]);

        $client = new GoogleClient('client-id', 'client-secret', 'refresh-token');
        $result = $client->appendSheetRow('sheet-1', 'Sheet1!A:Z', ['hello']);

        $this->assertSame('Sheet1!A1', $result['updated_range']);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && str_starts_with((string) $request->header('Content-Type')[0], 'application/x-www-form-urlencoded')
                && $request['grant_type'] === 'refresh_token'
                && $request['refresh_token'] === 'refresh-token';
        });
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'sheets.googleapis.com/v4/spreadsheets/sheet-1/values/')
            && $request->header('Authorization')[0] === 'Bearer access-1'
            && $request['values'] === [['hello']]);
    }

    public function test_google_api_errors_are_surfaceable(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1'], 200),
            'sheets.googleapis.com/*' => Http::response(['error' => ['message' => 'Permission denied']], 403),
        ]);

        $this->expectExceptionMessage('Google Sheets append failed: Permission denied');
        (new GoogleClient('client-id', 'client-secret', 'refresh-token'))
            ->appendSheetRow('sheet-1', 'Sheet1!A:Z', ['hello']);
    }
}
