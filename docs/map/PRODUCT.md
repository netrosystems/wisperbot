# Map — product code

Start here for any code change. Find the area, open its README router, then only what the task needs.

| Area | What lives there | Design doc |
|---|---|---|
| [app/Modules/README.md](../../app/Modules/README.md) | Feature modules (inbox, WhatsApp, AI, social, automation, broadcasting, ecommerce, integrations, leads, shared contacts), each with its own routes, jobs, models and migrations | [ARCHITECTURE.md](../../ARCHITECTURE.md), [docs/ARCHITECTURE.md](../ARCHITECTURE.md) |
| `app/Http/Controllers/` | Platform controllers outside modules: `Admin/` (Super Admin), `Client/` (client portal), `Api/` (mobile/external API), `Auth/`, `Install/`, `Webhooks/`, plus public site (`LandingController`, `PricingController`, `BlogController`, `CheckoutController`) | [ARCHITECTURE.md](../../ARCHITECTURE.md) |
| `app/Services/` | Cross-cutting services: billing, plan selection, workspaces, notifications and push, media/storage, i18n, marketing measurement, app version | [PRODUCT_DECISIONS](../PRODUCT_DECISIONS.md) |
| `app/Models/`, `database/migrations/` | Platform models (users, workspaces, plans, subscriptions…) and platform migrations; module migrations live in each module | [ARCHITECTURE.md](../../ARCHITECTURE.md) |
| `routes/` | `web.php` public site and health checks; `client.php` under `/app`; `admin.php` under `/admin`; `api.php` (under `/api`, mostly `v1`); `webhooks.php`; `reports.php`; `auth.php`; `channels.php` broadcast auth; `console.php` schedule | [SECURITY](../SECURITY.md) for auth surfaces |
| [resources/js/README.md](../../resources/js/README.md) | Inertia + React frontend: pages, layouts, components, locales, marketing site | [DESIGNSYSTEM.md](../../DESIGNSYSTEM.md), [docs/DESIGN.md](../DESIGN.md) |
| `resources/css/` | `app.css` (Tailwind app) and `marketing.css` (public site) | [DESIGNSYSTEM.md](../../DESIGNSYSTEM.md) |
| `tests/` | `Feature/` and `Unit/` PHPUnit suites; frontend tests in `resources/js/__tests__/` | [AGENTS.md § Required checks](../../AGENTS.md#required-checks) |
| `docker/`, `scripts/production/`, `deploy.sh` | Production packaging and deploy scripts | [OPERATIONS map](OPERATIONS.md) |
| `database/seeders/` | Plans, roles, currencies, translations, demo data. `TranslationSeeder` rewrites `resources/js/locales/*.json`; do not commit that output by accident. | — |

The staff mobile app and the customer Chat SDK are built outside this repository; server tests never prove their release. See [CHAT_REPLY_OPTIONS](../CHAT_REPLY_OPTIONS.md).
