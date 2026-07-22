import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import SeoHead from '@/Components/SeoHead';
import CookieConsent from '@/Components/CookieConsent';
import { Reveal } from '@/Components/Reveal';
import { FeatureIcon } from '@/Components/LandingIcons';
import { useTranslation } from 'react-i18next';

/*
 * Editorial, warm-cream marketing landing page for WisperBot.
 * Self-contained (its own header + footer) so the shared LandingLayout — and
 * therefore every sub-page — is left untouched. All copy is read from the
 * admin-editable `landing.*` settings. Supporting image areas remain intentional
 * placeholders, while the hero uses the shipped WisperBot landscape artwork.
 */

// ─── Small building blocks ──────────────────────────────────────────────────

const INK = '#241f1a';

function Sparkle({ className = 'h-3.5 w-3.5' }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 2l1.6 5.2L19 9l-5.4 1.8L12 16l-1.6-5.2L5 9l5.4-1.8L12 2z" />
        </svg>
    );
}

function ArrowUpRight({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" d="M7 17L17 7M8 7h9v9" />
        </svg>
    );
}

function Eyebrow({ children }) {
    if (!children) return null;
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full border border-black/5 bg-white/80 px-3.5 py-1.5 text-xs font-semibold text-[#3a332c] shadow-sm backdrop-blur">
            <span className="text-brand-500"><Sparkle /></span>
            {children}
        </span>
    );
}

/** Serif heading that italicises + orange-accents the trailing clause (after the
 *  last comma) or, failing that, the final word — mirroring the sample's style. */
function AccentHeading({ text, className = '' }) {
    const trimmed = (text || '').trim();
    if (!trimmed) return <span className={className} />;
    const ci = trimmed.lastIndexOf(',');
    let head;
    let tail;
    if (ci !== -1 && ci < trimmed.length - 1) {
        head = trimmed.slice(0, ci + 1);
        tail = trimmed.slice(ci + 1).trim();
    } else {
        const parts = trimmed.split(' ');
        tail = parts.pop();
        head = parts.join(' ');
    }
    return (
        <span className={className}>
            {head}
            {head ? ' ' : ''}
            <span className="italic text-brand-500">{tail}</span>
        </span>
    );
}

function DarkButton({ href, children, as = Link, external = false, className = '' }) {
    const cls = `group inline-flex items-center gap-2 rounded-full bg-[#241f1a] px-6 py-3 text-sm font-semibold text-white transition-all duration-200 hover:bg-[#3a332c] hover:-translate-y-0.5 ${className}`;
    const inner = (
        <>
            {children}
            <span className="flex h-6 w-6 items-center justify-center rounded-full bg-white/15 transition-transform duration-200 group-hover:translate-x-0.5 group-hover:-translate-y-0.5">
                <ArrowUpRight className="h-3.5 w-3.5" />
            </span>
        </>
    );
    if (external) return <a href={href} className={cls}>{inner}</a>;
    const Tag = as;
    return <Tag href={href} className={cls}>{inner}</Tag>;
}

function GhostButton({ href, children }) {
    return (
        <Link href={href} className="inline-flex items-center gap-2 rounded-full border border-[#241f1a]/15 bg-transparent px-6 py-3 text-sm font-semibold text-[#241f1a] transition-all duration-200 hover:border-[#241f1a]/40 hover:bg-[#241f1a]/[0.03]">
            {children}
        </Link>
    );
}

/** Warm, intentional image placeholder — replace with a real asset later. */
function Placeholder({ className = '', label = 'Image placeholder', hint = 'Upload later', rounded = 'rounded-3xl' }) {
    return (
        <div className={`relative flex flex-col items-center justify-center gap-2 overflow-hidden border border-dashed border-[#dcc7a8] bg-gradient-to-br from-[#fbf3e6] to-[#f1e2cd] ${rounded} ${className}`}>
            <div className="pointer-events-none absolute inset-0 opacity-50" style={{ backgroundImage: 'radial-gradient(circle at 28% 26%, rgba(255,118,46,0.12), transparent 55%)' }} />
            <svg className="relative h-9 w-9 text-[#c9a878]" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <rect x="3" y="4" width="18" height="16" rx="2.5" />
                <circle cx="8.5" cy="9.5" r="1.6" />
                <path strokeLinecap="round" strokeLinejoin="round" d="M4 17l4.5-4.5a2 2 0 012.8 0L16 17m-1.5-2l1.8-1.8a2 2 0 012.8 0L21 15" />
            </svg>
            <span className="relative text-sm font-semibold text-[#a9895f]">{label}</span>
            <span className="relative text-xs text-[#c0a67e]">{hint}</span>
        </div>
    );
}

