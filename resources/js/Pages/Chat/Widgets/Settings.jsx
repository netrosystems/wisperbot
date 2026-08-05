import { Head, Link } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { MessageCircle, Pencil, Plus, Settings as SettingsIcon } from 'lucide-react';

export default function ChatWidgetSettings({ widgets = [] }) {
    return (
        <ClientLayout title="Widget appearance">
            <Head title="Widget appearance" />
            <div className="mx-auto max-w-5xl space-y-6">
                <header>
                    <div className="flex items-center gap-2 text-xs font-semibold text-brand-600"><SettingsIcon className="h-4 w-4" /> CHATBOT WIDGET</div>
                    <h1 className="mt-2 text-2xl font-bold text-neutral-900 dark:text-white">Appearance</h1>
                    <p className="mt-1 text-sm text-neutral-500">Choose a widget to control its brand, launcher, welcome message and visitor experience.</p>
                </header>

                {widgets.length ? (
                    <div className="grid gap-4 md:grid-cols-2">
                        {widgets.map((widget) => (
                            <div key={widget.id} className="rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                                <div className="flex items-start justify-between gap-4">
                                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-white" style={{ background: widget.primary_color || '#ff762e' }}><MessageCircle className="h-5 w-5" /></span>
                                    <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${widget.enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-neutral-100 text-neutral-600'}`}>{widget.enabled ? 'Live' : 'Off'}</span>
                                </div>
                                <h2 className="mt-4 font-semibold text-neutral-900 dark:text-white">{widget.name || widget.title || 'Website chat widget'}</h2>
                                <p className="mt-1 text-sm text-neutral-500">{widget.title || 'Chat with us'}</p>
                                <Link href={route('client.inbox.chat-widgets.edit', widget.id)} className="mt-5 inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700"><Pencil className="h-4 w-4" />Edit appearance</Link>
                            </div>
                        ))}
                    </div>
                ) : (
                    <EmptyState />
                )}
            </div>
        </ClientLayout>
    );
}

function EmptyState() {
    return <div className="rounded-2xl border border-dashed border-neutral-300 bg-white px-6 py-14 text-center dark:border-neutral-700 dark:bg-neutral-900"><MessageCircle className="mx-auto h-9 w-9 text-neutral-300" /><h2 className="mt-4 font-semibold text-neutral-800 dark:text-white">Create a widget first</h2><p className="mt-1 text-sm text-neutral-500">Once created, its appearance and behavior can be tailored here.</p><Link href={route('client.inbox.chat-widgets.create')} className="mt-5 inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700"><Plus className="h-4 w-4" />Create widget</Link></div>;
}
