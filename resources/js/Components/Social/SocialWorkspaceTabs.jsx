import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { CalendarDays, MessageSquare } from 'lucide-react';

export default function SocialWorkspaceTabs({ active = 'posts' }) {
    const { t } = useTranslation();
    return <nav aria-label={t('social.workspace_navigation', { defaultValue: 'Social media workspace' })} className="flex gap-5 border-b border-neutral-200 dark:border-neutral-800">
        {[
            ['posts', 'client.social.automation.index', CalendarDays, 'Posts'],
            ['comments', 'client.social.comments.index', MessageSquare, 'Comments'],
        ].map(([key, target, Icon, label]) => <Link key={key} href={route(target)} aria-current={active === key ? 'page' : undefined}
            className={`inline-flex items-center gap-2 border-b-2 px-1 py-2.5 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500 ${active === key ? 'border-brand-500 text-brand-600' : 'border-transparent text-neutral-500 hover:text-neutral-900 dark:hover:text-white'}`}>
            <Icon className="h-4 w-4" aria-hidden="true" />{t(`social.workspace_${key}`, { defaultValue: label })}
        </Link>)}
    </nav>;
}
