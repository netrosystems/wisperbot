<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For client app routes: set current_client_id on the request from the authenticated user.
 * Controllers should use this to scope queries (e.g. only show data for the user's client).
 *
 * A user whose workspace was deleted is moved to another workspace they can
 * open, or, when none is left, sent to Workspaces to restore or create one.
 */
class EnsureClientScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->client_id) {
            $request->attributes->set('current_client_id', $user->client_id);
        }

        if ($user instanceof User && ! $request->routeIs('client.workspaces.*') && $this->lostWorkspace($user)) {
            $fallback = $user->accessibleWorkspaces()->sortBy('id')->first();
            if ($fallback) {
                $request->session()->put('current_workspace_id', $fallback->getKey());

                return $request->expectsJson()
                    ? response()->json(['message' => __('Your workspace changed. Reload the page.')], 409)
                    : redirect()->to($request->fullUrl());
            }

            return $request->expectsJson()
                ? response()->json(['message' => __('Restore or create a workspace to continue.')], 409)
                : redirect()->route('client.workspaces.index')
                    ->with('error', __('Your workspace was deleted. Restore it or create a new workspace to continue.'));
        }

        return $next($request);
    }

    /**
     * The user's workspace is deleted, or they have none left because they
     * deleted it. Users who never had a workspace (for example during
     * onboarding) are not affected.
     */
    private function lostWorkspace(User $user): bool
    {
        if ($user->workspace_id) {
            return in_array((int) $user->workspace_id, Workspace::deletedIds(), true);
        }

        return Workspace::deletedIds() !== []
            && Workspace::onlyTrashed()->where('owner_id', $user->id)->exists();
    }
}
