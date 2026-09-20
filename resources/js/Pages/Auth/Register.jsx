import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Input, Checkbox } from '@/Components/ui';
import { Head, Link, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { ArrowUpRight, CheckCircle, UserPlus } from 'lucide-react';
import { browserTz } from '@/Utils/datetime';

export default function Register({ plan_id = null, cycle = 'month', selected_plan = null, signup_available = true }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({
        name:                  '',
        email:                 '',
        password:              '',
        password_confirmation: '',
        agree_terms:           false,
        plan_id:               plan_id ? String(plan_id) : '',
        cycle:                 cycle,
        timezone:              browserTz() || 'Asia/Dhaka',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    const money = (cents, currency = 'USD') => {
        if (cents === null || cents === undefined) return 'Unavailable';
        if (Number(cents) === 0) return 'Free';
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency || 'USD',
            maximumFractionDigits: Number(cents) % 100 === 0 ? 0 : 2,
        }).format(Number(cents) / 100);
    };

    return (
        <AuthLayout
            eyebrow={t('auth.start_free', { defaultValue: 'Start free' })}
            title={t('auth.register') || 'Create an account'}
            subtitle={t('auth.register_subtitle', { defaultValue: 'Create your workspace now. Connect channels and invite your team after sign-up.' })}
            contentClassName="max-w-lg"
        >
            <Head title={t('auth.register') || 'Register'} />

            <form onSubmit={submit} className="space-y-4">
                <div className={`rounded-2xl border px-4 py-3.5 ${selected_plan && !selected_plan.is_free ? 'border-brand-200 bg-brand-50/70 dark:border-brand-800 dark:bg-brand-950/25' : 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-800 dark:bg-emerald-950/25'}`}>
                    <div className="flex items-start gap-2.5">
                        <CheckCircle className={`mt-0.5 h-4 w-4 shrink-0 ${selected_plan && !selected_plan.is_free ? 'text-brand-600' : 'text-emerald-600'}`} />
                        <div className="min-w-0 flex-1">
                            {selected_plan && !selected_plan.is_free ? (
                                <>
                                    <p className="text-sm font-semibold text-neutral-900 dark:text-white">
                                        {selected_plan.name} selected · {money(selected_plan.price_cents, selected_plan.currency)} / {data.cycle}
                                    </p>
                                    <p className="mt-0.5 text-xs leading-relaxed text-neutral-600 dark:text-neutral-400">
                                        Create your workspace first. You’ll continue to secure checkout next, while Free access stays available if you decide later.
                                    </p>
                                </>
                            ) : (
                                <>
                                    <p className="text-sm font-semibold text-neutral-900 dark:text-white">Start on Free — no card required</p>
                                    <p className="mt-0.5 text-xs text-neutral-600 dark:text-neutral-400">Your workspace and its included monthly AI credits activate immediately.</p>
                                </>
                            )}
                        </div>
                        {selected_plan && (
                            <Link href={route('pricing')} className="shrink-0 text-xs font-semibold text-brand-700 hover:underline dark:text-brand-400">
                                Change
                            </Link>
                        )}
                    </div>
                </div>

                {!signup_available && (
                    <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                        Account creation is temporarily unavailable. Please contact support.
                    </div>
                )}
                {errors.plan_id && <p className="text-xs text-coral-600">{errors.plan_id}</p>}
                {errors.cycle && <p className="text-xs text-coral-600">{errors.cycle}</p>}

                <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                        id="name"
                        name="name"
                        label={t('auth.name') || 'Full name'}
                        value={data.name}
                        autoComplete="name"
                        autoFocus
                        placeholder={t('auth.name_placeholder', { defaultValue: 'Your name' })}
                        className="min-h-12 !rounded-xl px-4"
                        onChange={(e) => setData('name', e.target.value)}
                        error={errors.name}
                        required
                    />

                    <Input
                        id="email"
                        type="email"
                        name="email"
                        label={t('auth.email') || 'Email address'}
                        value={data.email}
                        autoComplete="username"
                        placeholder="you@company.com"
                        className="min-h-12 !rounded-xl px-4"
                        onChange={(e) => setData('email', e.target.value)}
                        error={errors.email}
                        required
                    />

                    <Input
                        id="password"
                        type="password"
                        name="password"
                        label={t('auth.password') || 'Password'}
                        value={data.password}
                        autoComplete="new-password"
                        placeholder="••••••••"
                        className="min-h-12 !rounded-xl px-4"
                        onChange={(e) => setData('password', e.target.value)}
                        error={errors.password}
                        required
                    />

                    <Input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        label={t('auth.confirm_password') || 'Confirm password'}
                        value={data.password_confirmation}
                        autoComplete="new-password"
                        placeholder="••••••••"
                        className="min-h-12 !rounded-xl px-4"
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        error={errors.password_confirmation}
                        required
                    />
                </div>

                <Checkbox
                    id="agree_terms"
                    name="agree_terms"
                    checked={data.agree_terms}
                    onChange={(e) => setData('agree_terms', e.target.checked)}
                    error={errors.agree_terms}
                    label={
                        <span>
                            {t('auth.agree_prefix') || 'I agree to the'}{' '}
                            <a
                                href="/p/terms"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="font-medium text-brand-600 dark:text-brand-400 hover:underline"
                            >
                                {t('auth.terms_of_service') || 'Terms & Conditions'}
                            </a>{' '}
                            {t('auth.and') || 'and'}{' '}
                            <a
                                href="/p/privacy"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="font-medium text-brand-600 dark:text-brand-400 hover:underline"
                            >
                                {t('auth.privacy_policy') || 'Privacy Policy'}
                            </a>
                        </span>
                    }
                />

                <Button type="submit" variant="primary" size="lg" className="group min-h-12 w-full !rounded-xl" disabled={processing || !signup_available}>
                    <UserPlus className="mr-2 h-4 w-4" />
                    {processing ? (t('auth.creating_account') || 'Creating account…') : (t('auth.register') || 'Create account')}
                    {!processing && <ArrowUpRight className="ml-1 h-4 w-4 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" aria-hidden="true" />}
                </Button>
            </form>

            <p className="mt-5 text-center text-sm text-neutral-500 dark:text-neutral-400">
                {t('auth.already_registered') || 'Already have an account?'}{' '}
                <Link href={route('login')} className="font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                    {t('auth.log_in') || 'Sign in'}
                </Link>
            </p>
        </AuthLayout>
    );
}
