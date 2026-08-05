import { useEffect } from 'react';
import { router } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Head } from '@inertiajs/react';

/**
 * Defensive client-side fallback. The server redirects /chat-widgets to
 * Appearance on every visit, but if a stale cache ever lands here we
 * forward the visitor to the right place.
 */
export default function ChatWidgetIndex() {
    useEffect(() => {
        router.visit(route('client.inbox.chat-widgets.settings'), { replace: true });
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