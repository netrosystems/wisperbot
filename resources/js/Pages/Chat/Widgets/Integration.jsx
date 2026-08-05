import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import {
    Check,
    Code2,
    Copy,
    ExternalLink,
    MonitorSmartphone,
    Settings as SettingsIcon,
    ShieldCheck,
    Smartphone,
    Sparkles,
    Webhook,
} from 'lucide-react';

/**
 * "Integrations" — Crisp-style single-snippet install + identity passthrough.
 *
 * One `<script>` tag handles every visitor: anonymous ones get a private
 * visitor id stamped to their device; signed-in ones are matched to their
 * identity the moment the host page sets `window.WisperBotSettings` (or calls
 * `WisperBot('identify', …)` after the script loads for SPAs that sign in
 * lazily). Each step reveals as much or as little as the workspace needs.
 */
export default function ChatWidgetIntegration({ widget, embedBase }) {
    return (
        <ClientLayout title="Widget integrations">
            <Head title="Widget integrations" />
            <div className="mx-auto max-w-5xl space-y-6">
                <PageHeader />

                {widget
                    ? <InstallFlow embedBase={embedBase} widget={widget} />
                    : <EmptyState />}

                <MobileSdkSpot />
            </div>
        </ClientLayout>
    );
}

/* ── Page header ─────────────────────────────────────────────────────────── */

function PageHeader() {
    return (
        <header>
            <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brand-600">
                <Webhook className="h-4 w-4" /> Integrations
            </div>
            <h1 className="mt-2 text-2xl font-bold text-neutral-900 dark:text-white">
                Install your chat widget
            </h1>
            <p className="mt-1 max-w-3xl text-sm text-neutral-500 dark:text-neutral-400">
                WisperBot gives you three ways to put a chat widget in front of your customers. Pick the one that matches where you ship — and you can mix them: the same widget identity follows the visitor across every surface.
            </p>

            <ul className="mt-4 grid gap-2 sm:grid-cols-3">
                <SurfaceChip
                    icon={<MonitorSmartphone className="h-3.5 w-3.5" />}
                    label="Website"
                    sub="Drop one snippet"
                />
                <SurfaceChip
                    icon={<Code2 className="h-3.5 w-3.5" />}
                    label="Single-page app"
                    sub="Same snippet + identify()"
                />
                <SurfaceChip
                    icon={<Smartphone className="h-3.5 w-3.5" />}
                    label="Mobile app"
                    sub="Native SDK (iOS / Android)"
                />
            </ul>
        </header>
    );
}

function SurfaceChip({ icon, label, sub }) {
    return (
        <li className="flex items-center gap-2.5 rounded-xl border border-neutral-200 bg-white px-3 py-2.5 dark:border-neutral-800 dark:bg-neutral-900">
            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-brand-500/10 text-brand-600 dark:text-brand-400">
                {icon}
            </span>
            <span className="leading-tight">
                <span className="block text-sm font-semibold text-neutral-900 dark:text-white">{label}</span>
                <span className="block text-[11px] text-neutral-500 dark:text-neutral-400">{sub}</span>
            </span>
        </li>
    );
}

/* ── Mobile SDK spot ─────────────────────────────────────────────────────── */

function MobileSdkSpot() {
    return (
        <a
            href="https://github.com/netrosystems/wisperbot-mobile-sdk"
            target="_blank"
            rel="noopener"
            className="group flex items-start gap-4 rounded-2xl border border-neutral-200 bg-white p-5 transition hover:border-brand-300 hover:shadow-sm dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-brand-700"
        >
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600 dark:text-brand-400">
                <Smartphone className="h-5 w-5" />
            </span>
            <span className="flex-1">
                <span className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold text-neutral-900 dark:text-white">Building a native mobile app?</span>
                    <span className="inline-flex items-center gap-1 rounded-full bg-brand-500/10 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider text-brand-600 dark:text-brand-400">
                        <Sparkles className="h-3 w-3" /> iOS &amp; Android
                    </span>
                </span>
                <span className="mt-1 block text-sm text-neutral-500 dark:text-neutral-400">
                    The WisperBot native SDK ships the same chat experience inside your own app — Swift / Kotlin, with the same identify() and user_hash APIs as the web snippet. Get the source, release notes, and integration docs on GitHub.
                </span>
                <span className="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 group-hover:underline dark:text-brand-400">
                    View the WisperBot mobile SDK on GitHub
                    <ExternalLink className="h-3.5 w-3.5" />
                </span>
            </span>
        </a>
    );
}

