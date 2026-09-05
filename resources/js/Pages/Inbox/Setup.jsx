import { Head, router, usePage, Link } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import WhatsappConnectionHealth from '@/Components/Inbox/WhatsappConnectionHealth';
import {
    Check, Copy, AlertTriangle,
    Phone, Inbox, Webhook, FileText,
    Trash2, RefreshCw, Bot, ChevronDown, ExternalLink,
    Edit3, Clock, ShieldCheck, ShieldAlert, Wifi, WifiOff, X,
} from 'lucide-react';
import { useState, useCallback, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import {
    embeddedSignupLoginOptions,
    isWhatsappEmbeddedSignupFinish,
    WHATSAPP_ONBOARDING_CLOUD_API,
    WHATSAPP_ONBOARDING_COEXISTENCE,
} from '@/Utils/metaEmbeddedSignup';

/* brand logos (accurate official paths) */

function WhatsAppLogo({ className = 'h-5 w-5' }) {
    return (
        <svg className={className} viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <rect width="24" height="24" rx="5.5" fill="#25D366"/>
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" fill="white"/>
        </svg>
    );
}

function InstagramLogo({ className = 'h-5 w-5' }) {
    return (
        <svg className={className} viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <radialGradient id="ig1" cx="30%" cy="107%" r="130%">
                    <stop offset="0%" stopColor="#fdf497"/>
                    <stop offset="5%" stopColor="#fdf497"/>
                    <stop offset="45%" stopColor="#fd5949"/>
                    <stop offset="60%" stopColor="#d6249f"/>
                    <stop offset="90%" stopColor="#285AEB"/>
                </radialGradient>
            </defs>
            <rect width="24" height="24" rx="6" fill="url(#ig1)"/>
            <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z" fill="white"/>
        </svg>
    );
}

function MessengerLogo({ className = 'h-5 w-5' }) {
    return (
        <svg className={className} viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <linearGradient id="msg1" x1="0%" y1="100%" x2="100%" y2="0%">
                    <stop offset="0%" stopColor="#0099FF"/>
                    <stop offset="100%" stopColor="#A033FF"/>
                </linearGradient>
            </defs>
            <rect width="24" height="24" rx="6" fill="url(#msg1)"/>
            <path d="M12 2C6.477 2 2 6.145 2 11.259c0 2.928 1.453 5.544 3.73 7.258V22l3.405-1.869c.91.252 1.872.388 2.865.388 5.523 0 10-4.145 10-9.259C22 6.145 17.523 2 12 2zm.992 12.479l-2.549-2.72-4.976 2.72 5.474-5.809 2.612 2.721 4.911-2.721-5.472 5.809z" fill="white"/>
        </svg>
    );
}

function EbayLogo({ className = 'h-5 w-5' }) {
    return (
        <svg className={className} viewBox="0 0 48 20" role="img" aria-label="eBay">
            <text x="1" y="16" fontFamily="Arial, sans-serif" fontSize="18" fontWeight="700">
                <tspan fill="#e53238">e</tspan>
                <tspan fill="#0064d2">B</tspan>
                <tspan fill="#f5af02">a</tspan>
                <tspan fill="#86b817">y</tspan>
            </text>
        </svg>
    );
}

function AmazonLogo({ className = 'h-5 w-10' }) {
    return (
        <svg className={className} viewBox="0 0 48 20" role="img" aria-label="Amazon">
            <text x="2" y="13" fontFamily="Arial, sans-serif" fontSize="12" fontWeight="700" fill="currentColor">amazon</text>
            <path d="M12 16c7 3 17 3 24-1" fill="none" stroke="#ff9900" strokeWidth="1.8" strokeLinecap="round"/>
            <path d="m33.5 13.8 3 1.2-2.4 2" fill="none" stroke="#ff9900" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round"/>
        </svg>
    );
}

function TelegramLogo({ className = 'h-5 w-5' }) {
    return (
        <svg className={className} viewBox="0 0 24 24" role="img" aria-label="Telegram">
            <circle cx="12" cy="12" r="12" fill="#229ED9" />
            <path fill="white" d="m18.9 5.4-2.5 12c-.2.85-.7 1.05-1.42.65l-3.8-2.8-1.83 1.77c-.2.2-.37.37-.76.37l.27-3.87 7.05-6.37c.31-.27-.07-.43-.47-.16l-8.71 5.49-3.75-1.17c-.82-.26-.83-.82.17-1.21l14.66-5.65c.68-.25 1.28.16 1.09.95Z" />
        </svg>
    );
}

/* â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ shared helpers â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ */

function CopyButton({ text }) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);
    const copy = () => navigator.clipboard.writeText(text).then(() => {
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    });
    return (
        <button type="button" onClick={copy} title={t('inbox.copy')}
            className="shrink-0 rounded-md p-1.5 text-neutral-400 hover:text-brand-600 hover:bg-brand-50 dark:hover:bg-brand-950/30 dark:hover:text-brand-400 transition-all">
            {copied ? <Check className="h-3.5 w-3.5 text-green-500" /> : <Copy className="h-3.5 w-3.5" />}
        </button>
    );
}

function StatusBadge({ status }) {
    const { t } = useTranslation();
    const map = {
        active:   'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:ring-emerald-800',
        inactive: 'bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-neutral-800 dark:text-neutral-400 dark:ring-neutral-700',
        error:    'bg-red-50 text-red-700 ring-1 ring-red-200 dark:bg-red-900/30 dark:text-red-300 dark:ring-red-800',
    };
    const dot = {
        active: 'bg-emerald-500',
        inactive: 'bg-neutral-400',
        error: 'bg-red-500',
    };
    const labelMap = {
        active:   t('common.active'),
        inactive: t('common.inactive'),
        error:    t('inbox.status_error'),
    };
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${map[status] ?? map.inactive}`}>
            <span className={`h-1.5 w-1.5 rounded-full ${dot[status] ?? dot.inactive}`} />
            {labelMap[status] ?? status}
        </span>
    );
}

function ChannelCard({ icon: Icon, iconBg, title, count, children, className = '' }) {
    const { t } = useTranslation();
    return (
        <div className={`rounded-2xl border bg-white dark:bg-neutral-900 shadow-sm overflow-hidden border-neutral-200 dark:border-neutral-700 ${className}`}>
            <div className="flex items-center gap-3 px-5 py-4 border-b border-neutral-100 dark:border-neutral-800">
                <div className={`rounded-xl p-2 ${iconBg}`}>
                    <Icon className="h-4 w-4" />
                </div>
                <div>
                    <h3 className="font-semibold text-neutral-900 dark:text-neutral-100 text-sm">{title}</h3>
                </div>
                {count != null && (
                    <span className={`ml-auto text-xs font-medium px-2 py-0.5 rounded-full ${count > 0 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400'}`}>
                        {t('inbox.count_connected', { count })}
                    </span>
                )}
            </div>
            <div className="p-5">
                {children ?? (
                    <div className="text-center py-8">
                        <div className="mx-auto mb-3 rounded-2xl w-12 h-12 flex items-center justify-center bg-white dark:bg-neutral-800 shadow-sm border border-neutral-100 dark:border-neutral-700">
                            <Icon className="h-6 w-6" />
                        </div>
                        <p className="text-sm text-neutral-400 dark:text-neutral-500">{emptyText}</p>
                    </div>
                )}
            </div>
        </div>
    );
}

/* â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ chatbot selector â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ */

function ChatbotSelector({ channelAccountId, currentChatbotId, chatbots }) {
    const { t } = useTranslation();
    const [saving, setSaving] = useState(false);
    const [value, setValue] = useState(currentChatbotId ? String(currentChatbotId) : '');

    const handleChange = (e) => {
        const next = e.target.value;
        setValue(next);
        setSaving(true);
        router.patch(
            route('client.inbox.setup.assign-chatbot', { channelAccount: channelAccountId }),
            { chatbot_id: next === '' ? null : Number(next) },
            { preserveScroll: true, onFinish: () => setSaving(false) },
        );
    };

    return (
        <div className="flex items-center gap-2 mt-2.5 pt-2.5 border-t border-neutral-100 dark:border-neutral-700/50">
            <Bot className="h-3.5 w-3.5 text-brand-500 shrink-0" />
            <div className="relative flex-1">
                <select
                    value={value}
                    onChange={handleChange}
                    disabled={saving}
                    className="w-full appearance-none rounded-lg border border-neutral-200 dark:border-neutral-600 bg-white dark:bg-neutral-800 pl-2.5 pr-7 py-1.5 text-xs disabled:opacity-60 focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-400 transition"
                >
                    <option value="">{t('inbox.no_chatbot')}</option>
                    {chatbots.map(bot => (
                        <option key={bot.id} value={String(bot.id)}>{bot.name}</option>
                    ))}
                </select>
                <ChevronDown className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 h-3 w-3 text-neutral-400" />
            </div>
            {saving && <span className="text-xs text-neutral-400 shrink-0">{t('inbox.saving')}</span>}
        </div>
    );
}

