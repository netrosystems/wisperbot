import { useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * Text input with soft border and subtle focus ring.
 *
 * When `type="password"` we automatically render an Eye/EyeOff toggle button
 * overlaid on the right edge of the input — no extra prop required.
 */
export default function Input({
    type = 'text',
    label,
    error,
    hint,
    className = '',
    id,
    disablePasswordToggle = false,
    ...props
}) {
    const { t } = useTranslation();
    const isPassword = type === 'password' && !disablePasswordToggle;
    const [visible, setVisible] = useState(false);
    const inputId = id || props.name;
    const effectiveType = isPassword ? (visible ? 'text' : 'password') : type;
    return (
        <div className="w-full">
            {label && (
                <label
                    htmlFor={inputId}
                    className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300"
                >
                    {label}
                </label>
            )}
            <div className={isPassword ? 'relative' : undefined}>
                <input
                    id={inputId}
                    type={effectiveType}
                    className={[
                        'w-full rounded-soft border bg-white dark:bg-neutral-800 px-3 py-2 text-neutral-900 dark:text-neutral-100 shadow-inner transition duration-150 placeholder:text-neutral-400 dark:placeholder:text-neutral-500 focus:outline-none focus:ring-2',
                        // border-soft's color utility outranks border-red-500 in the
                        // compiled CSS, so it must be omitted entirely on error.
                        error
                            ? 'border-red-500 focus:border-red-500 focus:ring-red-500/20'
                            : 'border-soft border-neutral-300 dark:border-neutral-600 focus:border-brand-500 focus:ring-brand-500/20',
                        isPassword ? 'pr-10' : '',
                        className,
                    ].filter(Boolean).join(' ')}
                    {...props}
                />
                {isPassword && (
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
                )}
            </div>
            {error && (
                <p className="mt-1.5 text-sm text-red-500 dark:text-red-400">{error}</p>
            )}
            {hint && !error && (
                <p className="mt-1.5 text-sm text-neutral-500 dark:text-neutral-400">{hint}</p>
            )}
        </div>
    );
}
