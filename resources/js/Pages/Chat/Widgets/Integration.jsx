import { Head, Link } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Code2, MessageCircle, Plus } from 'lucide-react';
import InstallCard from './Partials/InstallCard';
import IdentityCard from './Partials/IdentityCard';

export default function ChatWidgetIntegration({ widgets = [], embedBase }) {
    return (
        <ClientLayout title="App integration">
            <Head title="Widget app integration" />
            <div className="mx-auto max-w-5xl space-y-6">
                <header>
                    <div className="flex items-center gap-2 text-xs font-semibold text-brand-600"><Code2 className="h-4 w-4" /> CHATBOT WIDGET</div>
                    <h1 className="mt-2 text-2xl font-bold text-neutral-900 dark:text-white">App integration</h1>
                    <p className="mt-1 max-w-3xl text-sm text-neutral-500">Install each widget on its website. Optionally pass authenticated visitor details so your agents can recognise logged-in customers.</p>
                </header>

                {widgets.length ? widgets.map((widget) => (
                    <section key={widget.id} className="space-y-4 rounded-2xl border border-neutral-200 bg-neutral-50/50 p-5 dark:border-neutral-800 dark:bg-neutral-950/30">
                        <div className="flex items-center gap-3"><span className="flex h-9 w-9 items-center justify-center rounded-full bg-brand-500/10 text-brand-600"><MessageCircle className="h-4 w-4" /></span><div><h2 className="font-semibold text-neutral-900 dark:text-white">{widget.name || 'Website chat widget'}</h2><p className="text-xs text-neutral-500">Use this code only on the website assigned to this widget.</p></div></div>
                        <InstallCard embedBase={embedBase} widgetKey={widget.widget_key} />
                        <IdentityCard embedBase={embedBase} widgetKey={widget.widget_key} identitySecret={widget.identity_secret} verification={widget.identity_verification} />
                    </section>
                )) : <EmptyState />}
            </div>
        </ClientLayout>
    );
}

function EmptyState() {
    return <div className="rounded-2xl border border-dashed border-neutral-300 bg-white px-6 py-14 text-center dark:border-neutral-700 dark:bg-neutral-900"><MessageCircle className="mx-auto h-9 w-9 text-neutral-300" /><h2 className="mt-4 font-semibold text-neutral-800 dark:text-white">No widget to integrate yet</h2><p className="mt-1 text-sm text-neutral-500">Create a widget and its install code will appear here.</p><Link href={route('client.inbox.chat-widgets.create')} className="mt-5 inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700"><Plus className="h-4 w-4" />Create widget</Link></div>;
}
