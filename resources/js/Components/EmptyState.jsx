import { Link } from '@inertiajs/react';

/**
 * Shared empty-state component.
 *
 * Props:
 *   icon        – React element (SVG / lucide icon).  Optional.
 *   title       – Heading text.
 *   description – Supporting paragraph text.  Optional.
 *   action      – { label, href?, onClick?, method? }  Optional primary CTA.
 *   secondaryAction – same shape, optional secondary CTA.
 */
export default function EmptyState({ icon, title, description, action, secondaryAction }) {
    return (
        <div className="flex flex-col items-center justify-center px-6 py-14 text-center">
            {icon && (
                <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-soft-lg border border-brand-100 bg-brand-50 text-brand-600 dark:border-brand-900/60 dark:bg-brand-950/40 dark:text-brand-400">
                    {icon}
                </div>
            )}
            <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100 mb-1">
                {title}
            </h3>
            {description && (
                <p className="text-sm text-neutral-500 dark:text-neutral-400 max-w-sm mb-6">
                    {description}
                </p>
            )}
            {(action || secondaryAction) && (
                <div className="flex flex-wrap items-center justify-center gap-3">
                    {action && (
                        action.href ? (
                            <Link
                                href={action.href}
                                method={action.method}
                className="inline-flex min-h-10 items-center gap-2 rounded-soft bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-soft transition-colors hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                            >
                                {action.label}
                            </Link>
                        ) : (
                            <button
                                type="button"
                                onClick={action.onClick}
                                className="inline-flex min-h-10 items-center gap-2 rounded-soft bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-soft transition-colors hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                            >
                                {action.label}
                            </button>
                        )
                    )}
                    {secondaryAction && (
                        secondaryAction.href ? (
                            <Link
                                href={secondaryAction.href}
                                className="inline-flex min-h-10 items-center gap-2 rounded-soft border border-neutral-200 bg-white px-4 py-2 text-sm font-semibold text-neutral-700 transition-colors hover:bg-neutral-50 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            >
                                {secondaryAction.label}
                            </Link>
                        ) : (
                            <button
                                type="button"
                                onClick={secondaryAction.onClick}
                                className="inline-flex min-h-10 items-center gap-2 rounded-soft border border-neutral-200 bg-white px-4 py-2 text-sm font-semibold text-neutral-700 transition-colors hover:bg-neutral-50 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            >
                                {secondaryAction.label}
                            </button>
                        )
                    )}
                </div>
            )}
        </div>
    );
}