function Stars() {
    return (
        <div className="flex gap-0.5 text-brand-500">
            {[0, 1, 2, 3, 4].map((i) => (
                <svg key={i} className="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M9.05 2.93c.3-.92 1.6-.92 1.9 0l1.07 3.29a1 1 0 00.95.69h3.46c.97 0 1.37 1.24.59 1.81l-2.8 2.03a1 1 0 00-.36 1.12l1.07 3.29c.3.92-.75 1.69-1.54 1.12l-2.8-2.03a1 1 0 00-1.18 0l-2.8 2.03c-.78.57-1.83-.2-1.53-1.12l1.07-3.29a1 1 0 00-.36-1.12l-2.8-2.03c-.78-.57-.38-1.81.59-1.81h3.46a1 1 0 00.95-.69L9.05 2.93z" />
                </svg>
            ))}
        </div>
    );
}

function ChannelGlyph({ name, className = 'h-6 w-6' }) {
    const glyphs = {
        whatsapp: <path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.51 5.26l-.999 3.648 3.978-1.607zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.247-.694.247-1.289.173-1.413z" />,
        messenger: <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.654V24l4.088-2.242c1.092.301 2.246.464 3.443.464 6.627 0 12-4.975 12-11.111C24 4.974 18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26L10.732 8.1l3.131 3.259L19.752 8.1l-6.561 6.863z" />,
        instagram: <path d="M12 2.16c3.2 0 3.58.01 4.85.07 3.25.15 4.77 1.69 4.92 4.92.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.15 3.23-1.66 4.77-4.92 4.92-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-3.26-.15-4.77-1.7-4.92-4.92-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85C2.38 3.92 3.9 2.38 7.15 2.23 8.42 2.17 8.8 2.16 12 2.16zM12 0C8.74 0 8.33.01 7.05.07 2.7.27.27 2.69.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.2 4.36 2.62 6.78 6.98 6.98C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c4.35-.2 6.78-2.62 6.98-6.98.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.2-4.35-2.62-6.78-6.98-6.98C15.67.01 15.26 0 12 0zm0 5.84a6.16 6.16 0 100 12.32 6.16 6.16 0 000-12.32zM12 16a4 4 0 110-8 4 4 0 010 8zm6.4-10.85a1.44 1.44 0 100 2.88 1.44 1.44 0 000-2.88z" />,
        sms: <path d="M8 10.5h8M8 14h5m-9 6.5l1.5-3A8.38 8.38 0 013 11.5C3 6.81 7.03 3 12 3s9 3.81 9 8.5-4.03 8.5-9 8.5a9.7 9.7 0 01-3.2-.54L4 20.5z" />,
        email: <path d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />,
        chat: <path d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />,
    };
    const filled = name === 'whatsapp' || name === 'messenger' || name === 'instagram';
    return (
        <svg className={className} viewBox="0 0 24 24" fill={filled ? 'currentColor' : 'none'} stroke={filled ? 'none' : 'currentColor'} strokeWidth={filled ? 0 : 1.6} strokeLinecap="round" strokeLinejoin="round">
            {glyphs[name] || glyphs.chat}
        </svg>
    );
}

// ─── Header ─────────────────────────────────────────────────────────────────