/* â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ WhatsApp section â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ */

/* ─────────────────── Phone number status helpers ─────────────────── */

const NAME_STATUS = {
    APPROVED:                  { labelKey: 'inbox.name_status_approved',      color: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:ring-emerald-800', icon: ShieldCheck },
    AVAILABLE_WITHOUT_MOCK_UP: { labelKey: 'inbox.name_status_approved',      color: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:ring-emerald-800', icon: ShieldCheck },
    PENDING_REVIEW:            { labelKey: 'inbox.name_status_under_review',  color: 'bg-amber-50 text-amber-700 ring-1 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:ring-amber-800',           icon: Clock       },
    DECLINED:                  { labelKey: 'inbox.name_status_declined',      color: 'bg-red-50 text-red-700 ring-1 ring-red-200 dark:bg-red-900/30 dark:text-red-300 dark:ring-red-800',                       icon: ShieldAlert  },
    EXPIRED:                   { labelKey: 'inbox.name_status_expired',       color: 'bg-red-50 text-red-700 ring-1 ring-red-200 dark:bg-red-900/30 dark:text-red-300 dark:ring-red-800',                       icon: ShieldAlert  },
};

const QUALITY_COLOR = {
    GREEN:  'text-emerald-600 dark:text-emerald-400',
    YELLOW: 'text-amber-500 dark:text-amber-400',
    RED:    'text-red-500 dark:text-red-400',
};

function NameStatusBadge({ status }) {
    const { t } = useTranslation();
    const info = NAME_STATUS[status];
    if (!info) return null;
    const Icon = info.icon;
    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ${info.color}`}>
            {Icon && <Icon className="h-2.5 w-2.5 shrink-0" />}
            {t(info.labelKey)}
        </span>
    );
}

function PhoneStatusCard({ num, wabaId, onRefreshed }) {
    const { t } = useTranslation();
    const phoneId = num.phone_number_id ?? num.id;
    const [refreshing, setRefreshing]     = useState(false);
    const [showNameForm, setShowNameForm] = useState(false);
    const [newName, setNewName]           = useState('');
    const [submitting, setSubmitting]     = useState(false);
    const [nameMsg, setNameMsg]           = useState(null);
    const [liveData, setLiveData]         = useState(null);

    const data          = liveData ?? num;
    const nameStatus    = data.name_status ?? null;
    const requestedName = data.requested_verified_name ?? null;
    const verifiedName  = data.verified_name ?? null;
    const qualityRating = data.quality_rating ?? null;
    const accountMode   = data.account_mode ?? null;
    const codeStatus    = data.code_verification_status ?? null;

    // Auto-fetch from Meta on first render if name_status is unknown
    useEffect(() => {
        if (!nameStatus && !refreshing) {
            refresh();
        }
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [phoneId]);

    const overallStatus = (() => {
        if (nameStatus === 'PENDING_REVIEW') return { label: t('inbox.overall_pending_review'), color: 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300', dot: 'bg-amber-400' };
        if (nameStatus === 'DECLINED')       return { label: t('inbox.overall_name_declined'),  color: 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300',         dot: 'bg-red-500'  };
        if (qualityRating === 'RED')         return { label: t('inbox.overall_quality_issue'),  color: 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300',         dot: 'bg-red-500'  };
        if (codeStatus === 'NOT_VERIFIED')   return { label: t('inbox.overall_not_verified'),   color: 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400', dot: 'bg-neutral-400' };
        if (nameStatus === 'APPROVED' || nameStatus === 'AVAILABLE_WITHOUT_MOCK_UP')
            return { label: t('common.active'), color: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300', dot: 'bg-emerald-500' };
        return { label: t('inbox.overall_unknown'), color: 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400', dot: 'bg-neutral-400' };
    })();

    const refresh = async () => {
        setRefreshing(true);
        try {
            const res = await fetch(
                route('client.whatsapp.setup.refresh-phone-status', { waba: wabaId, phoneNumberId: phoneId }),
                { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' } }
            );
            const json = await res.json();
            if (res.ok && json.data) { setLiveData({ ...num, ...json.data }); onRefreshed?.(); }
        } finally { setRefreshing(false); }
    };

    const submitNameChange = async () => {
        if (!newName.trim()) return;
        setSubmitting(true);
        setNameMsg(null);
        try {
            const res = await fetch(
                route('client.whatsapp.setup.change-display-name', { waba: wabaId, phoneNumberId: phoneId }),
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
                    body: JSON.stringify({ name: newName.trim() }),
                }
            );
            let json = {};
            try { json = await res.json(); } catch { /* non-JSON response */ }
            if (res.ok) {
                setNameMsg({ type: 'success', text: json.message ?? t('inbox.submitted_successfully') });
                setLiveData(prev => ({ ...(prev ?? num), name_status: 'PENDING_REVIEW', requested_verified_name: newName.trim() }));
                setShowNameForm(false);
                setNewName('');
            } else {
                setNameMsg({ type: 'error', text: json.error ?? t('inbox.request_failed_http', { status: res.status }) });
            }
        } catch (err) {
            setNameMsg({ type: 'error', text: t('inbox.network_error_detail', { message: err?.message ?? t('inbox.unknown') }) });
        } finally { setSubmitting(false); }
    };

    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/60 overflow-hidden">
            <div className="flex items-start gap-3 px-3.5 py-3">
                <div className="mt-0.5 rounded-lg bg-green-100 dark:bg-green-900/30 p-1.5 shrink-0">
                    <Phone className="h-3.5 w-3.5 text-green-600 dark:text-green-400" />
                </div>
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-semibold text-sm text-neutral-900 dark:text-neutral-100">
                            {data.display_phone ?? data.display_phone_number ?? '—'}
                        </span>
                        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ${overallStatus.color}`}>
                            <span className={`h-1.5 w-1.5 rounded-full ${overallStatus.dot}`} />
                            {overallStatus.label}
                        </span>
                        {qualityRating && (
                            <span className={`text-xs font-medium ${QUALITY_COLOR[qualityRating] ?? 'text-neutral-400'}`}>{qualityRating}</span>
                        )}
                        {accountMode && (
                            <span className="inline-flex items-center gap-1 text-[10px] text-neutral-400">
                                {accountMode === 'LIVE' ? <Wifi className="h-2.5 w-2.5" /> : <WifiOff className="h-2.5 w-2.5" />}
                                {accountMode}
                            </span>
                        )}
                    </div>
                    <div className="flex items-center gap-2 mt-1 flex-wrap">
                        <span className="text-xs text-neutral-500 dark:text-neutral-400">{verifiedName ?? '—'}</span>
                        {nameStatus && <NameStatusBadge status={nameStatus} />}
                    </div>
                    {nameStatus === 'PENDING_REVIEW' && requestedName && requestedName !== verifiedName && (
                        <div className="mt-1 flex items-center gap-1 text-[10px] text-amber-600 dark:text-amber-400">
                            <Clock className="h-3 w-3 shrink-0" />
                            <span>{t('inbox.requested_label')} <strong>{requestedName}</strong> {t('inbox.under_review_suffix')}</span>
                        </div>
                    )}
                    {nameStatus === 'DECLINED' && (
                        <div className="mt-1 flex items-center gap-1 text-[10px] text-red-600 dark:text-red-400">
                            <ShieldAlert className="h-3 w-3 shrink-0" />
                            <span>{t('inbox.name_declined_submit_new')}</span>
                        </div>
                    )}
                    <div className="font-mono text-[10px] text-neutral-400 mt-0.5">{t('inbox.id_label')} {phoneId}</div>
                </div>
                <div className="flex items-center gap-1 shrink-0">
                    <button type="button" onClick={refresh} disabled={refreshing} title={t('inbox.refresh_status_meta')}
                        className="p-1.5 rounded-lg text-neutral-400 hover:text-brand-600 hover:bg-brand-50 dark:hover:bg-brand-950/30 disabled:opacity-50 transition">
                        <RefreshCw className={`h-3.5 w-3.5 ${refreshing ? 'animate-spin' : ''}`} />
                    </button>
                    <button type="button" onClick={() => { setShowNameForm(v => !v); setNameMsg(null); }} title={t('inbox.change_display_name')}
                        className={`p-1.5 rounded-lg transition ${showNameForm ? 'bg-brand-100 dark:bg-brand-900/30 text-brand-600' : 'text-neutral-400 hover:text-brand-600 hover:bg-brand-50 dark:hover:bg-brand-950/30'}`}>
                        <Edit3 className="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>

            {nameMsg && (
                <div className={`flex items-start gap-2 px-3.5 py-2 text-xs border-t ${nameMsg.type === 'success' ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 border-emerald-100 dark:border-emerald-900/30' : 'bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 border-red-100 dark:border-red-900/30'}`}>
                    {nameMsg.type === 'success' ? <Check className="h-3.5 w-3.5 mt-0.5 shrink-0" /> : <AlertTriangle className="h-3.5 w-3.5 mt-0.5 shrink-0" />}
                    <span className="flex-1">{nameMsg.text}</span>
                    <button onClick={() => setNameMsg(null)}><X className="h-3.5 w-3.5 opacity-50 hover:opacity-100" /></button>
                </div>
            )}

            {showNameForm && (
                <div className="px-3.5 pb-3.5 pt-2.5 border-t border-neutral-200 dark:border-neutral-700 space-y-2">
                    <p className="text-[10px] font-semibold text-neutral-500 uppercase tracking-wider flex items-center gap-1">
                        <Edit3 className="h-3 w-3" /> {t('inbox.change_display_name')}
                    </p>
                    <p className="text-[10px] text-neutral-400 leading-relaxed">
                        {t('inbox.change_display_name_help')}
                    </p>
                    <div className="flex gap-2">
                        <input type="text" value={newName} onChange={e => setNewName(e.target.value)}
                            placeholder={verifiedName ?? t('inbox.business_name_placeholder')} maxLength={100}
                            className="flex-1 rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-2.5 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-400" />
                        <button type="button" onClick={submitNameChange} disabled={submitting || !newName.trim()}
                            className="rounded-lg bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white text-xs font-medium px-3 py-1.5 transition whitespace-nowrap">
                            {submitting ? t('inbox.submitting') : t('inbox.submit')}
                        </button>
                        <button type="button" onClick={() => { setShowNameForm(false); setNewName(''); }}
                            className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-2 py-1.5 text-xs text-neutral-500 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                            {t('common.cancel')}
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}

function CodeField({ label, value, icon: Icon }) {
    return (
        <div className="rounded-xl bg-neutral-50 dark:bg-neutral-800/60 border border-neutral-200 dark:border-neutral-700 px-3.5 py-2.5">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-neutral-400 mb-1.5 flex items-center gap-1.5">
                {Icon && <Icon className="h-3 w-3" />} {label}
            </p>
            <div className="flex items-center gap-2">
                <code className="flex-1 min-w-0 text-xs font-mono text-neutral-600 dark:text-neutral-300 break-all leading-relaxed">{value}</code>
                <CopyButton text={value} />
            </div>
        </div>
    );
}

function WabaCard({ waba, webhookGlobalUrl, channelAccounts, chatbots, onReconnect }) {
    const { t } = useTranslation();
    const [connectionHealth, setConnectionHealth] = useState(waba.connection_health);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting]           = useState(false);
    const [syncingPhones, setSyncingPhones] = useState(false);
    const phoneList = waba.phone_numbers ?? waba.phoneNumbers ?? [];

    const caByPhone = {};
    (channelAccounts ?? []).forEach(ca => { caByPhone[String(ca.phone_number_id)] = ca; });

    const handleDelete = () => {
        setDeleting(true);
        router.delete(route('client.whatsapp.setup.destroy', { waba: waba.id }), {
            preserveScroll: true,
            onFinish: () => { setDeleting(false); setConfirmDelete(false); },
        });
    };

    const syncPhones = () => {
        setSyncingPhones(true);
        router.post(route('client.whatsapp.setup.sync-phone-numbers', { waba: waba.id }), {}, {
            preserveScroll: true,
            onFinish: () => setSyncingPhones(false),
        });
    };

    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800/60 overflow-hidden">
            {/* Header row */}
            <div className="flex items-center justify-between gap-3 px-4 py-3 bg-neutral-50 dark:bg-neutral-800">
                <div className="flex items-center gap-2.5 min-w-0">
                    <div className="rounded-lg bg-white dark:bg-neutral-700 p-1 shadow-sm border border-neutral-100 dark:border-neutral-600">
                        <WhatsAppLogo className="h-5 w-5" />
                    </div>
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                            <span className="text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wide">WABA</span>
                            <code className="font-mono text-xs text-neutral-600 dark:text-neutral-300">{waba.waba_id}</code>
                            <StatusBadge status={waba.status} />
                        </div>
                    </div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                    <Link
                        href={route('client.whatsapp.templates.index')}
                        className="flex items-center gap-1.5 rounded-lg border border-neutral-200 dark:border-neutral-600 bg-white dark:bg-neutral-700 px-2.5 py-1.5 text-xs font-medium text-neutral-600 dark:text-neutral-300 hover:border-brand-300 hover:text-brand-600 dark:hover:text-brand-400 transition"
                    >
                        <FileText className="h-3 w-3" /> {t('inbox.templates')}
                    </Link>
                    {confirmDelete ? (
                        <div className="flex items-center gap-1.5">
                            <button onClick={handleDelete} disabled={deleting}
                                className="rounded-lg bg-red-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-60 transition">
                                {deleting ? t('inbox.removing') : t('inbox.confirm')}
                            </button>
                            <button onClick={() => setConfirmDelete(false)}
                                className="rounded-lg border border-neutral-200 dark:border-neutral-600 px-2.5 py-1.5 text-xs text-neutral-600 dark:text-neutral-400 hover:bg-neutral-50 dark:hover:bg-neutral-700 transition">
                                {t('common.cancel')}
                            </button>
                        </div>
                    ) : (
                        <button onClick={() => setConfirmDelete(true)}
                            className="rounded-lg border border-red-200 dark:border-red-800 p-1.5 text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 hover:text-red-600 transition"
                            title={t('inbox.remove_waba')}>
                            <Trash2 className="h-3.5 w-3.5" />
                        </button>
                    )}
                </div>
            </div>

            {confirmDelete && (
                <div className="px-4 py-2.5 flex items-start gap-2 text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/20 border-b border-red-100 dark:border-red-900/30">
                    <AlertTriangle className="h-3.5 w-3.5 mt-0.5 shrink-0" />
                    <span>{t('inbox.remove_waba_warning')}</span>
                </div>
            )}

            <div className="p-4 space-y-4">
                <WhatsappConnectionHealth waba={waba} onReconnect={onReconnect} onHealthChange={setConnectionHealth} />
                {/* Webhook — always global for embedded signup */}
                <details className="space-y-2">
                    <summary className="cursor-pointer text-xs text-neutral-500 dark:text-neutral-400 focus-visible:outline focus-visible:outline-2">{t('inbox.health_webhook_details', { defaultValue: 'Webhook details' })}</summary>
                    <CodeField label={t('inbox.webhook_url')} value={webhookGlobalUrl} icon={Webhook} />
                    <p className="text-xs text-neutral-500 dark:text-neutral-400">
                        {t('inbox.registered_via_embedded')}
                    </p>
                </details>

                {/* Phone numbers */}
                <div>
                    <div className="flex items-center justify-between mb-3">
                        <p className="text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider flex items-center gap-1.5">
                            <Phone className="h-3 w-3" /> {t('inbox.phone_numbers')}
                            <span className="ml-1 rounded-full bg-neutral-100 dark:bg-neutral-700 px-1.5 py-0.5 text-[10px] font-semibold text-neutral-600 dark:text-neutral-300">{phoneList.length}</span>
                        </p>
                        <button onClick={syncPhones} disabled={syncingPhones}
                            className="flex items-center gap-1 text-xs text-brand-600 dark:text-brand-400 hover:text-brand-700 disabled:opacity-60 font-medium transition">
                            <RefreshCw className={`h-3 w-3 ${syncingPhones ? 'animate-spin' : ''}`} />
                            {syncingPhones ? t('inbox.syncing') : t('inbox.sync_from_meta')}
                        </button>
                    </div>

                    {phoneList.length > 0 ? (
                        <div className="space-y-2">
                            {phoneList.map((num, i) => {
                                const phoneId = num.phone_number_id ?? num.id;
                                const ca = caByPhone[String(phoneId)];
                                return (
                                    <div key={phoneId ?? i}>
                                        <PhoneStatusCard num={num} wabaId={waba.id} onRefreshed={() => {}} />
                                        {connectionHealth?.components?.[`phone:${phoneId}`]?.state !== 'passed' && connectionHealth?.components?.[`phone:${phoneId}`]?.message && (
                                            <p className="px-3 py-2 text-xs text-amber-700 dark:text-amber-300">{connectionHealth.components[`phone:${phoneId}`].message}</p>
                                        )}
                                        {ca && (
                                            <div className="px-3.5 pb-2 -mt-1 rounded-b-xl border-x border-b border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/60">
                                                <span className="flex items-center gap-1 text-xs font-medium text-brand-600 dark:text-brand-400">
                                                    <Inbox className="h-3 w-3" /> {t('inbox.active_in_inbox')}
                                                </span>
                                                {chatbots.length > 0 && (
                                                    <ChatbotSelector channelAccountId={ca.id} currentChatbotId={ca.ai_chatbot_id} chatbots={chatbots} />
                                                )}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    ) : (
                        <div className="rounded-xl border border-dashed border-neutral-200 dark:border-neutral-700 py-6 text-center">
                            <Phone className="h-5 w-5 text-neutral-300 dark:text-neutral-600 mx-auto mb-2" />
                            <p className="text-xs text-neutral-400">{t('inbox.no_phone_numbers')}</p>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function WhatsAppSection({ wabas, webhookGlobalUrl, channelAccountsByWaba, chatbots, showForm, setShowForm, metaConfigIdWhatsapp, metaAppId, className = '', onboardingOnly = false }) {
    const { t } = useTranslation();
    const [waApiError, setWaApiError] = useState(null);
    const [waSubmitting, setWaSubmitting] = useState(false);

    const handleWaEmbeddedCode = useCallback(async (code, wabaId, phoneNumberId = null, onboardingType = WHATSAPP_ONBOARDING_CLOUD_API) => {
        setWaApiError(null);
        setWaSubmitting(true);
        try {
            const res = await fetch(route('client.whatsapp.setup.embedded-signup'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ code, waba_id: wabaId, phone_number_id: phoneNumberId, onboarding_type: onboardingType }),
            });
            const json = await res.json();
            if (!res.ok) {
                setWaApiError(json.message ?? t('inbox.connection_failed'));
            } else {
                toast.success(json.message ?? 'WhatsApp account connected successfully.');
                if (json.webhook_warning) toast.warning(json.webhook_warning);
                router.reload({ preserveScroll: true });
                setShowForm(false);
            }
        } catch {
            setWaApiError(t('inbox.network_error_retry'));
        } finally {
            setWaSubmitting(false);
        }
    }, [setShowForm, t]);

    const onboardingForm = showForm ? (
        <div className={`${!onboardingOnly && wabas.length > 0 ? 'mt-4 pt-4 border-t border-neutral-100 dark:border-neutral-800' : ''} space-y-3`}>
                    {metaConfigIdWhatsapp ? (
                        <>
                            <p className="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                                {t('inbox.authorize_whatsapp_help')}
                            </p>
                            <div className="space-y-2">
                                <EmbeddedSignupButton
                                    configId={metaConfigIdWhatsapp}
                                    appId={metaAppId}
                                    channel="whatsapp"
                                    whatsappOnboarding={WHATSAPP_ONBOARDING_COEXISTENCE}
                                    label={t('inbox.connect_whatsapp_business_app', { defaultValue: 'Connect existing WhatsApp Business app' })}
                                    color="green"
                                    onCode={handleWaEmbeddedCode}
                                />
                                <p className="text-[11px] leading-relaxed text-neutral-400 dark:text-neutral-500">
                                    {t('inbox.whatsapp_coexistence_help', { defaultValue: 'Keep using the WhatsApp Business mobile app while WisperBot receives and sends messages through Cloud API.' })}
                                </p>
                                <EmbeddedSignupButton
                                    configId={metaConfigIdWhatsapp}
                                    appId={metaAppId}
                                    channel="whatsapp"
                                    whatsappOnboarding={WHATSAPP_ONBOARDING_CLOUD_API}
                                    label={t('inbox.connect_whatsapp_cloud_api', { defaultValue: 'Set up a Cloud API number' })}
                                    color="neutral"
                                    onCode={handleWaEmbeddedCode}
                                />
                            </div>
                            {waSubmitting && <p className="text-xs text-neutral-400">{t('inbox.connecting_whatsapp')}</p>}
                            {waApiError && (
                                <p className="text-xs text-red-500 flex items-start gap-1.5">
                                    <AlertTriangle className="h-3.5 w-3.5 shrink-0 mt-0.5" /> {waApiError}
                                </p>
                            )}
                        </>
                    ) : (
                        <div className="rounded-lg border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 px-3 py-2.5 text-xs text-amber-700 dark:text-amber-300 flex items-start gap-2">
                            <AlertTriangle className="h-3.5 w-3.5 shrink-0 mt-0.5" />
                            <span>{t('inbox.meta_app_not_configured')}</span>
                        </div>
                    )}
                    <button type="button" onClick={() => setShowForm(false)}
                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-xs text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                        {t('common.cancel')}
                    </button>
        </div>
    ) : null;

    if (onboardingOnly) {
        return onboardingForm;
    }

    return (
        <ChannelCard
            icon={WhatsAppLogo}
            iconBg="bg-white dark:bg-neutral-800 shadow-sm border border-neutral-100 dark:border-neutral-700"
            title={t('inbox.whatsapp_business')}
            count={wabas.length}
            className={className}
        >
            {wabas.length > 0 && (
                <div className="space-y-3">
                    {wabas.map(waba => (
                        <WabaCard
                            key={waba.id}
                            waba={waba}
                            onReconnect={() => setShowForm(true)}
                            webhookGlobalUrl={webhookGlobalUrl}
                            channelAccounts={channelAccountsByWaba?.[waba.id] ?? []}
                            chatbots={chatbots}
                        />
                    ))}
                </div>
            )}

            {!showForm && wabas.length === 0 && (
                <div className="text-center py-8">
                    <div className="mx-auto mb-3 rounded-2xl w-12 h-12 flex items-center justify-center bg-green-100 dark:bg-green-900/30 opacity-60">
                        <WhatsAppLogo className="h-6 w-6" />
                    </div>
                    <p className="text-sm text-neutral-400 dark:text-neutral-500">{t('inbox.no_whatsapp_accounts')}</p>
                </div>
            )}

            {onboardingForm}
        </ChannelCard>
    );
}

/* â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ Instagram / Messenger sections â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ */

function AccountRow({ account, channel, chatbots }) {
    const { t } = useTranslation();
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const pageId = channel === 'instagram'
        ? account.meta_json?.instagram_page_id
        : account.meta_json?.page_id;

    const handleDelete = () => {
        setDeleting(true);
        router.delete(route('client.inbox.setup.destroy', { channelAccount: account.id }), {
            preserveScroll: true,
            onFinish: () => { setDeleting(false); setConfirmDelete(false); },
        });
    };

    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/60 overflow-hidden">
            <div className="flex items-start justify-between gap-3 px-3.5 py-3">
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-semibold text-sm text-neutral-900 dark:text-neutral-100">{account.display_name}</span>
                        <StatusBadge status={account.status} />
                    </div>
                    {pageId && <div className="font-mono text-xs text-neutral-400 mt-0.5">{t('inbox.page_id_label')} {pageId}</div>}
                    {chatbots.length > 0 && (
                        <ChatbotSelector
                            channelAccountId={account.id}
                            currentChatbotId={account.ai_chatbot_id}
                            chatbots={chatbots}
                        />
                    )}
                </div>
                <div className="flex items-center gap-1.5 shrink-0">
                    {confirmDelete ? (
                        <>
                            <button onClick={handleDelete} disabled={deleting}
                                className="rounded-lg bg-red-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-60 transition">
                                {deleting ? t('inbox.removing') : t('inbox.confirm')}
                            </button>
                            <button onClick={() => setConfirmDelete(false)}
                                className="rounded-lg border border-neutral-200 dark:border-neutral-600 px-2.5 py-1.5 text-xs text-neutral-600 dark:text-neutral-400 transition">
                                {t('common.cancel')}
                            </button>
                        </>
                    ) : (
                        <button onClick={() => setConfirmDelete(true)}
                            className="rounded-lg border border-red-200 dark:border-red-800 p-1.5 text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 hover:text-red-600 transition">
                            <Trash2 className="h-3.5 w-3.5" />
                        </button>
                    )}
                </div>
            </div>
            {confirmDelete && (
                <div className="flex items-start gap-2 px-3.5 py-2 text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/20 border-t border-red-100 dark:border-red-900/30">
                    <AlertTriangle className="h-3.5 w-3.5 mt-0.5 shrink-0" />
                    <span>{t('inbox.account_remove_warning')}</span>
                </div>
            )}
        </div>
    );
}

function EbayAccountRow({ account, chatbots }) {
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [working, setWorking] = useState(false);
    const sellerId = account.meta_json?.seller_user_id;

    const remove = () => {
        setWorking(true);
        router.delete(route('client.inbox.setup.destroy', { channelAccount: account.id }), {
            preserveScroll: true,
            onFinish: () => setWorking(false),
        });
    };

    const sync = () => {
        setWorking(true);
        router.post(route('client.inbox.setup.ebay.sync', { channelAccount: account.id }), {}, {
            preserveScroll: true,
            onFinish: () => setWorking(false),
        });
    };

    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/60 p-3.5">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-semibold text-sm text-neutral-900 dark:text-neutral-100">{account.display_name}</span>
                        <StatusBadge status={account.status} />
                    </div>
                    {sellerId && <p className="font-mono text-xs text-neutral-400 mt-0.5">Seller: {sellerId}</p>}
                    {account.meta_json?.last_sync_at && (
                        <p className="text-[11px] text-neutral-400 mt-1">Last synced {new Date(account.meta_json.last_sync_at).toLocaleString()}</p>
                    )}
                    {chatbots.length > 0 && (
                        <ChatbotSelector channelAccountId={account.id} currentChatbotId={account.ai_chatbot_id} chatbots={chatbots} />
                    )}
                </div>
                <div className="flex items-center gap-1.5">
                    <button type="button" onClick={sync} disabled={working} title="Sync eBay messages"
                        className="rounded-lg border border-neutral-200 dark:border-neutral-600 p-1.5 text-neutral-500 hover:text-blue-600 disabled:opacity-50">
                        <RefreshCw className={`h-3.5 w-3.5 ${working ? 'animate-spin' : ''}`} />
                    </button>
                    {confirmDelete ? (
                        <button type="button" onClick={remove} disabled={working}
                            className="rounded-lg bg-red-600 px-2.5 py-1.5 text-xs text-white disabled:opacity-50">
                            Disconnect
                        </button>
                    ) : (
                        <button type="button" onClick={() => setConfirmDelete(true)}
                            className="rounded-lg border border-red-200 dark:border-red-800 p-1.5 text-red-400 hover:bg-red-50">
                            <Trash2 className="h-3.5 w-3.5" />
                        </button>
                    )}
                </div>
            </div>
            {confirmDelete && (
                <p className="mt-2 text-xs text-red-600">Disconnecting removes this seller account from this workspace. Existing inbox history is retained.</p>
            )}
        </div>
    );
}

function AmazonAccountRow({ account }) {
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [working, setWorking] = useState(false);
    const [orderId, setOrderId] = useState('');
    const sellerId = account.meta_json?.selling_partner_id;

    const remove = () => {
        setWorking(true);
        router.delete(route('client.inbox.setup.destroy', { channelAccount: account.id }), {
            preserveScroll: true,
            onFinish: () => setWorking(false),
        });
    };

    const checkActions = (event) => {
        event.preventDefault();
        if (!orderId.trim()) return;
        setWorking(true);
        router.post(route('client.inbox.setup.amazon.actions', { channelAccount: account.id }), {
            amazon_order_id: orderId.trim(),
        }, {
            preserveScroll: true,
            onFinish: () => setWorking(false),
        });
    };

    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/60 p-3.5">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-semibold text-sm text-neutral-900 dark:text-neutral-100">{account.display_name}</span>
                        <StatusBadge status={account.status} />
                    </div>
                    {sellerId && <p className="font-mono text-[11px] text-neutral-400 mt-0.5 truncate">Seller: {sellerId}</p>}
                    <p className="mt-2 text-[11px] leading-relaxed text-neutral-500 dark:text-neutral-400">
                        Amazon supports approved, order-specific outbound buyer messages. Its SP-API does not expose the inbound Seller Central message inbox.
                    </p>
                    <form onSubmit={checkActions} className="mt-3 flex gap-1.5">
                        <input
                            value={orderId}
                            onChange={(event) => setOrderId(event.target.value)}
                            placeholder="Amazon order ID"
                            className="min-w-0 flex-1 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-2 py-1.5 text-[11px]"
                        />
                        <button type="submit" disabled={working || !orderId.trim()}
                            className="rounded-lg bg-[#ff9900] px-2.5 py-1.5 text-[11px] font-medium text-neutral-950 disabled:opacity-50">
                            Check actions
                        </button>
                    </form>
                </div>
                {confirmDelete ? (
                    <button type="button" onClick={remove} disabled={working}
                        className="rounded-lg bg-red-600 px-2.5 py-1.5 text-xs text-white disabled:opacity-50">
                        Disconnect
                    </button>
                ) : (
                    <button type="button" onClick={() => setConfirmDelete(true)}
                        className="rounded-lg border border-red-200 dark:border-red-800 p-1.5 text-red-400 hover:bg-red-50">
                        <Trash2 className="h-3.5 w-3.5" />
                    </button>
                )}
            </div>
        </div>
    );
}

function TelegramAccountRow({ account, chatbots }) {
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [working, setWorking] = useState(false);

    const remove = () => {
        setWorking(true);
        router.delete(route('client.inbox.setup.destroy', { channelAccount: account.id }), {
            preserveScroll: true,
            onFinish: () => setWorking(false),
        });
    };

    return (
        <div className="rounded-xl border border-neutral-200 bg-neutral-50 p-3.5 dark:border-neutral-700 dark:bg-neutral-800/60">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{account.display_name}</span>
                        <StatusBadge status={account.status} />
                    </div>
                    {account.status === 'inactive' && (
                        <p className="mt-1.5 text-xs leading-relaxed text-amber-700 dark:text-amber-300">
                            Pairing is not complete. Open a new pairing link, then connect the bot in Telegram Settings → Telegram Business → Chatbots.
                        </p>
                    )}
                    {account.status === 'error' && (
                        <p className="mt-1.5 text-xs text-red-600 dark:text-red-400">Telegram revoked or rejected this business connection. Reconnect it before replying.</p>
                    )}
                    {account.business_connection_id && (
                        <p className="mt-1 font-mono text-[11px] text-neutral-400">Connection: {account.business_connection_id}</p>
                    )}
                    {account.status === 'active' && chatbots.length > 0 && (
                        <ChatbotSelector channelAccountId={account.id} currentChatbotId={account.ai_chatbot_id} chatbots={chatbots} />
                    )}
                    {account.status !== 'active' && (
                        <a href={route('client.inbox.setup.telegram.connect')} className="mt-2 inline-flex text-xs font-semibold text-sky-600 hover:underline dark:text-sky-400">
                            Create a new pairing link
                        </a>
                    )}
                </div>
                {confirmDelete ? (
                    <button type="button" onClick={remove} disabled={working} className="rounded-lg bg-red-600 px-2.5 py-1.5 text-xs text-white disabled:opacity-50">Remove</button>
                ) : (
                    <button type="button" onClick={() => setConfirmDelete(true)} className="rounded-lg border border-red-200 p-1.5 text-red-400 hover:bg-red-50 dark:border-red-800">
                        <Trash2 className="h-3.5 w-3.5" />
                    </button>
                )}
            </div>
            {confirmDelete && <p className="mt-2 text-xs text-red-600">Inbox history stays available, but new Telegram messages stop routing here. Revoke the bot in Telegram Business settings too.</p>}
        </div>
    );
}

/* ─────────────────── Meta Embedded Signup helpers ─────────────────── */

/**
 * Listens for the WA_EMBEDDED_SIGNUP postMessage that Meta sends when
 * sessionInfoVersion:'3' is set. Resolves with { waba_id, phone_number_id }.
 */
function waitForWabaSessionInfo(timeout = 15000) {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
            window.removeEventListener('message', handler);
            // Meta can return a valid OAuth code without sending the optional
            // WA_EMBEDDED_SIGNUP payload (notably when previous settings are
            // reused). The backend can discover the granted WABA from the token.
            resolve({});
        }, timeout);

        function handler(event) {
            let hostname;
            try {
                hostname = new URL(event.origin).hostname;
            } catch {
                return;
            }
            if (hostname !== 'facebook.com' && !hostname.endsWith('.facebook.com')) return;

            try {
                const parsed = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
                if (parsed?.type === 'WA_EMBEDDED_SIGNUP') {
                    if (parsed.event === 'CANCEL' || parsed.event === 'ERROR') {
                        clearTimeout(timer);
                        window.removeEventListener('message', handler);
                        reject(new Error(parsed.data?.error_message ?? 'WhatsApp authorization was not completed.'));
                        return;
                    }

                    if (!isWhatsappEmbeddedSignupFinish(parsed.event)) return;

                    clearTimeout(timer);
                    window.removeEventListener('message', handler);
                    resolve(parsed.data ?? {});
                }
            } catch {
                // Ignore unrelated non-JSON cross-window messages.
            }
        }

        window.addEventListener('message', handler);
    });
}

function initFbSdk(appId) {
    if (typeof window.FB === 'undefined' || !appId) return false;
    try {
        FB.init({ appId, autoLogAppEvents: true, xfbml: false, version: 'v20.0' });
        window.__fbSdkReady = true;
        return true;
    } catch (_) {
        return false;
    }
}

/**
 * Loads the Facebook JS SDK on demand and resolves when FB.init() is done.
 * Safe to call multiple times — returns immediately if SDK is already ready.
 */
function loadFbSdk(appId) {
    if (!appId) {
        return Promise.reject(new Error(
            'Meta App ID is not configured. Ask your administrator to set App ID in Admin → Integrations → Meta App.'
        ));
    }

    if (window.__fbSdkReady && typeof window.FB !== 'undefined') {
        return Promise.resolve();
    }

    if (typeof window.FB !== 'undefined' && initFbSdk(appId)) {
        return Promise.resolve();
    }

    if (window.__fbSdkPromise) return window.__fbSdkPromise;

    const blockedMsg =
        'Facebook SDK could not load. Disable ad blockers or privacy shields for this site, ' +
        'or ask your administrator to allow https://connect.facebook.net in the Content-Security-Policy.';

    window.__fbSdkPromise = new Promise((resolve, reject) => {
        let settled = false;
        const settle = (fn, val) => {
            if (settled) return;
            settled = true;
            clearInterval(poll);
            clearTimeout(timer);
            fn(val);
        };

        const tryReady = () => {
            if (window.__fbSdkReady && typeof window.FB !== 'undefined') {
                settle(resolve);
                return true;
            }
            if (typeof window.FB !== 'undefined' && initFbSdk(appId)) {
                settle(resolve);
                return true;
            }
            return false;
        };

        const poll = setInterval(() => { tryReady(); }, 100);

        const existingScript = document.querySelector('script[src*="connect.facebook.net"]');
        const alreadyInjected = !!existingScript;

        const onScriptError = () => settle(reject, new Error(blockedMsg));
        if (existingScript) {
            existingScript.addEventListener('error', onScriptError, { once: true });
        }

        if (!alreadyInjected) {
            window.fbAsyncInit = () => {
                try {
                    initFbSdk(appId);
                    settle(resolve);
                } catch (e) {
                    settle(reject, e);
                }
            };

            const script = document.createElement('script');
            script.src = 'https://connect.facebook.net/en_US/sdk.js';
            script.async = true;
            script.defer = true;
            script.crossOrigin = 'anonymous';
            script.onerror = onScriptError;
            document.body.appendChild(script);
        }

        const timer = setTimeout(() => {
            settle(reject, new Error(
                'Facebook SDK did not load within 15 seconds. ' + blockedMsg
            ));
        }, 15000);
    });

    window.__fbSdkPromise.catch(() => { window.__fbSdkPromise = null; });

    return window.__fbSdkPromise;
}

function EmbeddedSignupButton({ configId, appId, channel, whatsappOnboarding = WHATSAPP_ONBOARDING_CLOUD_API, label, color, onCode, children }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const resolvedAppId = appId || props.metaAppId;
    const [loading, setLoading] = useState(false);
    const [error, setError]     = useState(null);
    const [showMetaGuide, setShowMetaGuide] = useState(false);

    const launch = useCallback(async () => {
        setError(null);

        if (!configId) {
            setError(t('inbox.embedded_signup_not_configured'));
            return;
        }

        setLoading(true);
        setShowMetaGuide(true);

        try {
            await loadFbSdk(resolvedAppId);
        } catch (e) {
            setLoading(false);
            setShowMetaGuide(false);
            setError(e?.message ?? t('inbox.could_not_load_fb_sdk'));
            return;
        }

        const isWhatsapp = channel === 'whatsapp';
        const sessionInfoPromise = isWhatsapp ? waitForWabaSessionInfo() : Promise.resolve(null);

        window.FB.login(
            (response) => {
                setShowMetaGuide(false);
                if (response.authResponse && response.authResponse.code) {
                    const code = response.authResponse.code;
                    if (isWhatsapp) {
                        sessionInfoPromise
                            .then((info) => {
                                setLoading(false);
                                onCode(code, info?.waba_id ?? null, info?.phone_number_id ?? null, whatsappOnboarding);
                            })
                            .catch(() => { setLoading(false); onCode(code, null, null, whatsappOnboarding); });
                    } else {
                        setLoading(false);
                        onCode(code);
                    }
                } else {
                    setLoading(false);
                    if (response.status !== 'connected') {
                        setError(t('inbox.authorization_cancelled'));
                    }
                }
            },
            embeddedSignupLoginOptions(configId, channel, whatsappOnboarding),
        );
    }, [configId, resolvedAppId, channel, onCode, t, whatsappOnboarding]);

    const colors = {
        green:  'bg-[#25D366] hover:bg-[#1ebe5d] text-white',
        neutral: 'border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 hover:bg-neutral-50 dark:hover:bg-neutral-700 text-neutral-800 dark:text-neutral-100 shadow-none',
        blue:   'bg-[#0866FF] hover:bg-[#0759e0] text-white',
        purple: 'bg-gradient-to-r from-pink-500 to-purple-600 hover:from-pink-600 hover:to-purple-700 text-white',
    };
    return (
        <div className="space-y-2">
            <button
                type="button"
                onClick={launch}
                disabled={loading}
                className={`w-full flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold shadow-sm transition disabled:opacity-60 ${colors[color] ?? colors.blue}`}
            >
                {loading ? (
                    <svg className="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                ) : (
                    <svg viewBox="0 0 24 24" className="h-4 w-4 fill-current"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                )}
                {loading ? t('inbox.opening_meta') : label}
            </button>
            {error && (
                <p className="text-xs text-red-500 flex items-start gap-1.5">
                    <AlertTriangle className="h-3.5 w-3.5 shrink-0 mt-0.5" /> {error}
                </p>
            )}
            {children}
            {showMetaGuide && (
                <MetaOnboardingGuide
                    channel={channel}
                    onClose={() => setShowMetaGuide(false)}
                />
            )}
        </div>
    );
}

function MetaOnboardingGuide({ onClose, channel }) {
    const { t } = useTranslation();

    return (
        <div
            className="fixed inset-0 z-[100] flex items-center justify-center bg-black/45 px-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="meta-onboarding-title"
        >
            <div className="w-full max-w-md rounded-2xl border border-neutral-200 bg-white p-6 shadow-2xl dark:border-neutral-700 dark:bg-neutral-900">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-[#0866FF]/10 text-[#0866FF]">
                            <ExternalLink className="h-5 w-5" />
                        </div>
                        <div>
                            <h2 id="meta-onboarding-title" className="text-base font-semibold text-neutral-900 dark:text-white">
                                {t('inbox.complete_setup_with_meta')}
                            </h2>
                            <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                {channel === 'whatsapp'
                                    ? t('inbox.secure_whatsapp_onboarding')
                                    : t('inbox.secure_meta_onboarding')}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                        aria-label={t('common.close')}
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
                <div className="mt-5 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm leading-relaxed text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-200">
                    {t('inbox.meta_popup_guidance')}
                </div>
                <div className="mt-4 flex items-center gap-2 text-xs text-neutral-500 dark:text-neutral-400">
                    <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                    {t('inbox.waiting_for_meta')}
                </div>
            </div>
        </div>
    );
}

/* ─────────────────── Instagram connect drawer ─────────────────── */

function AddInstagramForm({ onSuccess, metaConfigIdSocial, metaAppId, metaConfigIdWhatsapp }) {
    const { t } = useTranslation();
    const [apiError, setApiError] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [selection, setSelection] = useState(null);
    const configMismatch = metaConfigIdSocial && metaConfigIdWhatsapp && metaConfigIdSocial === metaConfigIdWhatsapp;

    const submitConnection = useCallback(async (payload) => {
        setApiError(null);
        setSubmitting(true);
        try {
            const res = await fetch(route('client.inbox.setup.embedded-signup.instagram'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (res.status === 409 && json.requires_selection) {
                setSelection({
                    token: json.selection_token,
                    accounts: json.accounts ?? [],
                    message: json.message ?? 'Choose the Instagram account you want to connect.',
                });
            } else if (!res.ok) {
                setApiError(json.message ?? t('inbox.connection_failed'));
            } else {
                router.reload({ preserveScroll: true });
                onSuccess?.();
            }
        } catch {
            setApiError(t('inbox.network_error_retry'));
        } finally {
            setSubmitting(false);
        }
    }, [onSuccess, t]);

    const handleEmbeddedCode = useCallback(async (code) => {
        setSelection(null);
        await submitConnection({ code });
    }, [submitConnection]);

    const connectSelectedAccount = useCallback(async (accountId) => {
        if (!selection?.token || !accountId) return;

        await submitConnection({
            selection_token: selection.token,
            selected_instagram_account_id: accountId,
        });
    }, [selection, submitConnection]);

    if (!metaConfigIdSocial) {
        return (
            <div className="rounded-lg border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 px-3 py-2.5 text-xs text-amber-700 dark:text-amber-300 flex items-start gap-2">
                <AlertTriangle className="h-3.5 w-3.5 shrink-0 mt-0.5" />
                <span>{t('inbox.meta_app_social_not_configured')}</span>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {configMismatch && (
                <div className="rounded-lg border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 px-3 py-2.5 text-xs text-amber-700 dark:text-amber-300 flex items-start gap-2">
                    <AlertTriangle className="h-3.5 w-3.5 shrink-0 mt-0.5" />
                    <span><strong>{t('inbox.misconfiguration_label')}</strong> {t('inbox.config_mismatch_instagram')}</span>
                </div>
            )}
            <p className="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                {t('inbox.authorize_instagram_help')}
            </p>
            <EmbeddedSignupButton
                configId={metaConfigIdSocial}
                appId={metaAppId}
                channel="instagram"
                label={t('inbox.continue_meta_instagram')}
                color="purple"
                onCode={handleEmbeddedCode}
            />
            {selection && (
                <div className="rounded-xl border border-pink-100 bg-pink-50/60 p-3 dark:border-pink-900/50 dark:bg-pink-950/20">
                    <p className="text-xs font-medium text-neutral-800 dark:text-neutral-100">{selection.message}</p>
                    <div className="mt-2 space-y-2">
                        {selection.accounts.map((account) => (
                            <button
                                type="button"
                                key={account.instagram_account_id}
                                onClick={() => connectSelectedAccount(account.instagram_account_id)}
                                disabled={submitting}
                                className="w-full rounded-lg border border-white bg-white px-3 py-2 text-left text-xs shadow-sm transition hover:border-pink-200 hover:bg-pink-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-pink-800"
                            >
                                <span className="block font-semibold text-neutral-900 dark:text-neutral-100">{account.name}</span>
                                <span className="mt-0.5 block text-neutral-500 dark:text-neutral-400">
                                    Instagram ID: {account.instagram_account_id}
                                    {account.facebook_page_name ? ` • Page: ${account.facebook_page_name}` : ''}
                                </span>
                            </button>
                        ))}
                    </div>
                </div>
            )}
            {submitting && <p className="text-xs text-neutral-400">{t('inbox.connecting_accounts')}</p>}
            {apiError && (
                <p className="text-xs text-red-500 flex items-start gap-1.5">
                    <AlertTriangle className="h-3.5 w-3.5 shrink-0 mt-0.5" /> {apiError}
                </p>
            )}
        </div>
    );
}

function AddMessengerForm({ onSuccess, metaConfigIdSocial, metaAppId, metaConfigIdWhatsapp }) {
    const { t } = useTranslation();
    const [apiError, setApiError] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [selection, setSelection] = useState(null);
    const configMismatch = metaConfigIdSocial && metaConfigIdWhatsapp && metaConfigIdSocial === metaConfigIdWhatsapp;

    const submitConnection = useCallback(async (payload) => {
        setApiError(null);
        setSubmitting(true);
        try {
            const res = await fetch(route('client.inbox.setup.embedded-signup.messenger'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (res.status === 409 && json.requires_selection) {
                setSelection({
                    token: json.selection_token,
                    accounts: json.accounts ?? [],
                    message: json.message ?? 'Choose the Facebook Page you want to connect.',
                });
            } else if (!res.ok) {
                setApiError(json.message ?? t('inbox.connection_failed'));
            } else {
                router.reload({ preserveScroll: true });
                onSuccess?.();
            }
        } catch {
            setApiError(t('inbox.network_error_retry'));
        } finally {
            setSubmitting(false);
        }
    }, [onSuccess, t]);

    const handleEmbeddedCode = useCallback(async (code) => {
        setSelection(null);
        await submitConnection({ code });
    }, [submitConnection]);

    const connectSelectedPage = useCallback(async (pageId) => {
        if (!selection?.token || !pageId) return;

        await submitConnection({
            selection_token: selection.token,
            selected_facebook_page_id: pageId,
        });
    }, [selection, submitConnection]);

    if (!metaConfigIdSocial) {
        return (
            <div className="rounded-lg border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 px-3 py-2.5 text-xs text-amber-700 dark:text-amber-300 flex items-start gap-2">
                <AlertTriangle className="h-3.5 w-3.5 shrink-0 mt-0.5" />
                <span>{t('inbox.meta_app_social_not_configured')}</span>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {configMismatch && (
                <div className="rounded-lg border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 px-3 py-2.5 text-xs text-amber-700 dark:text-amber-300 flex items-start gap-2">
                    <AlertTriangle className="h-3.5 w-3.5 shrink-0 mt-0.5" />
                    <span><strong>{t('inbox.misconfiguration_label')}</strong> {t('inbox.config_mismatch_messenger')}</span>
                </div>
            )}
            <p className="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                {t('inbox.authorize_messenger_help')}
            </p>
            <EmbeddedSignupButton
                configId={metaConfigIdSocial}
                appId={metaAppId}
                channel="messenger"
                label={t('inbox.continue_meta_messenger')}
                color="blue"
                onCode={handleEmbeddedCode}
            />
            {selection && (
                <div className="rounded-xl border border-blue-100 bg-blue-50/60 p-3 dark:border-blue-900/50 dark:bg-blue-950/20">
                    <p className="text-xs font-medium text-neutral-800 dark:text-neutral-100">{selection.message}</p>
                    <div className="mt-2 space-y-2">
                        {selection.accounts.map((account) => (
                            <button
                                type="button"
                                key={account.facebook_page_id}
                                onClick={() => connectSelectedPage(account.facebook_page_id)}
                                disabled={submitting}
                                className="w-full rounded-lg border border-white bg-white px-3 py-2 text-left text-xs shadow-sm transition hover:border-blue-200 hover:bg-blue-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-blue-800"
                            >
                                <span className="block font-semibold text-neutral-900 dark:text-neutral-100">{account.name}</span>
                                <span className="mt-0.5 block text-neutral-500 dark:text-neutral-400">
                                    Page ID: {account.facebook_page_id}
                                </span>
                            </button>
                        ))}
                    </div>
                </div>
            )}
            {submitting && <p className="text-xs text-neutral-400">{t('inbox.connecting_pages')}</p>}
            {apiError && (
                <p className="text-xs text-red-500 flex items-start gap-1.5">
                    <AlertTriangle className="h-3.5 w-3.5 shrink-0 mt-0.5" /> {apiError}
                </p>
            )}
        </div>
    );
}

function ConnectDrawer({ open, onClose, title, icon: Icon, iconBg, children }) {
    if (!open) return null;
    return (
        <>
            <div className="fixed inset-0 z-40 bg-black/50 backdrop-blur-sm" onClick={onClose} />
            <div className="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col bg-white dark:bg-neutral-900 shadow-2xl">
                <div className="flex items-center gap-3 border-b border-neutral-200 dark:border-neutral-700 px-6 py-4">
                    <div className={`rounded-xl p-2 ${iconBg}`}>
                        <Icon className="h-4 w-4" />
                    </div>
                    <h3 className="flex-1 font-semibold text-neutral-900 dark:text-neutral-100">{title}</h3>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition">
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                <div className="flex-1 overflow-y-auto px-6 py-5">
                    {children}
                </div>
            </div>
        </>
    );
}

/* â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ page root â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ */

export default function ChannelSetup({
    wabas, whatsappWebhookGlobalUrl,
    channelAccountsByWaba, instagramAccounts, messengerAccounts,
    ebayAccounts = [],
    amazonAccounts = [],
    telegramAccounts = [],
    telegramConfigured = false,
    metaAppId = null, metaConfigIdWhatsapp = null, metaConfigIdSocial = null,
    chatbots = [],
}) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const hasConnectedChannels = wabas.length > 0
        || instagramAccounts.length > 0
        || messengerAccounts.length > 0
        || telegramAccounts.length > 0
        || ebayAccounts.length > 0
        || amazonAccounts.length > 0;

    const [drawer, setDrawer] = useState(null);

    const openDrawer = (key) => {
        setDrawer(key);
    };
    const closeDrawer = () => {
        setDrawer(null);
    };

    return (
        <ClientLayout title={t('inbox.channel_setup')}>
            <Head title={t('inbox.channel_setup')} />

            {/* Page header */}
            <div className="mb-6 space-y-4">
                <div>
                    <h2 className="text-xl font-bold text-neutral-900 dark:text-neutral-100">{t('inbox.channel_setup')}</h2>
                    <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                        {t('inbox.channel_setup_subtitle')}
                    </p>
                </div>

                <div className="rounded-2xl border border-neutral-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p className="mb-3 px-1 text-xs font-semibold uppercase tracking-wide text-neutral-400 dark:text-neutral-500">
                        Connect a channel
                    </p>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-6">
                        <button type="button" onClick={() => openDrawer('whatsapp')}
                            className="flex min-h-11 items-center justify-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-2.5 text-sm font-semibold text-green-700 shadow-sm transition hover:border-green-300 hover:bg-green-100 dark:border-green-800 dark:bg-green-950/30 dark:text-green-400 dark:hover:bg-green-950/50 xl:col-span-2">
                            <WhatsAppLogo className="h-4 w-4" /> {t('inbox.connect_whatsapp')}
                        </button>
                        <button type="button" onClick={() => openDrawer('messenger')}
                            className="flex min-h-11 items-center justify-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-700 shadow-sm transition hover:border-blue-300 hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-400 dark:hover:bg-blue-950/50 xl:col-span-2">
                            <MessengerLogo className="h-4 w-4" /> {t('inbox.connect_messenger')}
                        </button>
                        <button type="button" onClick={() => openDrawer('instagram')}
                            className="flex min-h-11 items-center justify-center gap-2 rounded-xl border border-pink-200 bg-pink-50 px-4 py-2.5 text-sm font-semibold text-pink-700 shadow-sm transition hover:border-pink-300 hover:bg-pink-100 dark:border-pink-800 dark:bg-pink-950/30 dark:text-pink-400 dark:hover:bg-pink-950/50 xl:col-span-2">
                            <InstagramLogo className="h-4 w-4" /> {t('inbox.connect_instagram')}
                        </button>
                        <a href={telegramConfigured ? route('client.inbox.setup.telegram.connect') : '#'}
                            onClick={(event) => { if (!telegramConfigured) { event.preventDefault(); toast.error('Telegram Business is not configured by the Super Admin.'); } }}
                            className="flex min-h-11 items-center justify-center gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm font-semibold text-sky-700 shadow-sm transition hover:border-sky-300 hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/30 dark:text-sky-300 xl:col-span-2">
                            <TelegramLogo className="h-4 w-4" /> Connect Telegram
                        </a>
                        <a href={route('client.inbox.setup.ebay.connect')}
                            className="flex min-h-11 items-center justify-center gap-2 rounded-xl border border-blue-200 bg-blue-50/50 px-4 py-2.5 text-sm font-semibold text-blue-700 shadow-sm transition hover:border-blue-300 hover:bg-blue-50 dark:border-blue-800 dark:bg-blue-950/20 dark:text-blue-300 xl:col-span-2">
                            <EbayLogo className="h-4 w-9" /> Connect eBay
                        </a>
                        <a href={route('client.inbox.setup.amazon.connect')}
                            className="flex min-h-11 items-center justify-center gap-2 rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-2.5 text-sm font-semibold text-neutral-800 shadow-sm transition hover:border-amber-300 hover:bg-amber-50 dark:border-amber-800 dark:bg-amber-950/20 dark:text-neutral-200 xl:col-span-2">
                            <AmazonLogo className="h-4 w-9" /> Connect Amazon
                        </a>
                    </div>
                </div>
            </div>

            {/* Flash message */}
            {flash.success && (
                <div className="mb-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 px-4 py-3 text-sm flex items-center gap-2">
                    <Check className="h-4 w-4 shrink-0" />
                    {flash.success}
                </div>
            )}
            {(flash.error || flash.warning) && (
                <div className={`mb-4 rounded-xl px-4 py-3 text-sm flex items-start gap-2 border ${flash.error ? 'bg-red-50 border-red-200 text-red-800 dark:bg-red-900/20 dark:border-red-800 dark:text-red-200' : 'bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-200'}`}>
                    <AlertTriangle className="h-4 w-4 shrink-0 mt-0.5" />
                    {flash.error || flash.warning}
                </div>
            )}

            {/* No chatbots warning */}
            {chatbots.length === 0 && (
                <div className="mb-4 flex items-start gap-3 rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 px-4 py-3 text-sm text-amber-700 dark:text-amber-300">
                    <Bot className="h-4 w-4 shrink-0 mt-0.5" />
                    <span>{t('inbox.no_active_chatbots')} <Link href={route('client.ai.chatbots.index')} className="underline font-semibold">{t('inbox.create_one')}</Link> {t('inbox.to_enable_ai_replies')}</span>
                </div>
            )}

            {/* Connected account management. Empty channels use the setup CTAs above. */}
            {hasConnectedChannels && (
            <div className="grid grid-cols-1 gap-4 mb-6 md:grid-cols-2 xl:grid-cols-6">

                {/* WhatsApp */}
                {wabas.length > 0 && (
                <WhatsAppSection
                    wabas={wabas}
                    webhookGlobalUrl={whatsappWebhookGlobalUrl}
                    channelAccountsByWaba={channelAccountsByWaba ?? {}}
                    chatbots={chatbots}
                    showForm={false}
                    setShowForm={() => {}}
                    metaConfigIdWhatsapp={metaConfigIdWhatsapp}
                    metaAppId={metaAppId}
                    className="xl:col-span-2"
                />
                )}

                {/* Instagram */}
                {instagramAccounts.length > 0 && (
                <ChannelCard
                    icon={InstagramLogo}
                    iconBg="bg-white dark:bg-neutral-800 shadow-sm border border-neutral-100 dark:border-neutral-700"
                    title={t('inbox.instagram_business')}
                    count={instagramAccounts.length}
                    className="xl:col-span-2"
                >
                    <div className="space-y-2">
                        {instagramAccounts.map(a => <AccountRow key={a.id} account={a} channel="instagram" chatbots={chatbots} />)}
                    </div>
                </ChannelCard>
                )}

                {/* Messenger */}
                {messengerAccounts.length > 0 && (
                <ChannelCard
                    icon={MessengerLogo}
                    iconBg="bg-white dark:bg-neutral-800 shadow-sm border border-neutral-100 dark:border-neutral-700"
                    title={t('inbox.facebook_messenger')}
                    count={messengerAccounts.length}
                    className="xl:col-span-2"
                >
                    <div className="space-y-2">
                        {messengerAccounts.map(a => <AccountRow key={a.id} account={a} channel="messenger" chatbots={chatbots} />)}
                    </div>
                </ChannelCard>
                )}

                {/* Telegram Business connected-bot inbox */}
                {telegramAccounts.length > 0 && (
                <ChannelCard
                    icon={TelegramLogo}
                    iconBg="bg-white shadow-sm ring-1 ring-neutral-100 dark:bg-neutral-800 dark:ring-neutral-700"
                    title="Telegram Business"
                    count={telegramAccounts.filter(account => account.status === 'active').length}
                    className="xl:col-span-2"
                >
                    <div className="space-y-2">
                        {telegramAccounts.map(account => <TelegramAccountRow key={account.id} account={account} chatbots={chatbots} />)}
                    </div>
                </ChannelCard>
                )}

                {/* eBay seller inbox */}
                {ebayAccounts.length > 0 && (
                <ChannelCard
                    icon={EbayLogo}
                    iconBg="bg-white dark:bg-neutral-800 shadow-sm border border-neutral-100 dark:border-neutral-700"
                    title="eBay Seller Messages"
                    count={ebayAccounts.length}
                    className="xl:col-span-2"
                >
                    <div className="space-y-2">
                        {ebayAccounts.map(account => <EbayAccountRow key={account.id} account={account} chatbots={chatbots} />)}
                    </div>
                </ChannelCard>
                )}

                {/* Amazon Seller Central */}
                {amazonAccounts.length > 0 && (
                <ChannelCard
                    icon={AmazonLogo}
                    iconBg="bg-white dark:bg-neutral-800 shadow-sm border border-neutral-100 dark:border-neutral-700"
                    title="Amazon Seller Messaging"
                    count={amazonAccounts.length}
                    className="xl:col-span-2"
                >
                    <div className="space-y-2">
                        {amazonAccounts.map(account => <AmazonAccountRow key={account.id} account={account} />)}
                    </div>
                </ChannelCard>
                )}
            </div>
            )}

            {/* Connect WhatsApp drawer */}
            <ConnectDrawer open={drawer === 'whatsapp'} onClose={closeDrawer}
                title={t('inbox.connect_whatsapp')}
                icon={WhatsAppLogo}
                iconBg="bg-white dark:bg-neutral-800 shadow-sm border border-neutral-100 dark:border-neutral-700">
                <WhatsAppSection
                    wabas={wabas}
                    webhookGlobalUrl={whatsappWebhookGlobalUrl}
                    channelAccountsByWaba={channelAccountsByWaba ?? {}}
                    chatbots={chatbots}
                    showForm
                    setShowForm={(show) => { if (!show) closeDrawer(); }}
                    metaConfigIdWhatsapp={metaConfigIdWhatsapp}
                    metaAppId={metaAppId}
                    onboardingOnly
                />
            </ConnectDrawer>

            {/* Connect Instagram drawer */}
            <ConnectDrawer open={drawer === 'instagram'} onClose={closeDrawer}
                title={t('inbox.connect_instagram')}
                icon={InstagramLogo}
                iconBg="bg-white dark:bg-neutral-800 shadow-sm border border-neutral-100 dark:border-neutral-700">
                <AddInstagramForm onSuccess={closeDrawer} metaConfigIdSocial={metaConfigIdSocial} metaAppId={metaAppId} metaConfigIdWhatsapp={metaConfigIdWhatsapp} />
            </ConnectDrawer>

            {/* Connect Messenger drawer */}
            <ConnectDrawer open={drawer === 'messenger'} onClose={closeDrawer}
                title={t('inbox.connect_messenger')}
                icon={MessengerLogo}
                iconBg="bg-white dark:bg-neutral-800 shadow-sm border border-neutral-100 dark:border-neutral-700">
                <AddMessengerForm onSuccess={closeDrawer} metaConfigIdSocial={metaConfigIdSocial} metaAppId={metaAppId} metaConfigIdWhatsapp={metaConfigIdWhatsapp} />
            </ConnectDrawer>
        </ClientLayout>
    );
}
