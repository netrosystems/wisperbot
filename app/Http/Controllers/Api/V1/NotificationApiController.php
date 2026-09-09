<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WorkspaceNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationApiController extends Controller
{
    public function __construct(private readonly WorkspaceNotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $workspaceId = $this->notifications->currentWorkspaceId($request);
        $notifications = $this->notifications->query($request->user(), $workspaceId)
            ->latest()->paginate(25)
            ->through(fn ($notification) => $this->notifications->serialize($notification));

        return response()->json($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->notifications->query($request->user(), $this->notifications->currentWorkspaceId($request))
            ->whereNull('read_at')->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, string $notificationId): JsonResponse
    {
        $notification = $this->notifications->query($request->user(), $this->notifications->currentWorkspaceId($request))
            ->findOrFail($notificationId);
        $notification->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $this->notifications->query($request->user(), $this->notifications->currentWorkspaceId($request))
            ->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    public function destroy(Request $request, string $notificationId): JsonResponse
    {
        $this->notifications->query($request->user(), $this->notifications->currentWorkspaceId($request))
            ->findOrFail($notificationId)->delete();

        return response()->json(['ok' => true]);
    }
}
