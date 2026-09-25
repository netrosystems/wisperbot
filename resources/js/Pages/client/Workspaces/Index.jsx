import ClientLayout from '@/Layouts/ClientLayout';
import { Button, Card, Input, Badge, Modal } from '@/Components/ui';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

function WorkspaceAvatar({ name }) {
    const initials = name
        .split(' ')
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');

    return (
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-brand-700 font-semibold text-sm dark:bg-brand-900/40 dark:text-brand-300">
            {initials}
        </div>
    );
}

function formatDate(iso) {
    try {
        return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    } catch {
        return iso;
    }
}

/** The owner types the workspace name before Delete is enabled. */
function DeleteWorkspaceModal({ workspace, onClose }) {
    const { t } = useTranslation();
    const [typed, setTyped] = useState('');
    const [error, setError] = useState(null);
    const [deleting, setDeleting] = useState(false);
    const matches = workspace && typed.trim() === workspace.name;

    const close = () => {
        if (deleting) return;
        setTyped('');
        setError(null);
        onClose();
    };

    const submit = (e) => {
        e.preventDefault();
        if (!matches) return;
        setDeleting(true);
        router.delete(route('client.workspaces.destroy', workspace.id), {
            data: { confirm_name: typed.trim() },
            preserveScroll: true,
            onSuccess: () => {
                setTyped('');
                onClose();
            },
            onError: (errors) => setError(errors.confirm_name ?? t('workspaces.delete_failed')),
            onFinish: () => setDeleting(false),
        });
    };

    return (
        <Modal show={Boolean(workspace)} onClose={close} maxWidth="md">
            {workspace && (
                <form onSubmit={submit}>
                    <Modal.Header title={t('workspaces.delete_title', { name: workspace.name })} onClose={close} />
                    <Modal.Body className="space-y-3 text-sm text-neutral-600 dark:text-neutral-300">
                        <p>{t('workspaces.delete_intro')}</p>
                        <ul className="list-disc space-y-1 pl-5">
                            <li>{t('workspaces.delete_effect_hidden')}</li>
                            <li>{t('workspaces.delete_effect_stops')}</li>
                            <li>{t('workspaces.delete_effect_restore', { days: 30 })}</li>
                        </ul>
                        <Input
                            label={t('workspaces.delete_confirm_label', { name: workspace.name })}
                            value={typed}
                            onChange={(e) => { setTyped(e.target.value); setError(null); }}
                            placeholder={workspace.name}
                            autoComplete="off"
                            autoFocus
                            error={error}
                        />
                    </Modal.Body>
                    <Modal.Footer>
                        <Button type="button" variant="outline" size="sm" onClick={close} disabled={deleting}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" variant="danger" size="sm" disabled={!matches || deleting}>
                            {deleting ? t('workspaces.deleting') : t('workspaces.delete_workspace')}
                        </Button>
                    </Modal.Footer>
                </form>
            )}
        </Modal>
    );
}

