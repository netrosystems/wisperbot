import { useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * Bare password input with an Eye/EyeOff visibility toggle on the right edge.
 * Use this in admin/client pages where the layout is hand-rolled (grids,
 * inline error messages, etc.) and the shared `Input` primitive doesn't fit.
 *
 * Forward all extra props to the underlying `<input>`.
 */
export default function PasswordInput({ className = '', ...props }) {
    const { t } = useTranslation();
    const [visible, setVisible] = useState(false);
    return (
        <div className="relative">
            <input
                {...props}
                type={visible ? 'text' : 'password'}
                className={[
                    'w-full rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 pr-10 text-sm',
                    className,
                ].filter(Boolean).join(' ')}
            />
            <button
                type="button"
                onClick={() => setVisible((v) => !v)}
                tabIndex={-1}
                aria-label={
                    visible
                        ? (t('auth.toggle_password_hide') || 'Hide password')
                        : (t('auth.toggle_password_show') || 'Show password')
                }
                aria-pressed={visible}
                className="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200 transition"
            >
                {visible
                    ? <EyeOff className="h-4 w-4" aria-hidden="true" />
                    : <Eye className="h-4 w-4" aria-hidden="true" />}
            </button>
        </div>
    );
}
