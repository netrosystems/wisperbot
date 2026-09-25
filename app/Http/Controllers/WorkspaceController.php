<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    /**
     * List workspaces the user can access (for switcher).
     */
    public function index(Request $request): Response
    {
        $workspaces = $request->user()->accessibleWorkspaces();

        return Inertia::render('client/Workspaces/Index', [
            'workspaces' => $workspaces->map(fn (Workspace $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'is_owner' => $w->owner_id === $request->user()->id,
            ]),
            // Only the owner sees, and can restore, a deleted workspace.
            'deletedWorkspaces' => Workspace::onlyTrashed()
                ->where('owner_id', $request->user()->id)
                ->where('deleted_at', '>', now()->subDays(Workspace::RESTORE_DAYS))
                ->orderByDesc('deleted_at')
                ->get()
                ->map(fn (Workspace $w) => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'deleted_at' => $w->deleted_at->toIso8601String(),
                    'purge_after' => $w->purgeAfter()->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    /**
     * Switch the current web workspace for this browser session.
     */
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'workspace_id' => ['required', 'integer', Rule::exists('workspaces', 'id')],
        ]);

        $workspace = Workspace::findOrFail($validated['workspace_id']);

        $this->authorize('view', $workspace);

        $request->session()->put('current_workspace_id', $workspace->id);

        return redirect()->intended(route('client.dashboard'));
    }

    /**
     * Create a new workspace (owner is current user).
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Workspace::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $workspace = Workspace::create([
            'name' => $validated['name'],
            'owner_id' => $request->user()->id,
            'client_id' => $request->user()->client_id,
            'default_locale' => $request->user()->locale ?? 'en',
            'currency_code' => $request->user()->display_currency,
        ]);

        $workspace->members()->attach($request->user()->id, ['role' => 'owner']);

        $request->session()->put('current_workspace_id', $workspace->id);

        return redirect()->route('client.dashboard')->with('success', __('Workspace created.'));
    }

    /**
     * Rename a workspace. Only its owner may do this (WorkspacePolicy::update).
     */
    public function update(Request $request, Workspace $workspace, AuditLogService $audit): RedirectResponse
    {
        $this->authorize('update', $workspace);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = trim($validated['name']);
        $previous = $workspace->name;

        if ($name !== $previous) {
            $workspace->update(['name' => $name]);
            $audit->log('workspace.renamed', $workspace, ['name' => $previous], ['name' => $name], $request);
        }

        return back()->with('success', __('Workspace renamed.'));
    }

    /**
     * Delete a workspace: it disappears for everyone and stops all activity at
     * once, the owner can restore it for 30 days, and `workspaces:purge-deleted`
     * then erases its data. The owner confirms by typing the workspace name.
     */
    public function destroy(Request $request, Workspace $workspace, AuditLogService $audit): RedirectResponse
    {
        $this->authorize('delete', $workspace);

        $validated = $request->validate([
            'confirm_name' => ['required', 'string', 'max:255'],
        ]);
        if (trim($validated['confirm_name']) !== $workspace->name) {
            throw ValidationException::withMessages([
                'confirm_name' => __('Type the workspace name exactly as shown to delete it.'),
            ]);
        }

        DB::transaction(function () use ($workspace, $request): void {
            $workspace->forceFill(['deleted_by_user_id' => $request->user()->id])->save();
            $workspace->delete();

            // Everyone whose main workspace this was moves to another workspace
            // they can open, or to none (they are asked to restore or create one).
            User::where('workspace_id', $workspace->id)->get()->each(function (User $member): void {
                $member->forceFill(['workspace_id' => $member->accessibleWorkspaces()->sortBy('id')->first()?->getKey()])->save();
            });
        });
        Workspace::forgetDeletedIds();

        if ((int) $request->session()->get('current_workspace_id') === $workspace->id) {
            $request->session()->forget('current_workspace_id');
        }

        $audit->log('workspace.deleted', $workspace, ['name' => $workspace->name], ['purge_after' => $workspace->purgeAfter()?->toIso8601String()], $request);

        return redirect()->route('client.workspaces.index')->with(
            'success',
            __('Workspace deleted. You can restore it until :date.', ['date' => $workspace->purgeAfter()?->toFormattedDateString()]),
        );
    }

    /**
     * Restore a deleted workspace within its 30-day window. Everything in it,
     * including channels, widgets and schedules, resumes as it was.
     */
    public function restore(Request $request, int $workspace, AuditLogService $audit): RedirectResponse
    {
        $workspace = Workspace::onlyTrashed()->findOrFail($workspace);
        $this->authorize('restore', $workspace);
        abort_if($workspace->purgeAfter()?->isPast(), 410, __('This workspace can no longer be restored.'));

        $workspace->restore();
        $workspace->forceFill(['deleted_by_user_id' => null])->save();
        Workspace::forgetDeletedIds();

        $user = $request->user();
        if (! $user->workspace_id) {
            $user->forceFill(['workspace_id' => $workspace->id])->save();
        }
        $request->session()->put('current_workspace_id', $workspace->id);

        $audit->log('workspace.restored', $workspace, null, ['name' => $workspace->name], $request);

        return redirect()->route('client.workspaces.index')->with('success', __('Workspace restored.'));
    }
}
