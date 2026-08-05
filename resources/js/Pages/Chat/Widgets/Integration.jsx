import { Head, Link } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Code2, MessageCircle, Plus, ShieldCheck, Smartphone, MonitorSmartphone } from 'lucide-react';
import InstallCard from './Partials/InstallCard';
import IdentityCard from './Partials/IdentityCard';

export default function ChatWidgetIntegration({ widget = null, embedBase }) {
    return (
        <ClientLayout title="Widget integrations">
            <Head title="Widget integrations" />
            <div className="mx-auto max-w-5xl space-y-6">
                <header>
                    <div className="flex items-center gap-2 text-xs font-semibold text-brand-600"><Code2 className="h-4 w-4" /> CHATBOT WIDGET</div>
                    <h1 className="mt-2 text-2xl font-bold text-neutral-900 dark:text-white">Integrations</h1>
                    <p className="mt-1 max-w-3xl text-sm text-neutral-500">Use one snippet for every visitor, then optionally identify a signed-in customer from your own app. This follows the same trusted identity pattern used by leading live-chat platforms.</p>
                </header>

                <IntegrationOverview />

                {widget ? (
                    <section className="space-y-4 rounded-2xl border border-neutral-200 bg-neutral-50/50 p-5 dark:border-neutral-800 dark:bg-neutral-950/30">
                        <div className="flex items-center gap-3"><span className="flex h-9 w-9 items-center justify-center rounded-full bg-brand-500/10 text-brand-600"><MessageCircle className="h-4 w-4" /></span><div><h2 className="font-semibold text-neutral-900 dark:text-white">{widget.name || 'Website chat widget'}</h2><p className="text-xs text-neutral-500">Use this code only on the website assigned to this widget.</p></div></div>
                        <InstallCard embedBase={embedBase} widgetKey={widget.widget_key} />
                        <IdentityCard embedBase={embedBase} widgetKey={widget.widget_key} identitySecret={widget.identity_secret} verification={widget.identity_verification} />
                    </section>
                ) : <EmptyState />}
            </div>
        </ClientLayout>
    );
}

function IntegrationOverview() {
    return (
        <section className="rounded-2xl border border-brand-200 bg-brand-50/50 p-5 dark:border-brand-900/60 dark:bg-brand-950/20">
            <div className="flex gap-3">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white"><ShieldCheck className="h-5 w-5" /></span>
                <div>
                    <h2 className="font-semibold text-neutral-900 dark:text-white">One integration for anonymous and signed-in visitors</h2>
                    <p className="mt-1 text-sm leading-6 text-neutral-600 dark:text-neutral-300">Install the widget once on every page. Visitors who are not signed in get a private anonymous chat. When your website or app knows who they are, send their identity to WisperBot before loading the widget—or call the SDK after login.</p>
                </div>
            </div>
            <div className="mt-5 grid gap-3 md:grid-cols-3">
                <GuideTile icon={<Code2 className="h-4 w-4" />} title="Website" text="Paste the widget snippet before </body>. No framework or package is required." />
                <GuideTile icon={<MonitorSmartphone className="h-4 w-4" />} title="SPA / customer portal" text="Call WisperBot('identify', data) immediately after the customer signs in." />
                <GuideTile icon={<Smartphone className="h-4 w-4" />} title="Mobile app" text="Use the same external ID and server-signed identity in the WisperBot mobile SDK." />
            </div>
            <p className="mt-4 text-xs leading-5 text-neutral-500 dark:text-neutral-400"><b>Important:</b> WisperBot cannot read another website’s login session by itself. Your website or mobile-app backend decides when a person is signed in and sends only the details you permit.</p>
        </section>
    );
}

function GuideTile({ icon, title, text }) {
    return <div className="rounded-xl border border-white/80 bg-white/75 p-3.5 dark:border-neutral-800 dark:bg-neutral-900/70"><span className="flex h-7 w-7 items-center justify-center rounded-lg bg-brand-500/10 text-brand-600">{icon}</span><h3 className="mt-2 text-sm font-semibold text-neutral-900 dark:text-white">{title}</h3><p className="mt-1 text-xs leading-5 text-neutral-500 dark:text-neutral-400">{text}</p></div>;
}

function EmptyState() {
    return <div className="rounded-2xl border border-dashed border-neutral-300 bg-white px-6 py-14 text-center dark:border-neutral-700 dark:bg-neutral-900"><MessageCircle className="mx-auto h-9 w-9 text-neutral-300" /><h2 className="mt-4 font-semibold text-neutral-800 dark:text-white">No widget to integrate yet</h2><p className="mt-1 text-sm text-neutral-500">Create a widget and its install code will appear here.</p><Link href={route('client.inbox.chat-widgets.create')} className="mt-5 inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700"><Plus className="h-4 w-4" />Create widget</Link></div>;
}
