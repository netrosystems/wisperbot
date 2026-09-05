<?php

namespace App\Modules\Whatsapp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Services\WhatsappConnectionHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class WhatsappConnectionHealthController extends Controller
{
    public static function authorizeManager(Request $request, ?WhatsappBusinessAccount $waba = null): void
    {
        $user = $request->user();
        $workspaceId = $user->current_workspace_id ?? $user->workspace_id;
        abort_if($waba && (int) $waba->workspace_id !== (int) $workspaceId, 403);
        abort_unless(self::canManage($request), 403, 'Only workspace administrators can manage channel connections.');
    }

    public static function canManage(Request $request): bool
    {
        $user = $request->user();
        $workspace = Workspace::findOrFail($user->current_workspace_id ?? $user->workspace_id);
        $allowed = (int) $workspace->owner_id === (int) $user->id
            || ($workspace->client_id && (int) $workspace->client_id === (int) $user->client_id && $user->isClientAdministrator())
            || $workspace->members()->where('user_id', $user->id)->wherePivotIn('role', ['owner', 'admin'])->exists();

        return $allowed;
    }

    public function show(Request $request, WhatsappBusinessAccount $waba, WhatsappConnectionHealthService $service): JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $workspaceId === (int) $waba->workspace_id, 403);

        return response()->json($service->summary($waba))->header('Cache-Control', 'no-store');
    }

    public function check(Request $request, WhatsappBusinessAccount $waba, WhatsappConnectionHealthService $service): JsonResponse
    {
        return $this->queue($request, $waba, $service, 'check');
    }

    public function repair(Request $request, WhatsappBusinessAccount $waba, WhatsappConnectionHealthService $service): JsonResponse
    {
        return $this->queue($request, $waba, $service, 'repair');
    }

    private function queue(Request $request, WhatsappBusinessAccount $waba, WhatsappConnectionHealthService $service, string $kind): JsonResponse
    {
        self::authorizeManager($request, $waba);
        abort_unless($service->enabled((int) $waba->workspace_id), 422, 'Connection monitoring has not been enabled for this workspace.');
        $key = 'wa-health:request:'.$waba->workspace_id.':'.$waba->id;
        // Return the running operation before applying the new-operation throttle.
        $summary = $service->summary($waba);
        if (! $summary['operation_id']) {
            abort_if(RateLimiter::tooManyAttempts($key, 3), 429, 'Please wait a minute before checking again.');
            RateLimiter::hit($key, 60);
        }
        $operation = $service->enqueue($waba, $kind);

        return response()->json(['success' => true, 'operation_id' => $operation->id, 'message' => 'Checking your WhatsApp connection.', 'health' => $service->summary($waba)], 202);
    }
}
