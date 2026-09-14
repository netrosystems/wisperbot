<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveWebWorkspace
{
    /**
     * Apply the browser-session workspace to the in-memory web user only.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $workspaceId = $request->session()->get('current_workspace_id');
        if (! $workspaceId) {
            return $next($request);
        }

        $workspace = Workspace::find($workspaceId);
        if (! $workspace || ! $workspace->isAccessibleBy($user)) {
            $request->session()->forget('current_workspace_id');

            return $next($request);
        }

        $activeUser = clone $user;
        $activeUser->forceFill(['workspace_id' => (int) $workspace->id]);
        $activeUser->syncOriginalAttribute('workspace_id');

        Auth::setUser($activeUser);
        $request->setUserResolver(fn () => $activeUser);

        return $next($request);
    }
}