export default function WorkspacesIndex({ workspaces = [], deletedWorkspaces = [] }) {
    const { t } = useTranslation();
    const [name, setName] = useState('');
    const [creating, setCreating] = useState(false);
    const [switching, setSwitching] = useState(null);
    const [editingId, setEditingId] = useState(null);
    const [editName, setEditName] = useState('');
    const [renameError, setRenameError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(null);
    const [restoring, setRestoring] = useState(null);
    const currentWorkspace = usePage().props.currentWorkspace;

    const handleRestore = (workspace) => {
        setRestoring(workspace.id);
        router.post(route('client.workspaces.restore', workspace.id), {}, {
            preserveScroll: true,
            onFinish: () => setRestoring(null),
        });
    };

    const startRename = (workspace) => {
        setEditingId(workspace.id);
        setEditName(workspace.name);
        setRenameError(null);
    };

    const cancelRename = () => {
        setEditingId(null);
        setRenameError(null);
    };

    const handleRename = (e, workspace) => {
        e.preventDefault();
        const next = editName.trim();
        if (!next) return;
        if (next === workspace.name) {
            cancelRename();
            return;
        }
        setSaving(true);
        router.put(route('client.workspaces.update', workspace.id), { name: next }, {
            preserveScroll: true,
            onSuccess: () => setEditingId(null),
            onError: (errors) => setRenameError(errors.name ?? t('workspaces.rename_failed')),
            onFinish: () => setSaving(false),
        });
    };

    const handleSwitch = (workspaceId) => {
        setSwitching(workspaceId);
        router.post(route('client.workspaces.switch'), { workspace_id: workspaceId }, {
            preserveScroll: true,
            onFinish: () => setSwitching(null),
        });
    };

    const handleCreate = (e) => {
        e.preventDefault();
        if (!name.trim()) return;
        setCreating(true);
        router.post(route('client.workspaces.store'), { name: name.trim() }, {
            preserveScroll: true,
            onFinish: () => {
                setCreating(false);
                setName('');
            },
        });
    };

    return (
        <ClientLayout title={t('workspaces.title')}>
            <Head title={t('workspaces.title')} />

            <div className="max-w-2xl space-y-8">
                {/* Header */}
                <div>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('workspaces.title')}</h2>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        {t('workspaces.subtitle')}
                    </p>
                </div>

                {/* Workspace list */}
                <div className="space-y-3">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">
                        {t('workspaces.your_workspaces', { count: workspaces.length })}
                    </h3>

                    {workspaces.length === 0 ? (
                        <Card>
                            <Card.Body className="py-10 text-center">
                                <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-neutral-100 dark:bg-neutral-800">
                                    <svg className="h-6 w-6 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 7.125C2.25 6.504 2.754 6 3.375 6h6c.621 0 1.125.504 1.125 1.125v3.75c0 .621-.504 1.125-1.125 1.125h-6a1.125 1.125 0 01-1.125-1.125v-3.75zM14.25 8.625c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v8.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 01-1.125-1.125v-8.25zM3.75 16.125c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v2.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 01-1.125-1.125v-2.25z" />
                                    </svg>
                                </div>
                                <p className="text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('workspaces.none_yet')}</p>
                                <p className="mt-1 text-sm text-neutral-400">{t('workspaces.create_first')}</p>
                            </Card.Body>
                        </Card>
                    ) : (
                        <ul className="space-y-2">
                            {workspaces.map((w) => {
                                const isCurrent = currentWorkspace?.id === w.id;
                                const isSwitching = switching === w.id;
                                if (editingId === w.id) {
                                    return (
                                        <li key={w.id}>
                                            <form
                                                onSubmit={(e) => handleRename(e, w)}
                                                className="flex items-end gap-3 rounded-xl border border-brand-300 bg-white px-4 py-3 dark:border-brand-700 dark:bg-neutral-900"
                                            >
                                                <WorkspaceAvatar name={editName.trim() || w.name} />
                                                <Input
                                                    label={t('workspaces.name_label')}
                                                    value={editName}
                                                    onChange={(e) => setEditName(e.target.value)}
                                                    onKeyDown={(e) => e.key === 'Escape' && cancelRename()}
                                                    maxLength={255}
                                                    autoFocus
                                                    error={renameError}
                                                    className="flex-1"
                                                />
                                                <Button type="submit" variant="primary" size="sm" disabled={saving || !editName.trim()} className="shrink-0">
                                                    {saving ? t('workspaces.saving') : t('workspaces.save')}
                                                </Button>
                                                <Button type="button" variant="outline" size="sm" onClick={cancelRename} disabled={saving} className="shrink-0">
                                                    {t('common.cancel')}
                                                </Button>
                                            </form>
                                        </li>
                                    );
                                }
                                return (
                                    <li key={w.id}>
                                        <div className={[
                                            'flex items-center justify-between rounded-xl border px-4 py-3 transition-colors duration-150',
                                            isCurrent
                                                ? 'border-brand-300 bg-brand-50 dark:border-brand-700 dark:bg-brand-900/20'
                                                : 'border-neutral-200 bg-white hover:border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-neutral-600 dark:hover:bg-neutral-800',
                                        ].join(' ')}>
                                            <div className="flex items-center gap-3">
                                                <WorkspaceAvatar name={w.name} />
                                                <div>
                                                    <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">{w.name}</p>
                                                    <p className="text-xs text-neutral-400 dark:text-neutral-500">
                                                        {w.is_owner ? t('workspaces.owner') : t('workspaces.member')}
                                                    </p>
                                                </div>
                                            </div>

                                            <div className="flex items-center gap-2">
                                                {isCurrent && (
                                                    <Badge variant="brand" size="sm">{t('common.active')}</Badge>
                                                )}
                                                {w.is_owner && (
                                                    <>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => startRename(w)}
                                                            aria-label={t('workspaces.rename_named', { name: w.name })}
                                                        >
                                                            {t('workspaces.rename')}
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => setDeleting(w)}
                                                            aria-label={t('workspaces.delete_named', { name: w.name })}
                                                            className="text-coral-600 hover:text-coral-700 dark:text-coral-400"
                                                        >
                                                            {t('workspaces.delete')}
                                                        </Button>
                                                    </>
                                                )}
                                                <Button
                                                    variant={isCurrent ? 'outline' : 'primary'}
                                                    size="sm"
                                                    onClick={() => !isCurrent && handleSwitch(w.id)}
                                                    disabled={isCurrent || isSwitching}
                                                >
                                                    {isSwitching ? t('workspaces.switching') : isCurrent ? t('workspaces.current') : t('workspaces.switch')}
                                                </Button>
                                            </div>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                {deletedWorkspaces.length > 0 && (
                    <div className="space-y-3">
                        <h3 className="text-xs font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">
                            {t('workspaces.recently_deleted', { count: deletedWorkspaces.length })}
                        </h3>
                        <ul className="space-y-2">
                            {deletedWorkspaces.map((w) => (
                                <li key={w.id} className="flex items-center justify-between gap-3 rounded-xl border border-dashed border-neutral-300 bg-neutral-50 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-900/60">
                                    <div className="flex min-w-0 items-center gap-3 opacity-70">
                                        <WorkspaceAvatar name={w.name} />
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">{w.name}</p>
                                            <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                                {t('workspaces.erased_on', { date: formatDate(w.purge_after) })}
                                            </p>
                                        </div>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => handleRestore(w)}
                                        disabled={restoring === w.id}
                                        aria-label={t('workspaces.restore_named', { name: w.name })}
                                        className="shrink-0"
                                    >
                                        {restoring === w.id ? t('workspaces.restoring') : t('workspaces.restore')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* Create workspace */}
                {(
                <Card className="border-dashed border-neutral-300 dark:border-neutral-700">
                    <Card.Body className="space-y-4">
                        <div className="flex items-start gap-3">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 dark:bg-brand-900/30">
                                <svg className="h-4.5 w-4.5 text-brand-600 dark:text-brand-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                            </div>
                            <div>
                                <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{t('workspaces.create_new')}</h3>
                                <p className="mt-0.5 text-xs text-neutral-400 dark:text-neutral-500">{t('workspaces.create_new_desc')}</p>
                            </div>
                        </div>

                        <form onSubmit={handleCreate} className="flex items-end gap-3">
                            <Input
                                label={t('workspaces.name_label')}
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder={t('workspaces.name_placeholder')}
                                className="flex-1"
                            />
                            <Button
                                type="submit"
                                variant="primary"
                                disabled={creating || !name.trim()}
                                className="shrink-0"
                            >
                                {creating ? (
                                    <span className="flex items-center gap-1.5">
                                        <svg className="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                                        </svg>
                                        {t('workspaces.creating')}
                                    </span>
                                ) : (
                                    <span className="flex items-center gap-1.5">
                                        <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        {t('workspaces.create_workspace')}
                                    </span>
                                )}
                            </Button>
                        </form>
                    </Card.Body>
                </Card>
                )}
            </div>

            <DeleteWorkspaceModal workspace={deleting} onClose={() => setDeleting(null)} />
        </ClientLayout>
    );
}
