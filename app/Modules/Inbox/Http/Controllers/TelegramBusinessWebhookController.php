<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inbox\Jobs\ProcessTelegramBusinessUpdateJob;
use App\Modules\Inbox\Services\TelegramBusinessClient;
use App\Services\WebhookIdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramBusinessWebhookController extends Controller
{
    public function receive(Request $request, WebhookIdempotencyService $idempotency): JsonResponse
    {
        $client = TelegramBusinessClient::configured();
        $expected = $client?->webhookSecret() ?? '';
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            Log::warning('telegram.webhook.invalid_secret', ['ip' => $request->ip()]);

            return response()->json(['ok' => false], 401);
        }

        $update = $request->validate([
            'update_id' => ['required', 'integer'],
            'message' => ['nullable', 'array'],
            'business_connection' => ['nullable', 'array'],
            'business_message' => ['nullable', 'array'],
            'edited_business_message' => ['nullable', 'array'],
            'deleted_business_messages' => ['nullable', 'array'],
        ]);

        if (! $idempotency->isNewEvent('telegram_business', (string) $update['update_id'])) {
            return response()->json(['ok' => true]);
        }

        ProcessTelegramBusinessUpdateJob::dispatch($update);

        return response()->json(['ok' => true]);
    }
}
