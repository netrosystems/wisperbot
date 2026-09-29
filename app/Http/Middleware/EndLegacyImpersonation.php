<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client impersonation was removed on 2026-09-29. A session that was already
 * impersonating a client when that release deployed would otherwise stay
 * signed in as the client, with no banner and no way back. Sign such a
 * session out of both guards so the admin has to log in again.
 *
 * Safe to delete once every pre-removal session has expired (SESSION_LIFETIME).
 */
class EndLegacyImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has('impersonating')) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