function Header({ auth, landing }) {
    const { t } = useTranslation();
    const { branding } = usePage().props;
    const [open, setOpen] = useState(false);
    const nav = [
        { label: t('nav.features', { defaultValue: 'Features' }), href: '/#features' },
        { label: t('nav.use_cases', { defaultValue: 'Use Cases' }), href: '/use-cases' },
        { label: t('nav.integrations', { defaultValue: 'Integrations' }), href: '/integrations' },
        { label: t('nav.pricing', { defaultValue: 'Pricing' }), href: '/pricing' },
        { label: t('nav.faq', { defaultValue: 'FAQ' }), href: '/faq' },
    ];
    const getStarted = landing['landing.getstarted_label'] || t('welcome.get_started_free', { defaultValue: 'Get started' });
    return (
        <header className="absolute inset-x-0 top-0 z-50 border-b border-black/[0.06] bg-white/70 backdrop-blur-xl">
            <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-6 px-4 sm:px-6 lg:px-8">
                <Link href={route('home')} className="flex items-center">
                    <img src={branding?.logo_url || '/wisperbot-logo-with-title.svg'} alt={branding?.app_name || 'WisperBot'} className="h-7 w-auto max-w-[190px] object-contain" />
                </Link>
                <nav className="hidden items-center gap-1 md:flex">
                    {nav.map((l) => (
                        <Link key={l.href} href={l.href} className="rounded-full px-3.5 py-2 text-sm font-medium text-[#57504a] transition-colors hover:text-[#241f1a]">
                            {l.label}
                        </Link>
                    ))}
                </nav>
                <div className="flex items-center gap-2">
                    {auth?.user ? (
                        <Link href={route('client.dashboard')} className="hidden rounded-full bg-[#241f1a] px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#3a332c] sm:inline-flex">
                            {t('nav.dashboard', { defaultValue: 'Dashboard' })}
                        </Link>
                    ) : (
                        <>
                            <Link href={route('login')} className="hidden px-3.5 py-2 text-sm font-medium text-[#57504a] transition-colors hover:text-[#241f1a] sm:inline-flex">
                                {landing['landing.signin_label'] || t('nav.sign_in', { defaultValue: 'Log in' })}
                            </Link>
                            <Link href={route('register')} className="hidden rounded-full bg-[#241f1a] px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#3a332c] sm:inline-flex">
                                {getStarted}
                            </Link>
                        </>
                    )}
                    <button type="button" onClick={() => setOpen(!open)} className="inline-flex h-10 w-10 items-center justify-center rounded-full text-[#241f1a] md:hidden" aria-label="Menu">
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={1.8} viewBox="0 0 24 24"><path strokeLinecap="round" d={open ? 'M6 18L18 6M6 6l12 12' : 'M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5'} /></svg>
                    </button>
                </div>
            </div>
            {open && (
                <div className="border-t border-black/5 bg-white/70 px-4 py-4 backdrop-blur-xl md:hidden">
                    {nav.map((l) => (
                        <Link key={l.href} href={l.href} onClick={() => setOpen(false)} className="block rounded-xl px-3 py-2.5 text-sm font-medium text-[#57504a]">
                            {l.label}
                        </Link>
                    ))}
                    <div className="mt-2 flex flex-col gap-2 border-t border-black/5 pt-3">
                        <Link href={route('login')} className="rounded-xl px-3 py-2.5 text-sm font-medium text-[#57504a]">{landing['landing.signin_label'] || 'Log in'}</Link>
                        <Link href={route('register')} className="rounded-full bg-[#241f1a] px-3 py-2.5 text-center text-sm font-semibold text-white">{getStarted}</Link>
                    </div>
                </div>
            )}
        </header>
    );
}

// ─── Sections ───────────────────────────────────────────────────────────────

function Hero({ landing, auth, canRegister }) {
    const { t } = useTranslation();
    const s = (k, d = '') => landing[`landing.${k}`] ?? d;
    if (s('hero_enabled') !== '1') return null;
    const stats = [1, 2, 3].map((i) => ({ value: s(`metric_${i}_value`), label: s(`metric_${i}_label`) })).filter((m) => m.value);
    return (
        <section className="relative isolate min-h-[36rem] overflow-hidden bg-[#faf5ec] sm:min-h-[clamp(34rem,45vw,54rem)]">
            <img
                src="/images/wisperbot-hero.png"
                alt=""
                aria-hidden="true"
                className="absolute inset-0 h-full w-full object-cover object-top"
            />

            <div className="relative z-10 mx-auto max-w-4xl px-4 pt-32 pb-16 text-center sm:px-6 sm:pt-40 sm:pb-24 lg:px-8">
                <Reveal className="mb-6 flex justify-center" y={12}><Eyebrow>{s('hero_badge')}</Eyebrow></Reveal>
                <Reveal as="h1" delay={80} className="mx-auto max-w-3xl font-display text-4xl font-semibold leading-[1.05] tracking-tight text-[#241f1a] sm:text-5xl lg:text-6xl">
                    <AccentHeading text={s('hero_title')} />
                </Reveal>
                <Reveal as="p" delay={170} className="mx-auto mt-6 max-w-xl text-base leading-relaxed text-[#6f6660] sm:text-lg">
                    {s('hero_subtitle')}
                </Reveal>
                <Reveal delay={260} className="mt-9 flex flex-wrap items-center justify-center gap-3">
                    {auth?.user ? (
                        <DarkButton href={route('client.dashboard')}>{t('welcome.goToDashboard', { defaultValue: 'Go to dashboard' })}</DarkButton>
                    ) : (
                        <>
                            {canRegister && s('hero_cta_primary') && <DarkButton href={route('register')}>{s('hero_cta_primary')}</DarkButton>}
                            {s('hero_cta_secondary') && <GhostButton href="/#channels">{s('hero_cta_secondary')}</GhostButton>}
                        </>
                    )}
                </Reveal>
                {stats.length > 0 && (
                    <Reveal delay={340} className="mx-auto mt-12 flex max-w-lg items-start justify-center gap-8 sm:gap-14">
                        {stats.map((m, i) => (
                            <div key={i} className="text-center">
                                <p className="font-display text-2xl font-semibold text-[#241f1a] sm:text-3xl">{m.value}</p>
                                <p className="mt-1 text-xs text-[#8a817a] sm:text-sm">{m.label}</p>
                            </div>
                        ))}
                    </Reveal>
                )}
            </div>
        </section>
    );
}

