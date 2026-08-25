# WisperBot

WisperBot is a proprietary, multi-tenant customer communication SaaS. It combines an omni-channel inbox, website chat widgets, social publishing, AI knowledge bases and smart bots, SMS broadcasting, automations, ecommerce context, billing, and web/mobile agent experiences.

The application is built with Laravel 12, PHP 8.2+, Inertia, React 19, Vite, MySQL, and queued background processing.

## Documentation

Start with the [documentation index](docs/README.md) or explore the master specifications:

- [**Technical Architecture Specification (`ARCHITECTURE.md`)**](ARCHITECTURE.md) — System boundaries, modules, request paths, tenancy, queues, and real-time events.
- [**UI/UX Design System (`DESIGNSYSTEM.md`)**](DESIGNSYSTEM.md) — Space Grotesk & Fraunces typography, Orange & Amber brand palette, layout archetypes, and UI components.
- [**Feature Plan & Specifications (`PLAN.md`)**](PLAN.md) — Core user journeys, feature module breakdowns, and UI state machines.
- [**Agent Routing Guide (`AGENTS.md`)**](AGENTS.md) — Fast-lookup routing instructions for AI coding agents and repository maintenance rules.

### Detailed Domain Documentation (`docs/`)

- [Product decisions](docs/PRODUCT_DECISIONS.md) — Intentional product behavior and terminology.
- [Integrations](docs/INTEGRATIONS.md) — Third-party platforms, OAuth, webhooks, and limitations.
- [Operations](docs/OPERATIONS.md) and [Deployment](DEPLOYMENT.md) — Local setup, scheduler, workers, production release, and diagnostics.
- [Security](docs/SECURITY.md) — Authentication surfaces, workspace isolation, secrets, webhook verification, and local licensing.
- [Known issues](docs/KNOWN_ISSUES.md) — Unresolved limitations and operational risks.
- [Handoff](docs/HANDOFF.md) — Current repository state and the next engineer/LLM checklist.

Repository-wide maintenance rules for people and coding agents are in [AGENTS.md](AGENTS.md).

## Local Development

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer dev
```

`composer dev` runs the Laravel server, a queue listener, Laravel Pail, and Vite together. Configure a local database and non-production integration credentials before testing integration flows. Never commit `.env` or credentials.

## Verification

```bash
composer test
npm test
npm run build
composer analyse
```

Run the smallest relevant test set while developing, then expand verification in proportion to the risk of the change.
