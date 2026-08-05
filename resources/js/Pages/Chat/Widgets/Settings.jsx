import { Head, Link, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { ArrowLeft, Check, Trash2 } from 'lucide-react';
import ChatWidgetForm from './Partials/ChatWidgetForm';
import InstallCard from './Partials/InstallCard';
import IdentityCard from './Partials/IdentityCard';

/**
 * "Appearance" — branding + behaviour for the workspace's single chat widget.
 * Since each workspace now has at most one widget, this page IS the edit page:
 * no listing, no create button. Backend redirects here when the workspace has
 * no widget yet (it falls through to the Integration empty state).
 */
export default function ChatWidgetSettings({
    widget,
    chatbots = [],
    embedBase,
    identitySecret,
    canUseCustomLauncherLogo = false,
}) {
    const flash = usePage().props.flash ?? {};

    const submit = (payload) => {
        router.post(
            route('client.inbox.chat-widgets.update', widget.id),
            { ...payload, _method: 'put' },
            { preserveScroll: true, forceFormData: true },
        );
    };

    const remove = () => {
        if (confirm('Delete this widget? The embed will stop working. Past conversations stay in your inbox.')) {
            router.delete(route('client.inbox.chat-widgets.destroy', widget.id));
        }
    };

    return (
        <ClientLayout title="Widget appearance">
            <Head title="Widget appearance" />
            <div className="space-y-6">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <Link
                            href={route('client.inbox.chat-widgets.integration')}
                            className="inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"
                        >
                            <ArrowLeft className="h-4 w-4" /> Integrations
                        </Link>
                        <h2 className="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                            {widget.name || 'Website chat widget'}
                        </h2>
                        <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                            Brand the chat bubble, choose greeting copy, wire up AI, and decide who can chat.
                        </p>
                    </div>
                    <button
                        onClick={remove}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-700 px-3 py-2 text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition"
                    >
                        <Trash2 className="h-4 w-4" /> Delete widget
                    </button>
                </div>

                {flash.success && (
                    <div className="rounded-lg bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-200 px-4 py-3 text-sm flex items-center gap-2">
                        <Check className="h-4 w-4 flex-shrink-0" /> {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 px-4 py-3 text-sm">
                        {flash.error}
                    </div>
                )}

                <ChatWidgetForm
                    widget={widget}
                    chatbots={chatbots}
                    canUseCustomLauncherLogo={canUseCustomLauncherLogo}
                    submitLabel="Save changes"
                    onSubmit={submit}
                />

                <InstallCard embedBase={embedBase} widgetKey={widget.widget_key} />
                <IdentityCard
                    embedBase={embedBase}
                    widgetKey={widget.widget_key}
                    identitySecret={identitySecret}
                    verification={widget.identity_verification}
                />
            </div>
        </ClientLayout>
    );
}