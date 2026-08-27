# WisperBot repository instructions

These instructions apply to every file in this repository and to every person or coding agent making a change.

## Source of truth

The running code and database migrations are authoritative. Documentation explains the intended system. If code and documentation disagree, investigate the discrepancy; do not silently preserve contradictory behavior.

Start work by reading `docs/README.md`, `docs/HANDOFF.md`, and the authoritative domain specification for your change from the table below:

---

## 🧭 Domain specification & documentation impact matrix

Read the authoritative document for your task domain before writing code, and update it in the same commit if your changes affect that domain:

| Task / Change domain | Authoritative document | What it guarantees & when to update |
| :--- | :--- | :--- |
| **UI, Styling, Colors, Layouts & Components** | [`DESIGNSYSTEM.md`](DESIGNSYSTEM.md) | Pixel-perfect UI using Space Grotesk & Fraunces, exact WisperBot Orange (`#FF762E`) & Amber (`#FFBF00`) tokens, `.page` vs `.viewport-table-page` (InboxLayout 100vh) layouts, XYFlow canvas, and accessible Radix/Headless UI components. |
| **Backend, APIs, Modules, Queues & Tenancy** | [`ARCHITECTURE.md`](ARCHITECTURE.md) and `docs/ARCHITECTURE.md` | Correct modular monolith patterns (`app/Modules/*`), strict `workspace_id` query scoping, queue assignments (`whatsapp`, `ai`, `social`, `automation`, `broadcast`), Reverb/Pusher WebSockets, and health probes. |
| **Features, Workflows, State Machines & Roadmap** | [`PLAN.md`](PLAN.md) | Complete business logic compliance across all 10 modules (Omni-Channel Inbox, Master Email Inbox, Widget with HMAC, WhatsApp Cloud API, AI Knowledge Bases, XYFlow Automations, Social Publishing, SMS Campaigns, Billing). |
| **External Integrations & OAuth Credentials** | [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md) and provider guides | Correct Meta OAuth scopes, Instagram limitations, Google/Microsoft OAuth, Telegram, SMS Gateways, SP-API / eBay, and Qdrant vectors. |
| **Operations, Workers, Deployment & Crons** | [`docs/OPERATIONS.md`](docs/OPERATIONS.md) and/or [`DEPLOYMENT.md`](DEPLOYMENT.md) | Scheduler setup, worker commands, deployment checklist, Vite production bundle builds, and log diagnostics. |
| **Security, Secrets & Threat Boundaries** | [`docs/SECURITY.md`](docs/SECURITY.md) | `Crypt::encryptString` secret storage, Sanctum bearer tokens, CSRF, webhook signatures, widget HMAC secrets, and local licensing guards. |
| **Product Decisions & Terminology** | [`docs/PRODUCT_DECISIONS.md`](docs/PRODUCT_DECISIONS.md) | Enforces intentional product choices (Omni-Channel vs Master Email Inbox separation, social deletion capabilities). |
| **Known Issues & Technical Debt** | [`docs/KNOWN_ISSUES.md`](docs/KNOWN_ISSUES.md) | Active queue name quirks, external platform review states, and historical workarounds. |
| **Current Repository Status & Next Steps** | [`docs/HANDOFF.md`](docs/HANDOFF.md) | Baseline commit status, active development focus, and immediate checklist. |
| **User-Visible Releases & Milestones** | [`docs/CHANGELOG.md`](docs/CHANGELOG.md) | Documentation-level release notes for user-visible or operationally significant changes. |

If a code change has no documentation impact, say so in the commit/hand-off summary. Do not bump the application version manually for ordinary commits; production deployment finalization owns patch-version increments.

---

## Documentation standards

- Record facts verified from code or provider documentation; label assumptions and pending decisions.
- Include exact route names, queue names, configuration key names, and platform limitations where useful.
- Never include passwords, API keys, access tokens, app secrets, private keys, license codes, reviewer credentials, or real customer personal data.
- Use placeholders such as `YOUR-DOMAIN`, `YOUR_APP_ID`, and `REDACTED`.
- Distinguish local, staging, and production behavior explicitly.
- Add dates to status snapshots and decisions that may become stale.
- Preserve provider-specific setup guides and link them from `docs/INTEGRATIONS.md`.

---

## Engineering invariants

- Preserve workspace isolation on every query, webhook, broadcast channel, job, and API action.
- Keep public widget conversations private to a stable visitor/session identity; never expose a shared public transcript.
- Treat inbound webhooks as untrusted: verify signatures/tokens, apply idempotency, and queue expensive work.
- Do not expose encrypted credentials back to the browser. Blank credential fields mean “keep the stored value.”
- Browser authentication uses session cookies and CSRF; mobile and external APIs use Sanctum bearer tokens.
- Do not conflate provider capabilities. In particular, Facebook and Instagram post-edit/delete support differ and must be capability-driven.
- Keep production license enforcement enabled. Any local testing bypass must be explicitly local-only and fail closed outside local environments.
- Preserve unrelated user changes in a dirty worktree.

---

## Required checks

Use focused tests first. For a typical backend/frontend change, consider:

```bash
php artisan test --filter=RelevantTest
npm test -- --run
npm run build
./vendor/bin/pint --test
composer analyse
```

For route, config, or deployment changes, also clear/rebuild relevant caches in a safe environment. Never claim an external integration works merely because a unit test passes; identify what still requires provider-side verification.
