/**
 * The legacy "edit" page. Backend redirects this URL to Appearance now
 * that Integrations owns the install + identity steps. This stub exists
 * only so Inertia's build pipeline still finds a resolver for direct
 * visits; it should never render.
 */
import { Head } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';

export default function ChatWidgetEdit() {
    return (
        <ClientLayout title="Edit website widget">
            <Head title="Edit website widget" />
            <div className="rounded-2xl border border-dashed border-neutral-300 bg-white px-6 py-14 text-center dark:border-neutral-700 dark:bg-neutral-900">
                <p className="text-sm text-neutral-500">Redirecting to Appearance…</p>
            </div>
        </ClientLayout>
    );
}
