import { Head, useForm, usePage } from '@inertiajs/react';
import { Building2, User } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import { SocialBrandIcon } from '@/Components/BrandIcons';

/**
 * Shown right after LinkedIn authorization. The member's own profile and every
 * Company Page they administer are offered, so a client posting for a business
 * never ends up publishing to a personal feed by accident.
 */
function Row({ checked, onChange, icon, title, subtitle, picture }) {
    return (
        <label className="flex cursor-pointer items-center gap-3 rounded-xl border border-neutral-200 bg-white p-3.5 transition hover:border-brand-400 dark:border-neutral-700 dark:bg-neutral-900">
            <input
                type="checkbox"
                checked={checked}
                onChange={onChange}
                className="h-4 w-4 shrink-0 rounded border-neutral-300 text-brand-600 focus:ring-brand-500/40 dark:border-neutral-600"
            />
            {picture ? (
                <img src={picture} alt="" className="h-9 w-9 shrink-0 rounded-full object-cover" />
            ) : (
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-neutral-500 dark:bg-neutral-800">{icon}</span>
            )}
            <span className="min-w-0">
                <span className="block truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">{title}</span>
                <span className="block truncate text-xs text-neutral-500 dark:text-neutral-400">{subtitle}</span>
            </span>
        </label>
    );
}

export default function LinkedInTargets({ member = null, organizations = [] }) {
    const { t } = useTranslation();
    const flash = usePage().props.flash ?? {};
    const { data, setData, post, processing } = useForm({
        connect_member: Boolean(member) && organizations.length === 0,
        organization_ids: organizations.map((organization) => String(organization.id)),
    });

    const toggleOrganization = (id) => {
        const value = String(id);
        setData('organization_ids', data.organization_ids.includes(value)
            ? data.organization_ids.filter((current) => current !== value)
            : [...data.organization_ids, value]);
    };

    const selectedCount = data.organization_ids.length + (data.connect_member ? 1 : 0);

    const submit = (e) => {
        e.preventDefault();
        post(route('client.social.accounts.linkedin.store'));
    };

    return (
        <ClientLayout title={t('social.linkedin_choose_title')}>
            <Head title={t('social.linkedin_choose_title')} />
            <form onSubmit={submit} className="mx-auto max-w-2xl space-y-5">
                <div className="flex items-start gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-neutral-50 dark:bg-neutral-800">
                        <SocialBrandIcon network="linkedin" className="h-5 w-5" />
                    </span>
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('social.linkedin_choose_title')}</h2>
                        <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                            {member ? t('social.linkedin_choose_subtitle') : t('social.linkedin_choose_subtitle_pages')}
                        </p>
                    </div>
                </div>

                {flash.error && (
                    <div className="rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200">{flash.error}</div>
                )}

                {organizations.length > 0 && (
                    <div className="space-y-2">
                        <p className="text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{t('social.linkedin_company_pages')}</p>
                        {organizations.map((organization) => (
                            <Row
                                key={organization.id}
                                checked={data.organization_ids.includes(String(organization.id))}
                                onChange={() => toggleOrganization(organization.id)}
                                icon={<Building2 className="h-4 w-4" />}
                                title={organization.name}
                                subtitle={organization.vanity_name ? `linkedin.com/company/${organization.vanity_name}` : t('social.linkedin_page')}
                                picture={organization.picture_url}
                            />
                        ))}
                    </div>
                )}

                {member && (
                    <div className="space-y-2">
                        <p className="text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{t('social.linkedin_personal')}</p>
                        <Row
                            checked={data.connect_member}
                            onChange={() => setData('connect_member', !data.connect_member)}
                            icon={<User className="h-4 w-4" />}
                            title={member.name || t('social.linkedin_personal')}
                            subtitle={t('social.linkedin_personal_hint')}
                            picture={member.picture_url}
                        />
                    </div>
                )}

                <div className="flex items-center justify-between gap-3 border-t border-neutral-100 pt-4 dark:border-neutral-800">
                    <span className="text-xs text-neutral-500 dark:text-neutral-400">{t('social.linkedin_selected_count', { count: selectedCount })}</span>
                    <button
                        type="submit"
                        disabled={processing || selectedCount === 0}
                        className="rounded-lg bg-brand-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-brand-700 disabled:opacity-60"
                    >
                        {processing ? t('ai.saving') : t('social.linkedin_connect_selected')}
                    </button>
                </div>
            </form>
        </ClientLayout>
    );
}