/* ── Empty fallback (defensive only: ensureWidget() usually prevents it) ── */

function EmptyState() {
    return (
        <div className="rounded-2xl border border-dashed border-neutral-300 bg-white px-6 py-14 text-center dark:border-neutral-700 dark:bg-neutral-900">
            <Code2 className="mx-auto h-9 w-9 text-neutral-300" />
            <h2 className="mt-4 font-semibold text-neutral-800 dark:text-white">No widget to integrate yet</h2>
            <p className="mt-1 text-sm text-neutral-500">A widget was not found for this workspace.</p>
            <Link
                href={route('client.inbox.chat-widgets.settings')}
                className="mt-5 inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700"
            >
                <SettingsIcon className="h-4 w-4" /> Open widget settings
            </Link>
        </div>
    );
}

/* ── Main flow ───────────────────────────────────────────────────────────── */

function InstallFlow({ embedBase, widget }) {
    return (
        <div className="space-y-5">
            <UniversalSnippet embedBase={embedBase} widgetKey={widget.widget_key} />

            <IdentifiedVisitors embedBase={embedBase} widgetKey={widget.widget_key} />

            <VerifyIdentity
                embedBase={embedBase}
                widgetKey={widget.widget_key}
                identitySecret={widget.identity_secret}
                verification={widget.identity_verification}
            />

            <FooterActions widgetKey={widget.widget_key} />
        </div>
    );
}

function FooterActions({ widgetKey }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-neutral-200 bg-neutral-50/60 px-4 py-3 text-sm dark:border-neutral-800 dark:bg-neutral-950/30">
            <a
                href={`/widgets/chat/${widgetKey}.js`}
                target="_blank"
                rel="noopener"
                className="inline-flex items-center gap-1.5 font-medium text-brand-600 hover:underline dark:text-brand-400"
            >
                <ExternalLink className="h-3.5 w-3.5" /> View the generated loader
            </a>
            <Link
                href={route('client.inbox.chat-widgets.settings')}
                className="inline-flex items-center gap-1.5 font-medium text-neutral-600 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-white"
            >
                <SettingsIcon className="h-3.5 w-3.5" /> Brand and behaviour
            </Link>
        </div>
    );
}

/* ── Universal snippet ───────────────────────────────────────────────────── */

/**
 * The one snippet every visitor loads. Anonymous → gets a stable, device-bound
 * visitor id. Logged-in → host page sets `window.WisperBotSettings` BEFORE this
 * script and the same snippet upgrades the visitor's identity automatically.
 */
function UniversalSnippet({ embedBase, widgetKey }) {
    const snippet =
`<script src="${embedBase}/widgets/chat/${widgetKey}.js" async></script>`;

    return (
        <Step
            n={1}
            kicker="Anonymous only"
            title="Simple website integration"
            sub="Drop one script tag. Visitors stay anonymous — no name, no email, no user lookup. Great for marketing sites and landing pages."
        >
            <CopyBox code={snippet} />
            <ol className="mt-4 grid gap-2.5 text-sm text-neutral-600 dark:text-neutral-300">
                <StepRow
                    icon={<MonitorSmartphone className="h-4 w-4" />}
                    label="Website"
                    text="Paste inside the layout file just before </body>, or via Google Tag Manager / your site builder's custom HTML slot."
                />
                <StepRow
                    icon={<Code2 className="h-4 w-4" />}
                    label="Single-page app"
                    text="Add the same snippet to index.html. The widget mounts instantly and waits for WisperBot('identify') after login."
                />
            </ol>
            <p className="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                <b className="font-semibold">No login roundtrip.</b> WisperBot does not peek at your session cookie. Your backend decides who is signed in and only hands us the fields you permit.
            </p>
        </Step>
    );
}

