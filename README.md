# WisperBot

WisperBot is a proprietary, multi-tenant customer communication SaaS. It combines an omni-channel inbox, website chat widgets, social publishing, AI knowledge bases and smart bots, SMS broadcasting, automations, ecommerce context, billing, and web/mobile agent experiences.

The application is built with Laravel 12, PHP 8.2+, Inertia, React 19, Vite, MySQL, and queued background processing.

## Documentation

Start with the [documentation index](docs/README.md). The most useful entry points are:

- [Architecture](docs/ARCHITECTURE.md) — modules, request paths, tenancy, events, queues, and data ownership.
- [Product decisions](docs/PRODUCT_DECISIONS.md) — intentional product behavior and terminology.
- [Integrations](docs/INTEGRATIONS.md) — third-party platforms, OAuth, webhooks, and limitations.
- [Operations](docs/OPERATIONS.md) and [deployment](DEPLOYMENT.md) — local setup, scheduler, workers, production release, and diagnostics.
- [Security](docs/SECURITY.md) — authentication surfaces, workspace isolation, secrets, webhook verification, and local licensing.
- [Known issues](docs/KNOWN_ISSUES.md) — unresolved limitations and operational risks.
- [Handoff](docs/HANDOFF.md) — current repository state and the next engineer/LLM checklist.

Repository-wide maintenance rules for people and coding agents are in [AGENTS.md](AGENTS.md).

## Local development

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
