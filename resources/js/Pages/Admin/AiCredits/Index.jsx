import AdminLayout from '@/Layouts/AdminLayout';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, Bot, Coins, DollarSign, Gauge } from 'lucide-react';

const number = new Intl.NumberFormat();
const money = (microUsd = 0) => new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 4,
}).format(Number(microUsd) / 1_000_000);

function Metric({ label, value, icon: Icon }) {
    return (
        <div className="rounded-soft-lg border border-neutral-200 bg-white p-5 shadow-soft dark:border-neutral-800 dark:bg-neutral-900">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm text-neutral-500 dark:text-neutral-400">{label}</p>
                <Icon className="h-5 w-5 text-primary-500" aria-hidden="true" />
            </div>
            <p className="mt-2 text-2xl font-semibold tabular-nums text-neutral-900 dark:text-white">{value}</p>
        </div>
    );
}

function AdjustmentForm({ periodId }) {
    const form = useForm({ credits: '', reason: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('admin.ai-credits.adjust', periodId), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={submit} className="mt-3 grid gap-2 border-t border-neutral-100 pt-3 dark:border-neutral-800 sm:grid-cols-[110px_1fr_auto]">
            <label className="sr-only" htmlFor={`credits-${periodId}`}>Credit adjustment</label>
            <input
                id={`credits-${periodId}`}
                type="number"
                min="-1000000"
                max="1000000"
                required
                placeholder="± credits"
                value={form.data.credits}
                onChange={(event) => form.setData('credits', event.target.value)}
                className="rounded-soft border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950"
            />
            <label className="sr-only" htmlFor={`reason-${periodId}`}>Adjustment reason</label>
            <input
                id={`reason-${periodId}`}
                required
                minLength="5"
                maxLength="500"
                placeholder="Audit reason (required)"
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
                className="rounded-soft border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950"
            />
            <button
                type="submit"
                disabled={form.processing}
                className="rounded-soft bg-primary-500 px-4 py-2 text-sm font-medium text-white hover:bg-primary-600 disabled:opacity-50"
            >
                {form.processing ? 'Saving…' : 'Adjust'}
            </button>
            {form.hasErrors && <p className="text-xs text-red-600 sm:col-span-3">{Object.values(form.errors)[0]}</p>}
        </form>
    );
}

export default function AiCreditReport({ report, filters }) {
    const succeeded = report.statuses
        .filter((row) => row.status === 'succeeded')
        .reduce((sum, row) => sum + Number(row.actions || 0), 0);
    const totalCredits = report.by_feature.reduce((sum, row) => sum + Number(row.credits || 0), 0);
    const totalCost = report.by_model.reduce((sum, row) => sum + Number(row.cost_microusd || 0), 0);
    const totalMargin = report.by_plan.reduce((sum, row) => sum + Number(row.estimated_gross_margin_microusd || 0), 0);

    const applyRange = (event) => {
        event.preventDefault();
        const values = new FormData(event.currentTarget);
        router.get(route('admin.ai-credits.report'), {
            from: values.get('from'),
            to: values.get('to'),
        }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AdminLayout title="AI Credits">
            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <div>
                        <h1 className="text-2xl font-semibold text-neutral-900 dark:text-white">Managed AI credit operations</h1>
                        <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Usage, estimated provider cost, margin, and audited account adjustments.</p>
                    </div>
                    <form onSubmit={applyRange} className="flex flex-wrap items-end gap-2">
                        <label className="text-xs text-neutral-500">From<input name="from" type="date" defaultValue={filters.from} className="mt-1 block rounded-soft border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></label>
                        <label className="text-xs text-neutral-500">To<input name="to" type="date" defaultValue={filters.to} className="mt-1 block rounded-soft border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></label>
                        <button className="rounded-soft border border-neutral-200 bg-white px-4 py-2 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800">Apply</button>
                    </form>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric label="Succeeded actions" value={number.format(succeeded)} icon={Bot} />
                    <Metric label="Credits consumed" value={number.format(totalCredits)} icon={Coins} />
                    <Metric label="Estimated provider cost" value={money(totalCost)} icon={Gauge} />
                    <Metric label="Estimated gross margin" value={money(totalMargin)} icon={DollarSign} />
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section className="rounded-soft-lg border border-neutral-200 bg-white p-5 shadow-soft dark:border-neutral-800 dark:bg-neutral-900">
                        <h2 className="font-semibold text-neutral-900 dark:text-white">Usage by feature</h2>
                        <div className="mt-4 overflow-x-auto"><table className="w-full text-left text-sm"><thead className="text-xs uppercase text-neutral-400"><tr><th className="pb-2">Feature</th><th className="pb-2 text-right">Actions</th><th className="pb-2 text-right">Credits</th><th className="pb-2 text-right">Cost</th></tr></thead><tbody>{report.by_feature.map((row) => <tr key={row.feature} className="border-t border-neutral-100 dark:border-neutral-800"><td className="py-3 font-medium">{row.feature.replaceAll('_', ' ')}</td><td className="py-3 text-right tabular-nums">{number.format(row.actions)}</td><td className="py-3 text-right tabular-nums">{number.format(row.credits)}</td><td className="py-3 text-right tabular-nums">{money(row.cost_microusd)}</td></tr>)}</tbody></table></div>
                    </section>
                    <section className="rounded-soft-lg border border-neutral-200 bg-white p-5 shadow-soft dark:border-neutral-800 dark:bg-neutral-900">
                        <h2 className="font-semibold text-neutral-900 dark:text-white">Economics by plan</h2>
                        <div className="mt-4 overflow-x-auto"><table className="w-full text-left text-sm"><thead className="text-xs uppercase text-neutral-400"><tr><th className="pb-2">Plan</th><th className="pb-2 text-right">Accounts</th><th className="pb-2 text-right">Used</th><th className="pb-2 text-right">Cost</th><th className="pb-2 text-right">Margin</th></tr></thead><tbody>{report.by_plan.map((row) => <tr key={row.plan_id ?? row.plan_name} className="border-t border-neutral-100 dark:border-neutral-800"><td className="py-3 font-medium">{row.plan_name}</td><td className="py-3 text-right tabular-nums">{number.format(row.accounts)}</td><td className="py-3 text-right tabular-nums">{number.format(row.used_credits)}</td><td className="py-3 text-right tabular-nums">{money(row.estimated_cost_microusd)}</td><td className="py-3 text-right tabular-nums">{money(row.estimated_gross_margin_microusd)}</td></tr>)}</tbody></table></div>
                    </section>
                </div>

                {report.suspicious_device_clusters.length > 0 && <section className="rounded-soft-lg border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950/30"><div className="flex gap-3"><AlertTriangle className="h-5 w-5 text-amber-600" /><div><h2 className="font-semibold text-amber-900 dark:text-amber-200">Possible cross-workspace device sharing</h2><p className="mt-1 text-sm text-amber-700 dark:text-amber-300">{report.suspicious_device_clusters.length} hashed device cluster(s) appeared across more than two workspaces. Investigate before taking action; fingerprints are signals, not proof.</p></div></div></section>}

                <section className="rounded-soft-lg border border-neutral-200 bg-white p-5 shadow-soft dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 className="font-semibold text-neutral-900 dark:text-white">Current and recent credit periods</h2>
                    <p className="mt-1 text-sm text-neutral-500">Adjustments cannot reduce a balance below credits already used or reserved.</p>
                    <div className="mt-4 grid gap-3 lg:grid-cols-2">
                        {report.periods.map((period) => {
                            const total = Number(period.allowance) + Number(period.adjustment_credits);
                            const remaining = Math.max(0, total - Number(period.used_credits) - Number(period.reserved_credits));
                            return <article key={period.id} className="rounded-soft border border-neutral-200 p-4 dark:border-neutral-700"><div className="flex items-start justify-between gap-3"><div><p className="font-medium">{period.account_type} #{period.account_id}</p><p className="text-xs text-neutral-500">{new Date(period.period_start).toLocaleDateString()} – {new Date(period.period_end).toLocaleDateString()}</p></div><span className="rounded-full bg-neutral-100 px-2 py-1 text-xs dark:bg-neutral-800">{period.status}</span></div><div className="mt-3 grid grid-cols-4 gap-2 text-xs"><div><span className="text-neutral-400">Total</span><p className="font-medium tabular-nums">{number.format(total)}</p></div><div><span className="text-neutral-400">Used</span><p className="font-medium tabular-nums">{number.format(period.used_credits)}</p></div><div><span className="text-neutral-400">Reserved</span><p className="font-medium tabular-nums">{number.format(period.reserved_credits)}</p></div><div><span className="text-neutral-400">Remaining</span><p className="font-medium tabular-nums">{number.format(remaining)}</p></div></div><AdjustmentForm periodId={period.id} /></article>;
                        })}
                    </div>
                </section>
            </div>
        </AdminLayout>
    );
}
