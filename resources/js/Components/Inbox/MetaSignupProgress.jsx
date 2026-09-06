import { useTranslation } from 'react-i18next';

export default function MetaSignupProgress({ loading }) {
    const { t } = useTranslation();
    if (!loading) return null;

    return <p role="status" aria-live="polite" className="text-xs leading-5 text-neutral-500 dark:text-neutral-400">
        {t('inbox.meta_setup_inline_progress', { defaultValue: 'Complete authorization in the Meta window. Keep this panel open; setup will continue here automatically.' })}
    </p>;
}