function LogoCloud({ landing }) {
    const s = (k, d = '') => landing[`landing.${k}`] ?? d;
    if (s('stats_enabled') !== '1') return null;
    const brands = [1, 2, 3, 4, 5, 6].map((i) => s(`stats_${i}_label`)).filter(Boolean);
    if (!brands.length) return null;
    return (
        <section className="border-y border-black/[0.06] bg-[#f6efe2] py-10">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <p className="text-center text-xs font-semibold uppercase tracking-[0.2em] text-[#a99a86]">{s('stats_heading', 'Trusted by leading teams worldwide')}</p>
                <div className="mt-7 flex flex-wrap items-center justify-center gap-x-10 gap-y-5">
                    {brands.map((b, i) => (
                        <span key={i} className="font-display text-xl font-medium italic text-[#c3b39c] transition-colors hover:text-[#a99a86]">{b}</span>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Editorial({ landing }) {
    const { t } = useTranslation();
    const s = (k, d = '') => landing[`landing.${k}`] ?? d;
    const statValue = s('metric_4_value', s('about_stat_1_value', '6x'));
    const statLabel = s('metric_4_label', 'More replies than email');
    return (
        <section className="bg-[#faf5ec] py-20 sm:py-28">
            <div className="mx-auto grid max-w-6xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:gap-16 lg:px-8">
                <Reveal>
                    <Eyebrow>{s('why_badge', 'Why WisperBot')}</Eyebrow>
                    <h2 className="mt-6 font-display text-3xl font-medium leading-[1.15] tracking-tight text-[#241f1a] sm:text-4xl">
                        <AccentHeading text={s('why_title', 'Built for support teams that care about results')} />
                    </h2>
                    <p className="mt-5 max-w-md text-base leading-relaxed text-[#6f6660]">
                        {s('solution_desc', s('why_subtitle', ''))}
                    </p>
                    <div className="mt-8">
                        <DarkButton href="/#features">{t('welcome.learn_more', { defaultValue: 'Learn more' })}</DarkButton>
                    </div>
                    <div className="mt-9 flex items-center gap-3">
                        <div className="flex -space-x-2">
                            {['#f6b17a', '#ff9a56', '#e07a3a', '#c9a878'].map((c, i) => (
                                <span key={i} className="h-9 w-9 rounded-full border-2 border-[#faf5ec]" style={{ background: c }} />
                            ))}
                            <span className="flex h-9 w-9 items-center justify-center rounded-full border-2 border-[#faf5ec] bg-brand-500 text-xs font-bold text-white">14k+</span>
                        </div>
                        <p className="max-w-[14rem] text-xs leading-relaxed text-[#8a817a]">Built for modern support teams who value clarity, speed and happy customers.</p>
                    </div>
                </Reveal>

                <Reveal delay={120} className="relative">
                    <Placeholder className="aspect-[4/3] w-full" label="Editorial image" hint="Add a supporting visual" />
                    <div className="absolute -bottom-6 -left-4 w-56 rounded-2xl bg-brand-500 p-6 text-white shadow-xl shadow-brand-500/25 sm:-left-8">
                        <p className="font-display text-4xl font-semibold leading-none">{statValue}</p>
                        <p className="mt-3 text-xs leading-relaxed text-white/90">{statLabel} — resolved faster across every channel.</p>
                    </div>
                </Reveal>
            </div>
        </section>
    );
}

function Channels({ landing }) {
    const s = (k, d = '') => landing[`landing.${k}`] ?? d;
    if (s('channels_enabled') !== '1') return null;
    const channels = [1, 2, 3, 4, 5].map((i) => ({
        key: s(`channel_${i}_key`, 'chat'),
        title: s(`channel_${i}_title`),
        desc: s(`channel_${i}_desc`),
    })).filter((c) => c.title);
    if (!channels.length) return null;
    return (
        <section id="channels" className="scroll-mt-20 bg-[#f6efe2] py-20 sm:py-28">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <Reveal className="mx-auto max-w-2xl text-center">
                    <Eyebrow>{s('channels_badge', 'Omnichannel')}</Eyebrow>
                    <h2 className="mt-6 font-display text-3xl font-medium leading-tight tracking-tight text-[#241f1a] sm:text-4xl">
                        <AccentHeading text={s('channels_title')} />
                    </h2>
                    <p className="mt-4 text-base text-[#6f6660]">{s('channels_subtitle')}</p>
                </Reveal>
                <div className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {channels.map((c, i) => (
                        <Reveal key={i} delay={(i % 3) * 80} className="group rounded-3xl border border-black/[0.06] bg-[#fffdf9] p-7 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-black/[0.04]">
                            <div className="mb-5 inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-500/10 text-brand-600 transition-colors duration-300 group-hover:bg-brand-500 group-hover:text-white">
                                <ChannelGlyph name={c.key} className="h-6 w-6" />
                            </div>
                            <h3 className="font-display text-lg font-medium text-[#241f1a]">{c.title}</h3>
                            <p className="mt-2 text-sm leading-relaxed text-[#6f6660]">{c.desc}</p>
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}

function UseCases({ landing }) {
    const { t } = useTranslation();
    const s = (k, d = '') => landing[`landing.${k}`] ?? d;
    if (s('why_enabled') !== '1') return null;
    const cards = [1, 2, 3].map((i) => ({
        icon: s(`why_${i}_icon`, 'star'),
        title: s(`why_${i}_title`),
        desc: s(`why_${i}_desc`),
    })).filter((c) => c.title);
    if (!cards.length) return null;
    return (
        <section className="bg-[#faf5ec] py-20 sm:py-28">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <Reveal className="flex flex-col items-start justify-between gap-6 sm:flex-row sm:items-end">
                    <div className="max-w-xl">
                        <Eyebrow>{t('nav.use_cases', { defaultValue: 'Use cases' })}</Eyebrow>
                        <h2 className="mt-6 font-display text-3xl font-medium leading-[1.15] tracking-tight text-[#241f1a] sm:text-4xl">
                            <AccentHeading text="Crafted for teams that move with clarity" />
                        </h2>
                    </div>
                    <DarkButton href={route('register')}>{t('welcome.get_started_free', { defaultValue: 'Get started' })}</DarkButton>
                </Reveal>

                <Reveal delay={100} className="mt-12">
                    <Placeholder className="aspect-[16/7] w-full" label="Product dashboard" hint="Add a screenshot of the app" />
                </Reveal>

                <div className="mt-6 grid gap-5 sm:grid-cols-3">
                    {cards.map((c, i) => {
                        const featured = i === 1;
                        return (
                            <Reveal
                                key={i}
                                delay={i * 90}
                                className={`rounded-3xl p-7 transition-all duration-300 ${featured ? 'bg-brand-500 text-white shadow-xl shadow-brand-500/20' : 'border border-black/[0.06] bg-[#fffdf9] hover:-translate-y-1 hover:shadow-lg'}`}
                            >
                                <div className={`mb-5 inline-flex h-11 w-11 items-center justify-center rounded-xl ${featured ? 'bg-white/20 text-white' : 'bg-brand-500/10 text-brand-600'}`}>
                                    <FeatureIcon name={c.icon} className="h-5 w-5" />
                                </div>
                                <h3 className={`font-display text-lg font-medium ${featured ? 'text-white' : 'text-[#241f1a]'}`}>{c.title}</h3>
                                <p className={`mt-2 text-sm leading-relaxed ${featured ? 'text-white/90' : 'text-[#6f6660]'}`}>{c.desc}</p>
                            </Reveal>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

function Process({ landing }) {
    const s = (k, d = '') => landing[`landing.${k}`] ?? d;
    if (s('howitworks_enabled') !== '1') return null;
    const steps = [1, 2, 3].map((i) => ({ title: s(`step_${i}_title`), desc: s(`step_${i}_desc`) })).filter((st) => st.title);
    if (!steps.length) return null;
    return (
        <section className="bg-[#f6efe2] py-20 sm:py-28">
            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <Reveal className="mx-auto max-w-2xl text-center">
                    <Eyebrow>{s('howitworks_badge', 'Process')}</Eyebrow>
                    <h2 className="mt-6 font-display text-3xl font-medium leading-tight tracking-tight text-[#241f1a] sm:text-4xl">
                        <AccentHeading text={s('howitworks_title', 'From chaos to clarity in 3 simple steps')} />
                    </h2>
                    <p className="mt-4 text-base text-[#6f6660]">{s('howitworks_subtitle')}</p>
                </Reveal>
                <div className="mt-14 grid gap-6 sm:grid-cols-3">
                    {steps.map((st, i) => (
                        <Reveal key={i} delay={i * 110} className="relative rounded-3xl border border-black/[0.06] bg-[#fffdf9] p-7">
                            <span className="font-display text-5xl font-semibold text-brand-500/25">0{i + 1}</span>
                            <h3 className="mt-4 font-display text-lg font-medium text-[#241f1a]">{st.title}</h3>
                            <p className="mt-2 text-sm leading-relaxed text-[#6f6660]">{st.desc}</p>
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Features({ landing }) {
    const s = (k, d = '') => landing[`landing.${k}`] ?? d;
    if (s('features_enabled') !== '1') return null;
    const feats = [1, 2, 3, 4, 5, 6, 7, 8, 9].map((i) => ({
        icon: s(`feature_${i}_icon`, 'zap'),
        title: s(`feature_${i}_title`),
        desc: s(`feature_${i}_desc`),
    })).filter((f) => f.title);
    if (!feats.length) return null;
    return (
        <section id="features" className="scroll-mt-20 bg-[#faf5ec] py-20 sm:py-28">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <Reveal className="mx-auto max-w-2xl text-center">
                    <Eyebrow>{s('features_badge', 'Features')}</Eyebrow>
                    <h2 className="mt-6 font-display text-3xl font-medium leading-tight tracking-tight text-[#241f1a] sm:text-4xl">
                        <AccentHeading text={s('features_title')} />
                    </h2>
                    <p className="mt-4 text-base text-[#6f6660]">{s('features_subtitle')}</p>
                </Reveal>
                <div className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {feats.map((f, i) => (
                        <Reveal key={i} delay={(i % 3) * 80} className="group rounded-3xl border border-black/[0.06] bg-[#fffdf9] p-7 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-black/[0.04]">
                            <div className="mb-5 inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-500/10 text-brand-600 transition-colors duration-300 group-hover:bg-brand-500 group-hover:text-white">
                                <FeatureIcon name={f.icon} className="h-6 w-6" />
                            </div>
                            <h3 className="font-display text-lg font-medium text-[#241f1a]">{f.title}</h3>
                            <p className="mt-2 text-sm leading-relaxed text-[#6f6660]">{f.desc}</p>
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Testimonials({ landing }) {
    const s = (k, d = '') => landing[`landing.${k}`] ?? d;
    if (s('testimonials_enabled') !== '1') return null;
    const items = [1, 2, 3, 4, 5, 6].map((i) => ({
        name: s(`testimonial_${i}_name`),
        role: s(`testimonial_${i}_role`),
        text: s(`testimonial_${i}_text`),
    })).filter((tm) => tm.name && tm.text);
    if (!items.length) return null;
    return (
        <section className="bg-[#f6efe2] py-20 sm:py-28">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <Reveal className="mx-auto max-w-2xl text-center">
                    <Eyebrow>{s('testimonials_badge', 'Loved by teams')}</Eyebrow>
                    <h2 className="mt-6 font-display text-3xl font-medium leading-tight tracking-tight text-[#241f1a] sm:text-4xl">
                        <AccentHeading text={s('testimonials_title')} />
                    </h2>
                </Reveal>
                <div className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {items.map((tm, i) => (
                        <Reveal key={i} delay={(i % 3) * 80} className="flex flex-col rounded-3xl border border-black/[0.06] bg-[#fffdf9] p-7">
                            <Stars />
                            <p className="mt-4 flex-1 text-sm leading-relaxed text-[#4a433f]">“{tm.text}”</p>
                            <div className="mt-6 flex items-center gap-3">
                                <span className="flex h-10 w-10 items-center justify-center rounded-full bg-brand-500/15 text-sm font-bold text-brand-600">{tm.name.charAt(0)}</span>
                                <div>
                                    <p className="text-sm font-semibold text-[#241f1a]">{tm.name}</p>
                                    <p className="text-xs text-[#8a817a]">{tm.role}</p>
                                </div>
                            </div>
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Faq({ landing }) {
    const s = (k, d = '') => landing[`landing.${k}`] ?? d;
    const [open, setOpen] = useState(0);
    if (s('faq_enabled') !== '1') return null;
    const faqs = [1, 2, 3, 4, 5].map((i) => ({ q: s(`faq_${i}_q`), a: s(`faq_${i}_a`) })).filter((f) => f.q && f.a);
    if (!faqs.length) return null;
    return (
        <section className="bg-[#faf5ec] py-20 sm:py-28">
            <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <Reveal className="text-center">
                    <Eyebrow>{s('faq_badge', 'FAQ')}</Eyebrow>
                    <h2 className="mt-6 font-display text-3xl font-medium leading-tight tracking-tight text-[#241f1a] sm:text-4xl">
                        <AccentHeading text={s('faq_title')} />
                    </h2>
                </Reveal>
                <div className="mt-12 space-y-3">
                    {faqs.map((f, i) => {
                        const isOpen = open === i;
                        return (
                            <Reveal key={i} delay={i * 60} className={`rounded-2xl border bg-[#fffdf9] transition-colors ${isOpen ? 'border-brand-400/50' : 'border-black/[0.06]'}`}>
                                <button className="flex w-full items-center justify-between gap-4 px-6 py-5 text-left" onClick={() => setOpen(isOpen ? -1 : i)}>
                                    <span className={`font-display text-base font-medium ${isOpen ? 'text-brand-600' : 'text-[#241f1a]'}`}>{f.q}</span>
                                    <span className={`flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full transition-all duration-300 ${isOpen ? 'rotate-45 bg-brand-500 text-white' : 'bg-black/[0.04] text-[#8a817a]'}`}>
                                        <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2.2} viewBox="0 0 24 24"><path strokeLinecap="round" d="M12 5v14M5 12h14" /></svg>
                                    </span>
                                </button>
                                <div className={`grid transition-all duration-300 ease-smooth ${isOpen ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}>
                                    <div className="overflow-hidden">
                                        <p className="px-6 pb-5 text-sm leading-relaxed text-[#6f6660]">{f.a}</p>
                                    </div>
                                </div>
                            </Reveal>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

function CtaBand({ landing, auth, canRegister }) {
    const s = (k, d = '') => landing[`landing.${k}`] ?? d;
    if (s('cta_enabled') !== '1') return null;
    return (
        <section className="bg-[#faf5ec] px-4 pb-24 sm:px-6 lg:px-8">
            <Reveal className="relative mx-auto max-w-6xl overflow-hidden rounded-[2rem] px-6 py-16 text-center sm:py-20" style={{ background: 'radial-gradient(ellipse 60% 90% at 50% 0%, rgba(255,118,46,0.18), transparent 70%), #241f1a' }}>
                <div className="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-brand-500/20 blur-3xl" />
                <div className="pointer-events-none absolute -bottom-20 -left-16 h-64 w-64 rounded-full bg-brand-600/15 blur-3xl" />
                <h2 className="relative mx-auto max-w-2xl font-display text-3xl font-medium leading-tight tracking-tight text-white sm:text-5xl">
                    <AccentHeading text={s('cta_title')} />
                </h2>
                {s('cta_subtitle') && <p className="relative mx-auto mt-5 max-w-xl text-base text-white/70">{s('cta_subtitle')}</p>}
                <div className="relative mt-9 flex flex-wrap items-center justify-center gap-3">
                    {auth?.user ? (
                        <Link href={route('client.dashboard')} className="inline-flex items-center gap-2 rounded-full bg-brand-500 px-7 py-3.5 text-sm font-semibold text-white transition-all hover:-translate-y-0.5 hover:bg-brand-600">{s('cta_primary', 'Open dashboard')}</Link>
                    ) : (
                        <>
                            {canRegister && (
                                <Link href={route('register')} className="group inline-flex items-center gap-2 rounded-full bg-brand-500 px-7 py-3.5 text-sm font-semibold text-white shadow-[0_10px_30px_-6px_rgba(255,118,46,0.5)] transition-all hover:-translate-y-0.5 hover:bg-brand-600">
                                    {s('cta_primary', 'Get started')}
                                    <ArrowUpRight className="h-4 w-4" />
                                </Link>
                            )}
                            <Link href="/contact" className="inline-flex items-center gap-2 rounded-full border border-white/20 px-7 py-3.5 text-sm font-semibold text-white transition-colors hover:bg-white/10">{s('cta_secondary', 'Book a demo')}</Link>
                        </>
                    )}
                </div>
            </Reveal>
        </section>
    );
}

function Footer({ landing }) {
    const { t } = useTranslation();
    const year = new Date().getFullYear();
    const cols = [
        { title: t('landing_page_admin.footer_product', { defaultValue: 'Product' }), links: [
            { label: t('nav.features', { defaultValue: 'Features' }), href: '/#features' },
            { label: t('nav.integrations', { defaultValue: 'Integrations' }), href: '/integrations' },
            { label: t('nav.pricing', { defaultValue: 'Pricing' }), href: '/pricing' },
            { label: t('nav.faq', { defaultValue: 'FAQ' }), href: '/faq' },
        ] },
        { title: t('landing_page_admin.footer_company', { defaultValue: 'Company' }), links: [
            { label: t('landing_page_admin.footer_about', { defaultValue: 'About' }), href: '/about' },
            { label: t('nav.use_cases', { defaultValue: 'Use Cases' }), href: '/use-cases' },
            { label: t('nav.contact', { defaultValue: 'Contact' }), href: '/contact' },
        ] },
        { title: t('landing_page_admin.footer_legal', { defaultValue: 'Legal' }), links: [
            { label: t('landing_page_admin.footer_privacy', { defaultValue: 'Privacy Policy' }), href: '/p/privacy' },
            { label: t('landing_page_admin.footer_terms', { defaultValue: 'Terms of Service' }), href: '/p/terms' },
        ] },
    ];
    return (
        <footer className="bg-[#1c1814] pt-16 pb-10 text-white">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <div className="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="lg:col-span-1">
                        <img src="/wisperbot-logo-white.svg" alt="WisperBot" className="h-7 w-auto" />
                        <p className="mt-4 max-w-xs text-sm leading-relaxed text-white/50">{t('landing.footer_tagline', { defaultValue: 'Omnichannel customer support, automated with AI.' })}</p>
                    </div>
                    {cols.map((c) => (
                        <div key={c.title}>
                            <h4 className="text-xs font-semibold uppercase tracking-wider text-white/40">{c.title}</h4>
                            <ul className="mt-4 space-y-2.5">
                                {c.links.map((l) => (
                                    <li key={l.href}><Link href={l.href} className="text-sm text-white/60 transition-colors hover:text-white">{l.label}</Link></li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
                <div className="mt-12 border-t border-white/10 pt-6 text-center text-xs text-white/40">
                    &copy; {year} {t('nav.all_rights_reserved', { defaultValue: 'WisperBot(A Netro Systems Limited Company) All rights reserved.' })}
                </div>
            </div>
        </footer>
    );
}

// ─── Page ───────────────────────────────────────────────────────────────────

export default function Welcome({ auth, canLogin, canRegister, landing = {}, plans = [] }) {
    const s = (k, d = '') => landing[`landing.${k}`] ?? d;
    const appName = import.meta.env.VITE_APP_NAME || 'WisperBot';
    const metaTitle = s('seo_title') || s('hero_title') || appName;
    const metaDesc = s('seo_description') || s('hero_subtitle') || '';

    const faqs = [1, 2, 3, 4, 5].map((i) => ({ q: s(`faq_${i}_q`), a: s(`faq_${i}_a`) })).filter((f) => f.q && f.a);
    const jsonLd = [
        { '@context': 'https://schema.org', '@type': 'Organization', name: appName, description: metaDesc },
        { '@context': 'https://schema.org', '@type': 'SoftwareApplication', name: appName, applicationCategory: 'BusinessApplication', operatingSystem: 'Web', description: metaDesc, offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD' } },
    ];
    if (faqs.length) {
        jsonLd.push({ '@context': 'https://schema.org', '@type': 'FAQPage', mainEntity: faqs.map((f) => ({ '@type': 'Question', name: f.q, acceptedAnswer: { '@type': 'Answer', text: f.a } })) });
    }

    return (
        <div className="min-h-screen bg-[#faf5ec] font-sans text-[#241f1a]" style={{ color: INK }}>
            <SeoHead title={metaTitle} description={metaDesc} keywords={s('seo_keywords')} image={s('seo_og_image') || undefined} jsonLd={jsonLd} />
            <Header auth={auth} landing={landing} />
            <main>
                <Hero landing={landing} auth={auth} canRegister={canRegister} />
                <LogoCloud landing={landing} />
                <Editorial landing={landing} />
                <Channels landing={landing} />
                <UseCases landing={landing} />
                <Process landing={landing} />
                <Features landing={landing} />
                <Testimonials landing={landing} />
                <Faq landing={landing} />
                <CtaBand landing={landing} auth={auth} canRegister={canRegister} />
            </main>
            <Footer landing={landing} />
            <CookieConsent />
        </div>
    );
}
