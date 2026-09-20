import { useEffect, useRef, useState } from 'react';
import { Dialog, DialogBackdrop, DialogPanel, DialogTitle, Description } from '@headlessui/react';
import { AlertTriangle } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * In-app confirmation for destructive actions. Replaces window.confirm(),
 * which browsers silently suppress after several prompts (a suppressed
 * confirm returns false, so the button appears to do nothing).
 *
 *   if (!(await confirmDialog({ message: t('x.delete_confirm') }))) return;
 *
 * Resolves true only when the person presses the confirm button. Escape,
 * the backdrop and Cancel all resolve false.
 */
let openDialog = null;

export function confirmDialog({ title, message, confirmLabel, cancelLabel, destructive = true } = {}) {
    // The host is mounted once at the app root; the fallback only covers
    // pages rendered outside it (e.g. isolated tests).
    if (!openDialog) {
        return Promise.resolve(typeof window !== 'undefined' && window.confirm(message ?? title ?? ''));
    }

    return new Promise((resolve) => openDialog({ title, message, confirmLabel, cancelLabel, destructive, resolve }));
}

export function ConfirmDialogHost() {
    const { t } = useTranslation();
    const [request, setRequest] = useState(null);
    const cancelRef = useRef(null);

    useEffect(() => {
        openDialog = (next) => setRequest((current) => {
            // A second request while one is open cancels the first.
            current?.resolve(false);
            return next;
        });
        return () => { openDialog = null; };
    }, []);

    const close = (result) => {
        request?.resolve(result);
        setRequest(null);
    };

    const destructive = request?.destructive !== false;

    return (
        <Dialog open={request !== null} onClose={() => close(false)} initialFocus={cancelRef} className="relative z-[100]">
            <DialogBackdrop transition className="fixed inset-0 bg-neutral-950/40 transition-opacity duration-150 data-[closed]:opacity-0" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel
                    transition
                    className="w-full max-w-sm rounded-2xl border border-neutral-200 bg-white p-5 shadow-xl transition duration-150 data-[closed]:scale-95 data-[closed]:opacity-0 dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <div className="flex items-start gap-3">
                        <span className={`flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full ${destructive ? 'bg-red-50 text-red-600 dark:bg-red-950/50 dark:text-red-400' : 'bg-brand-50 text-brand-600 dark:bg-brand-950/40 dark:text-brand-400'}`}>
                            <AlertTriangle className="h-4 w-4" aria-hidden="true" />
                        </span>
                        <div className="min-w-0">
                            <DialogTitle className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                                {request?.title || t('common.confirm_title', 'Are you sure?')}
                            </DialogTitle>
                            {request?.message && (
                                <Description className="mt-1 text-sm leading-5 text-neutral-600 dark:text-neutral-400">
                                    {request.message}
                                </Description>
                            )}
                        </div>
                    </div>
                    <div className="mt-5 flex justify-end gap-2">
                        <button
                            ref={cancelRef}
                            type="button"
                            onClick={() => close(false)}
                            className="rounded-lg border border-neutral-200 px-4 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                        >
                            {request?.cancelLabel || t('common.cancel', 'Cancel')}
                        </button>
                        <button
                            type="button"
                            onClick={() => close(true)}
                            className={`rounded-lg px-4 py-2 text-sm font-semibold text-white transition focus:outline-none focus-visible:ring-2 ${destructive ? 'bg-red-600 hover:bg-red-700 focus-visible:ring-red-500/40' : 'bg-brand-600 hover:bg-brand-700 focus-visible:ring-brand-500/40'}`}
                        >
                            {request?.confirmLabel || t('common.delete', 'Delete')}
                        </button>
                    </div>
                </DialogPanel>
            </div>
        </Dialog>
    );
}
