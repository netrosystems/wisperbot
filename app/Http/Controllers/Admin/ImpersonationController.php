<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function __construct(private AuditLogService $auditLog)
    {
    }

    public function stop(Request $request): RedirectResponse
    {
        $adminId = $request->session()->get('impersonator_admin_id');
        $clientId = $request->session()->get('impersonated_client_id');

        // Defensive: even if the flag was somehow lost (network glitch, partial
        // session write, stale cookie), the admin guard being unauthenticated
        // while the web guard is impersonating a client is itself the signal
        // that we should bail back to admin.login rather than orphan the
        // impersonated session row.
        $session = $request->session();
        $wasImpersonating = (bool) $session->get('impersonating');

        // Always wipe the impersonation keys first so a retry mid-flight can't
        // re-enter this branch with stale state.
        $session->forget(['impersonator_admin_id', 'impersonating', 'impersonated_client_id']);

        // Log out BOTH guards. The web guard is the impersonated client; the
        // admin guard is the super-admin who started the impersonation. Without
        // the admin logout, the admin session row remains authenticated and the
        // next "Start impersonation" click can either be blocked by
        // session-regeneration races or, worse, can re-bind the stale admin
        // guard to the new impersonation. We also invalidate + regenerate so
        // the session ID rotates and any cached session row in the database
        // (SESSION_DRIVER=database on live) is replaced cleanly.
        Auth::guard('web')->logout();
        Auth::guard('admin')->logout();
        $session->invalidate();
        $session->regenerateToken();

        if ($wasImpersonating) {
            $this->auditLog->logAdmin('impersonation.ended', null, null, [
                'actor_admin_id' => $adminId,
                'client_id' => $clientId,
            ]);

            return redirect()
                ->route('admin.clients.index')
                ->with('success', __('Returned to admin.'));
        }

        // No active impersonation — just bounce to login so the admin can
        // re-authenticate. This is safer than admin.dashboard which assumes a
        // valid admin guard.
        return redirect()
            ->route('admin.login')
            ->with('success', __('Returned to admin.'));
    }
}