/* ── Step 2: identity passthrough ─────────────────────────────────────────── */

function IdentifiedVisitors({ embedBase, widgetKey }) {
    // Reuse the same loader — telling the host the snippet is identical makes
    // this feel like a "drop-in" upgrade rather than a different install path.
    const spaIdentify =
`// After your customer signs in (or whenever you learn who they are).
window.WisperBot('identify', {
  external_id: currentUser.id,           // your internal user id
  name:        currentUser.name,
  email:       currentUser.email,
  avatar:      currentUser.avatarUrl,
  user_hash:   signedUserHash            // optional, see step 3
});

// And on sign-out.
window.WisperBot('logout');`;

    const serverRendered =
`<!-- Server-rendered pages: print the visitor inline BEFORE the loader. -->
<script>
  window.WisperBotSettings = {
    external_id: "${'{{ user.id }}'}",
    name:        "${'{{ user.name }}'}",
    email:       "${'{{ user.email }}'}",
    avatar:      "${'{{ user.avatar_url }}'}"
  };
</script>
<script src="${embedBase}/widgets/chat/${widgetKey}.js" async></script>`;

    return (
        <Step
            n={2}
            kicker="Logged-in users only"
            title="Identify who your visitors are"
            sub="For sites with user accounts. After step 1's loader runs, hand us the visitor's name, email, and avatar so agents see who they're chatting with. Skip if your site has no accounts."
            badge="Recommended"
        >
            <SubSnippet
                label="Server-rendered pages (PHP, Rails, Django, …)"
                code={serverRendered}
                note="The widget reads window.WisperBotSettings before it loads and merges those fields into the chat session automatically."
            />
            <SubSnippet
                label="Single-page apps (React, Vue, Next.js, …)"
                code={spaIdentify}
                note="Call identify() right after auth resolves; the widget swaps the conversation onto the new identity without losing history."
            />
            <p className="mt-3 text-xs text-neutral-500 dark:text-neutral-400">
                Skip this step entirely if your site has no signed-in users — anonymous chats still work and land in your inbox like normal messages.
            </p>
        </Step>
    );
}

/* ── Step 3: identity verification (HMAC) ────────────────────────────────── */

function VerifyIdentity({ embedBase, widgetKey, identitySecret, verification }) {
    const php =
`// Never expose the secret to the browser. Sign on YOUR server.
$user_hash = hash_hmac('sha256', (string) $user->id, '${identitySecret}');`;

    const node =
`// Node.js
const crypto = require('crypto');
const userHash = crypto
  .createHmac('sha256', '${identitySecret}')
  .update(String(userId))
  .digest('hex');`;

    return (
        <Step
            n={3}
            kicker="Anti-spoofing"
            title="Prove the identity is genuine"
            sub="Sign the visitor's id on your server with HMAC. Without this, anyone can call identify() and pretend to be your user. Turn this on before passing real PII in step 2."
        >
            <div className={`rounded-xl border px-3.5 py-2.5 text-xs leading-5 ${
                verification
                    ? 'border-brand-200 bg-brand-50 text-brand-800 dark:border-brand-900/60 dark:bg-brand-950/40 dark:text-brand-200'
                    : 'border-neutral-200 bg-neutral-50 text-neutral-600 dark:border-neutral-800 dark:bg-neutral-900/40 dark:text-neutral-300'
            }`}>
                <span className="inline-flex items-center gap-1.5 font-semibold">
                    <ShieldCheck className="h-3.5 w-3.5" />
                    Verification is {verification ? <b>on</b> : <span className="font-normal">off</span>}
                </span>
                {verification
                    ? ' Unsigned identities are treated as anonymous and your agent sees a “not verified” badge.'
                    : ' Turn it on in Appearance → "Identity verification" once you start passing user_hash; WisperBot will reject unsigned identities at that point.'}
            </div>

            <SubSnippet label="Sign on your server (PHP)" code={php} />
            <SubSnippet label="Sign on your server (Node.js)" code={node} />

            <div className="mt-2">
                <p className="mb-1.5 text-xs font-medium text-neutral-500 dark:text-neutral-400">
                    Your widget secret (server-side only — never paste this into the browser)
                </p>
                <CopyBox code={identitySecret} dense />
            </div>
        </Step>
    );
}

