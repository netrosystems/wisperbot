import AdminLayout from '@/Layouts/AdminLayout';
import { Card } from '@/Components/ui';
import { Head } from '@inertiajs/react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function AdminCurrenciesIndex({ currencies = [], flash = {} }) {
    const { t } = useTranslation();
    return (
        <AdminLayout title={t('admin.nav.currencies')}>
            <Head title={`${t('admin.nav.currencies')} · ${t('head.admin')}`} />
            <div className="space-y-6">
                <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('admin.nav.currencies')}</h2>
                {flash?.success && <div className="rounded-soft-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">{flash.success}</div>}
                <Card>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="border-b border-neutral-200 dark:border-neutral-700 text-left text-neutral-500 dark:text-neutral-400">
                                    <th className="pb-2 pr-4 font-medium">{t('admin.col_code')}</th>
                                    <th className="pb-2 pr-4 font-medium">{t('admin.col_symbol')}</th>
                                    <th className="pb-2 pr-4 font-medium">{t('admin.col_decimals')}</th>
                                    <th className="pb-2 pr-4 font-medium">{t('admin.col_exchange_rate')}</th>
                                    <th className="pb-2 pr-4 font-medium">{t('admin.col_default')}</th>
                                    <th className="pb-2 pr-4 font-medium">{t('common.enabled')}</th>
                                    <th className="pb-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {currencies.map((c) => (
                                    <CurrencyRow key={c.code} currency={c} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            </div>
        </AdminLayout>
    );
}

function CurrencyRow({ currency }) {
    const { t } = useTranslation();
    const { data, setData, put, processing } = useForm({
        symbol: currency.symbol ?? '',
        decimals: currency.decimals ?? 2,
        exchange_rate: currency.exchange_rate ?? '',
        is_default: currency.is_default ?? false,
        enabled: currency.enabled ?? true,
    });
    return (
        <tr className="border-b border-neutral-100 dark:border-neutral-800">
            <td className="py-3 pr-4 font-medium text-neutral-900 dark:text-neutral-100">{currency.code}</td>
            <td className="py-3 pr-4"><input value={data.symbol} onChange={(e) => setData('symbol', e.target.value)} className="rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-2 py-1 w-16 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20" /></td>
            <td className="py-3 pr-4"><input type="number" value={data.decimals} onChange={(e) => setData('decimals', parseInt(e.target.value, 10) || 0)} className="rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-2 py-1 w-14 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20" /></td>
            <td className="py-3 pr-4"><input type="number" step="any" value={data.exchange_rate} onChange={(e) => setData('exchange_rate', e.target.value)} className="rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-2 py-1 w-20 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20" /></td>
            <td className="py-3 pr-4"><input type="checkbox" checked={data.is_default} onChange={(e) => setData('is_default', e.target.checked)} className="rounded border-neutral-300 dark:border-neutral-600 text-brand-500" /></td>
            <td className="py-3 pr-4"><input type="checkbox" checked={data.enabled} onChange={(e) => setData('enabled', e.target.checked)} className="rounded border-neutral-300 dark:border-neutral-600 text-brand-500" /></td>
            <td className="py-3"><button type="button" onClick={() => put(route('admin.currencies.update', currency.code))} className="text-brand-600 dark:text-brand-400 text-sm hover:underline font-medium" disabled={processing}>{t('common.save')}</button></td>
        </tr>
    );
}
