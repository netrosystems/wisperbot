import { useTranslation } from 'react-i18next';

export default function CommentPlatformAvailability({ platforms = {} }) {
    const { t } = useTranslation();
    if (!Object.keys(platforms).length) return null;
    return <details className="rounded-lg border border-neutral-200 bg-white px-3 py-2 text-xs dark:border-neutral-800 dark:bg-neutral-900">
        <summary className="cursor-pointer font-medium text-neutral-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500 dark:text-neutral-300">
            {t('social.comments_platform_availability', { defaultValue: 'Which platforms support comments?' })}
        </summary>
        <p className="mt-2 text-neutral-500">{t('social.comments_connection_not_permission', { defaultValue: 'Connecting for publishing does not automatically enable comments or AI replies.' })}</p>
        <dl className="mt-3 space-y-3">{Object.entries(platforms).map(([key, platform]) => <div key={key}>
            <dt className="font-semibold text-neutral-800 dark:text-neutral-200">{platform.label} · {t(`social.comments_platform_${key}_summary`, { defaultValue: platform.summary })}</dt>
            <dd className="mt-1 leading-5 text-neutral-500">{t(`social.comments_platform_${key}_detail`, { defaultValue: platform.detail })}</dd>
        </div>)}</dl>
    </details>;
}
