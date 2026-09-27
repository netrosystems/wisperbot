import { useSyncExternalStore } from 'react';
import { useTranslation } from 'react-i18next';
import {
    acceptMarketingCookies,
    getConsentSnapshot,
    rejectMarketingCookies,
    subscribeConsent,
} from '@/Utils/metaPixel';

/**
 * Marketing-cookie choice for public pages. It renders only where WisperBot's
 * Meta Pixel is configured, so visitors are never asked about cookies that
 * are not in use. Rejecting is always one click, as prominent as accepting.
 */
export default function CookieConsent() {
    const { t } = useTranslation();
    const { visible, optIn, consent } = useSyncExternalStore(subscribeConsent, getConsentSnapshot, getConsentSnapshot);

    if (!visible) return null;

    const body = optIn
        ? t('consent.body_opt_in', {
              defaultValue:
                  'We use essential cookies to run WisperBot. With your permission, we also use Meta advertising cookies to measure our campaigns. You can change your choice anytime.',
          })
        : t('consent.body_notice', {
              defaultValue:
                  'We use essential cookies to run WisperBot and Meta advertising cookies to measure our campaigns. You can turn advertising cookies off anytime.',
          });

    return (
        <div
            role="region"
            aria-label={t('consent.label', { defaultValue: 'Cookie choices' })}
            className="fixed inset-x-4 bottom-4 z-[60] sm:inset-x-auto sm:left-6 sm:bottom-6 sm:max-w-[420px]"
        >
            <div className="rounded-2xl border border-neutral-200 bg-white/95 p-5 text-neutral-700 shadow-[0_24px_60px_rgba(14,17,23,0.16)] backdrop-blur dark:border-neutral-800 dark:bg-neutral-950/95 dark:text-neutral-300">
                <h2 className="text-[15px] font-semibold text-neutral-950 dark:text-white">
                    {t('consent.title', { defaultValue: 'Your privacy, your choice' })}
                </h2>
                <p className="mt-2 text-sm leading-6">
                    {body}{' '}
                    <a
                        href="/p/cookies"
                        className="font-medium text-brand-700 underline decoration-brand-300 underline-offset-2 hover:text-brand-800 dark:text-brand-400"
                    >
                        {t('consent.policy', { defaultValue: 'Cookie Policy' })}
                    </a>
                </p>
                <div className="mt-4 flex flex-wrap gap-2.5">
                    <button
                        type="button"
                        onClick={acceptMarketingCookies}
                        className="inline-flex min-h-10 items-center rounded-full bg-brand-500 px-5 text-sm font-semibold text-neutral-950 transition hover:bg-brand-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
                    >
                        {optIn || consent !== 'granted'
                            ? t('consent.accept', { defaultValue: 'Accept' })
                            : t('consent.acknowledge', { defaultValue: 'Got it' })}
                    </button>
                    <button
                        type="button"
                        onClick={rejectMarketingCookies}
                        className="inline-flex min-h-10 items-center rounded-full border border-neutral-300 bg-white px-5 text-sm font-semibold text-neutral-900 transition hover:bg-neutral-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 dark:border-neutral-700 dark:bg-transparent dark:text-white dark:hover:bg-neutral-900"
                    >
                        {optIn
                            ? t('consent.reject', { defaultValue: 'Reject' })
                            : t('consent.turn_off', { defaultValue: 'Turn off' })}
                    </button>
                </div>
            </div>
        </div>
    );
}