/* ── Reusable bits ───────────────────────────────────────────────────────── */

function Step({ n, kicker, title, sub, badge, children }) {
    return (
        <section className="overflow-hidden rounded-2xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <header className="flex items-start gap-3 border-b border-neutral-100 bg-neutral-50/60 px-5 py-4 dark:border-neutral-800 dark:bg-neutral-950/30">
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white">
                    {n}
                </span>
                <div className="flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="font-semibold text-neutral-900 dark:text-white">{title}</h2>
                        {badge && (
                            <span className="inline-flex items-center gap-1 rounded-full bg-brand-500/10 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider text-brand-600 dark:text-brand-400">
                                <Sparkles className="h-3 w-3" /> {badge}
                            </span>
                        )}
                    </div>
                    {kicker && (
                        <p className="mt-1 text-[11px] font-semibold uppercase tracking-wider text-brand-600 dark:text-brand-400">
                            {kicker}
                        </p>
                    )}
                    {sub && <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">{sub}</p>}
                </div>
            </header>
            <div className="px-5 py-4">{children}</div>
        </section>
    );
}

function SubSnippet({ label, code, note }) {
    return (
        <div className="mt-4 first:mt-0">
            <p className="mb-1.5 text-xs font-medium text-neutral-500 dark:text-neutral-400">{label}</p>
            <CopyBox code={code} />
            {note && <p className="mt-2 text-xs leading-5 text-neutral-500 dark:text-neutral-400">{note}</p>}
        </div>
    );
}

function StepRow({ icon, label, text }) {
    return (
        <li className="flex gap-3">
            <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-brand-500/10 text-brand-600 dark:text-brand-400">
                {icon}
            </span>
            <span>
                <b className="font-semibold text-neutral-800 dark:text-neutral-200">{label}.</b>{' '}
                <span className="text-neutral-500 dark:text-neutral-400">{text}</span>
            </span>
        </li>
    );
}

function CopyBox({ code, dense = false }) {
    // Tiny inline copy-to-clipboard button. Reused by every code block on the page.
    // We avoid React state in this simple version — use a ref to write "Copied"
    // briefly — but state is fine here, just keep it inside this leaf component.
    return <CopyBoxWithState code={code} dense={dense} />;
}

function CopyBoxWithState({ code, dense }) {
    const [copied, setCopied] = useState(false);
    const copy = () => {
        const done = () => { setCopied(true); setTimeout(() => setCopied(false), 2000); };
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(code).then(done, done);
        } else {
            const ta = document.createElement('textarea');
            ta.value = code;
            ta.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            done();
        }
    };
    return (
        <div className="relative">
            <pre className={`overflow-x-auto rounded-lg bg-neutral-950 font-mono text-neutral-200 whitespace-pre ${dense ? 'px-3 py-2 text-[11px]' : 'p-3.5 text-[12px] leading-relaxed'}`}>
                {code}
            </pre>
            <button
                type="button"
                onClick={copy}
                className="absolute right-2 top-2 inline-flex items-center gap-1 rounded-md bg-white/15 px-2.5 py-1.5 text-[11px] font-semibold text-white transition hover:bg-white/25"
            >
                {copied ? <><Check className="h-3 w-3" /> Copied</> : <><Copy className="h-3 w-3" /> Copy</>}
            </button>
        </div>
    );
}
