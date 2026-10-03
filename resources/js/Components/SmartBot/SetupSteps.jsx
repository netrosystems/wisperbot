import { Check } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * The Smart Bot setup bar: numbered steps, ticked when done, each one a button
 * that opens its panel. Below `sm` the titles would not fit, so it shows
 * "Step 2 of 5" with the current title over a segmented bar.
 */
export default function SetupSteps({ steps, current, onSelect, disabled = false }) {
    const { t } = useTranslation();
    const currentIndex = Math.max(0, steps.findIndex(step => step.id === current));

    return (
        <nav aria-label={t('smart_bot.setup_steps')}>
            <div className="flex items-baseline justify-between sm:hidden">
                <p className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{steps[currentIndex]?.title}</p>
                <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('smart_bot.step_of', { current: currentIndex + 1, total: steps.length })}</p>
            </div>
            <div className="mt-2 flex gap-1 sm:hidden">
                {steps.map((step, index) => (
                    <button
                        key={step.id}
                        type="button"
                        disabled={disabled}
                        onClick={() => onSelect(step.id)}
                        aria-label={`${index + 1}. ${step.title}`}
                        aria-current={current === step.id ? 'step' : undefined}
                        className={`h-1.5 flex-1 rounded-full transition disabled:cursor-default ${step.done ? 'bg-brand-500' : current === step.id ? 'bg-brand-300 dark:bg-brand-700' : 'bg-neutral-200 dark:bg-neutral-800'}`}
                    />
                ))}
            </div>

            <ol className="hidden items-start sm:flex">
                {steps.map((step, index) => {
                    const active = current === step.id;

                    return (
                        <li key={step.id} className="flex min-w-0 flex-1 items-start last:flex-none">
                            <button
                                type="button"
                                disabled={disabled}
                                onClick={() => onSelect(step.id)}
                                aria-current={active ? 'step' : undefined}
                                className="group flex min-w-0 flex-col items-center gap-1.5 px-1 focus:outline-none disabled:cursor-default"
                            >
                                <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full border text-xs font-bold transition group-focus-visible:ring-2 group-focus-visible:ring-brand-500/40 ${step.done
                                    ? 'border-brand-600 bg-brand-600 text-white'
                                    : active
                                        ? 'border-brand-500 bg-white text-brand-700 dark:bg-neutral-900 dark:text-brand-300'
                                        : 'border-neutral-300 bg-white text-neutral-400 dark:border-neutral-700 dark:bg-neutral-900'}`}>
                                    {step.done ? <Check className="h-4 w-4" aria-hidden /> : index + 1}
                                </span>
                                <span className={`max-w-[9rem] truncate text-center text-xs ${active ? 'font-semibold text-neutral-900 dark:text-neutral-100' : 'text-neutral-500 dark:text-neutral-400'}`}>
                                    {step.title}
                                </span>
                            </button>
                            {index !== steps.length - 1 && (
                                <span aria-hidden className={`mt-4 h-0.5 min-w-4 flex-1 rounded-full ${step.done ? 'bg-brand-500' : 'bg-neutral-200 dark:bg-neutral-800'}`} />
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
