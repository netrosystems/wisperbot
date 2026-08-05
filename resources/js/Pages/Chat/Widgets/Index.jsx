import { useEffect } from 'react';
import { router } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Head } from '@inertiajs/react';

/**
 * The legacy widget-listing page. With one-widget-per-workspace the listing
 * is no longer meaningful, so the backend now redirects this URL. This
 * component only renders if the redirect ever fails (e.g. cached HTML) and
 * acts as a defensive client-side fallback.
 */
export default function ChatWidgetIndex() {
    useEffect(() => {
        router.visit(route('client.inbox.chat-widgets.integration'), { replace: true });
    }, []);

    return (
        <ClientLayout title="Chat widget">
            <Head title="Chat widget" />
            <div className="flex items-center justify-center py-20 text-sm text-neutral-500">
                Redirecting…
            </div>
        </ClientLayout>
    );
}