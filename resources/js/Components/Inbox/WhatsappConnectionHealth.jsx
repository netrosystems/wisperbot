import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { RefreshCw, ShieldCheck, AlertTriangle } from 'lucide-react';

const labels = {
    ready: 'Ready', needs_attention: 'Needs attention', reconnect_required: 'Reconnect required',
    checking: 'Checking', check_delayed: 'Check delayed',
};

export default function WhatsappConnectionHealth({ waba, onReconnect, onHealthChange }) {
    const { t } = useTranslation();
    const [health, setHealth] = useState(waba.connection_health ?? { enabled: false });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const refresh = useCallback(async (signal) => {
        try {
            const response = await fetch(route('client.whatsapp.setup.health', { waba: waba.id }), { headers: { Accept: 'application/json' }, signal });
            if (!response.ok) throw new Error('check');
            setHealth(await response.json());
            setError(null);
        } catch (e) {
            if (e.name !== 'AbortError') {
                setError(t('inbox.health_refresh_failed', { defaultValue: 'Connection status could not be refreshed. Try again.' }));
                setHealth(current => ({ ...current, state: 'check_delayed' }));
            }
        }
    }, [waba.id, t]);

    useEffect(() => {
        setHealth(waba.connection_health ?? { enabled: false });
    }, [waba.connection_health]);

    useEffect(() => { onHealthChange?.(health); }, [health, onHealthChange]);

    useEffect(() => {
        if (!health.enabled) return undefined;
        const controller = new AbortController();
        const update = () => { if (!document.hidden) refresh(controller.signal); };
        const timer = setInterval(update, health.operation_id ? 3000 : 60000);
        window.addEventListener('focus', update);
        return () => { controller.abort(); clearInterval(timer); window.removeEventListener('focus', update); };
    }, [health.enabled, health.operation_id, refresh]);

    const run = async (kind) => {
        setBusy(true);
        setError(null);
        try {
            const response = await fetch(route(`client.whatsapp.setup.${kind === 'repair' ? 'repair' : 'health.check'}`, { waba: waba.id }), {
                method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message ?? t('inbox.health_refresh_failed', { defaultValue: 'Connection status could not be refreshed. Try again.' }));
            setHealth(data.health);
        } catch (e) { setError(e.message); } finally { setBusy(false); }
    };

    if (!health.enabled) return null;
    const working = busy || health.state === 'checking';
    const ready = health.state === 'ready';
    const findings = Object.entries(health.components ?? {}).filter(([, value]) => value.state !== 'passed');
    const formatDate = value => value ? new Date(value).toLocaleString() : t('inbox.health_not_yet', { defaultValue: 'Not yet' });
    const actionLabel = health.action === 'reconnect' ? t('inbox.health_reconnect', { defaultValue: 'Reconnect WhatsApp' })
        : health.action === 'repair' ? t('inbox.health_repair', { defaultValue: 'Repair connection' })
            : t('inbox.health_check', { defaultValue: 'Check connection' });

    return <section aria-label={t('inbox.health_title', { defaultValue: 'Connection health' })} className="rounded-xl border border-neutral-200 dark:border-neutral-700 p-3 space-y-2 text-xs">
        <div className="flex items-center justify-between gap-2 flex-wrap">
            <span role="status" className={`inline-flex items-center gap-1.5 font-medium ${ready ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'}`}>
                {ready ? <ShieldCheck className="h-4 w-4" /> : working ? <RefreshCw className="h-4 w-4 animate-spin motion-reduce:animate-none" /> : <AlertTriangle className="h-4 w-4" />}
                {t(`inbox.health_${health.state}`, { defaultValue: labels[health.state] ?? 'Check delayed' })}
            </span>
            {waba.can_manage_health && <button type="button" disabled={working} onClick={() => health.action === 'reconnect' ? onReconnect?.() : run(health.action === 'repair' ? 'repair' : 'check')}
                className="rounded-lg px-2 py-1 text-brand-600 dark:text-brand-400 font-medium hover:bg-brand-50 dark:hover:bg-brand-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500 disabled:opacity-50">{actionLabel}</button>}
        </div>
        {health.action === 'contact_admin' && <p className="text-amber-700 dark:text-amber-300">{t('inbox.health_admin_help', { defaultValue: 'Your service administrator needs to review this connection.' })}</p>}
        {ready && <p className="text-neutral-600 dark:text-neutral-300">{health.delivery_verified
            ? t('inbox.health_delivery_verified', { defaultValue: 'A real incoming message has been processed.' })
            : t('inbox.health_send_test', { defaultValue: 'Connection checks passed. Send a message to your business number to verify delivery.' })}</p>}
        {!working && findings.slice(0, 1).map(([key, finding]) => <p key={key} className="text-neutral-600 dark:text-neutral-300">{finding.message}</p>)}
        <div className="text-neutral-500 dark:text-neutral-400 space-y-1">
            <p>{t('inbox.health_last_checked', { defaultValue: 'Last checked' })}: {formatDate(health.checked_at)}</p>
            <p>{t('inbox.health_last_message', { defaultValue: 'Last incoming message processed' })}: {formatDate(health.last_message_at)}</p>
        </div>
        {error && <p role="alert" className="text-red-600 dark:text-red-400">{error}</p>}
        <details className="text-neutral-500 dark:text-neutral-400">
            <summary className="cursor-pointer py-1 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">{t('inbox.health_details', { defaultValue: 'Connection details' })}</summary>
            <ul className="mt-2 space-y-2">{Object.entries(health.components ?? {}).map(([key, value]) => <li key={key}>
                {key.startsWith('phone:') && <span className="font-medium">{t('inbox.health_phone', { defaultValue: 'Phone' })} {key.slice(6)}: </span>}
                {value.message}
            </li>)}</ul>
            {waba.can_manage_health && health.action !== 'repair' && health.action !== 'reconnect' && <button type="button" disabled={working} onClick={() => run('repair')} className="mt-2 text-brand-600 dark:text-brand-400 disabled:opacity-50 focus-visible:outline focus-visible:outline-2">
                {t('inbox.health_repair', { defaultValue: 'Repair connection' })}
            </button>}
        </details>
    </section>;
}
