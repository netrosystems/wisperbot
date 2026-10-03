import { Link, router } from '@inertiajs/react';
import { Globe, Inbox, Mail } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { confirmDialog } from '@/Components/ConfirmDialog';

const CHANNEL_NAMES = { whatsapp: 'WhatsApp', messenger: 'Messenger', instagram: 'Instagram', telegram: 'Telegram', ebay: 'eBay', email: 'Email' };

function Place({ icon: Icon, title, detail, on, otherBot, unavailable, canManage, busy, onUse, onStop, settingsHref }) {
    const { t } = useTranslation();

    return (
        <li className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center">
            <div className="flex min-w-0 flex-1 items-start gap-3">
                <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${on ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400'}`}><Icon className="h-4 w-4" /></span>
                <div className="min-w-0">
                    <p className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                        {title}
                        <span className={`ml-2 rounded-full px-2 py-0.5 align-middle text-[11px] font-medium ${on ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400'}`}>
                            {on ? t('smart_bot.place_on') : otherBot ? t('smart_bot.place_other', { bot: otherBot }) : t('smart_bot.place_off')}
                        </span>
                    </p>
                    <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{detail}</p>
                </div>
            </div>
            <div className="flex shrink-0 items-center gap-3 pl-12 sm:pl-0">
                {settingsHref && <Link href={settingsHref} className="text-xs font-medium text-neutral-500 hover:text-brand-600">{t('smart_bot.place_settings')}</Link>}
                {unavailable ? null : !canManage ? (
                    <span className="text-xs text-neutral-400">{t('smart_bot.place_admin_only')}</span>
                ) : on ? (
                    <button type="button" disabled={busy} onClick={onStop} className="rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-semibold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">{t('smart_bot.place_stop')}</button>
                ) : (
                    <button type="button" disabled={busy} onClick={onUse} className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50">{otherBot ? t('smart_bot.place_use_instead') : t('smart_bot.place_use')}</button>
                )}
            </div>
        </li>
    );
}

/**
 * "Where it answers": switch this bot on for the website chat, the inbox
 * channels and email, with the same settings Widget Setup and Channel Setup
 * save. Hours and per-place details stay on those pages.
 */
export default function WhereItAnswers({ chatbot, placements, canManage = false }) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);
    const { widget, segments = {} } = placements ?? {};
    const options = { preserveScroll: true, onFinish: () => setBusy(false) };

    const replaces = async (otherBot) => !otherBot || confirmDialog({ message: t('smart_bot.place_replace_confirm', { bot: otherBot }), confirmLabel: t('smart_bot.place_use_instead') });

    const setWidget = async (on) => {
        if (on && !(await replaces(widget.other_bot))) return;
        setBusy(true);
        router.put(route('client.ai.chatbots.placements.widget', { chatbot: chatbot.uuid, chatWidget: widget.id }), { on }, options);
    };
    const setSegment = async (segment, on) => {
        const policy = segments[segment];
        if (on && !(await replaces(policy.other_bot))) return;
        setBusy(true);
        // Keep the place's schedule: "Scheduled" stays scheduled with this bot.
        const mode = !on ? 'off' : policy.mode === 'scheduled' ? 'scheduled' : 'always_on';
        router.patch(route('client.inbox.ai-answering.update', { segment }), {
            mode,
            chatbot_id: on ? chatbot.id : null,
            schedule: mode === 'scheduled' ? policy.schedule : null,
        }, options);
    };

    const channelDetail = (segment, emptyKey) => {
        const policy = segments[segment];
        if (!policy) return '';
        if (policy.runtime_state === 'runtime_disabled') return t('smart_bot.place_paused');
        if (!policy.connected?.length) return t(emptyKey);
        const names = policy.connected.map(channel => CHANNEL_NAMES[channel] ?? channel).join(', ');

        return policy.on && policy.mode === 'scheduled' ? `${names} · ${t('smart_bot.place_scheduled')}` : names;
    };

    return (
        <ul className="divide-y divide-neutral-100 dark:divide-neutral-800">
            <Place
                icon={Globe}
                title={t('smart_bot.place_website')}
                detail={widget ? t('smart_bot.place_website_detail') : t('smart_bot.place_website_missing')}
                on={Boolean(widget?.on)}
                otherBot={widget?.other_bot}
                unavailable={!widget}
                canManage={canManage}
                busy={busy}
                onUse={() => setWidget(true)}
                onStop={() => setWidget(false)}
                settingsHref={route('client.inbox.chat-widgets.settings')}
            />
            {segments.omni && (
                <Place
                    icon={Inbox}
                    title={t('smart_bot.place_channels')}
                    detail={channelDetail('omni', 'smart_bot.place_channels_missing')}
                    on={segments.omni.on}
                    otherBot={segments.omni.other_bot}
                    unavailable={segments.omni.runtime_state === 'runtime_disabled'}
                    canManage={canManage}
                    busy={busy}
                    onUse={() => setSegment('omni', true)}
                    onStop={() => setSegment('omni', false)}
                    settingsHref={route('client.inbox.setup')}
                />
            )}
            {segments.email && (
                <Place
                    icon={Mail}
                    title={t('smart_bot.place_email')}
                    detail={channelDetail('email', 'smart_bot.place_email_missing')}
                    on={segments.email.on}
                    otherBot={segments.email.other_bot}
                    unavailable={segments.email.runtime_state === 'runtime_disabled'}
                    canManage={canManage}
                    busy={busy}
                    onUse={() => setSegment('email', true)}
                    onStop={() => setSegment('email', false)}
                    settingsHref={route('client.inbox.email.index')}
                />
            )}
        </ul>
    );
}
