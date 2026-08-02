<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inbox\Services\TelegramBusinessClient;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramBusinessSetupController extends Controller
{
    public function connect(Request $request): RedirectResponse
    {
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
        $client = TelegramBusinessClient::configured();

        if (! $client) {
            return back()->with('error', 'Telegram Business is not configured. Ask the Super Admin to configure and enable the Telegram platform bot.');
        }

        try {
            $client->registerWebhook();
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $code = Str::random(32);
        $expiresAt = now()->addMinutes(15);
        $userId = (int) $request->user()->id;

        DB::transaction(function () use ($workspaceId, $code, $expiresAt, $userId): void {
            // Only the newest deep link is valid. Keeping multiple live pairing
            // rows makes it possible for an old Telegram tab to connect an
            // unintended workspace account later.
            ChannelAccount::where('workspace_id', $workspaceId)
                ->where('channel', 'telegram')
                ->where('status', 'inactive')
                ->delete();

            ChannelAccount::create([
                'workspace_id' => $workspaceId,
                'channel' => 'telegram',
                'provider' => 'telegram_business',
                'display_name' => 'Telegram Business — awaiting pairing',
                'status' => 'inactive',
                'meta_json' => [
                    'pairing_code_hash' => hash('sha256', $code),
                    'pairing_expires_at' => $expiresAt->toIso8601String(),
                    'pairing_created_by' => $userId,
                ],
            ]);
        });

        return redirect()->away('https://t.me/'.rawurlencode($client->botUsername()).'?start=wb_'.$code);
    }
}
