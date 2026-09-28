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

`composer dev` runs the Laravel server, a queue listener for every application queue (website-chat AI replies need the `ai` queue), Laravel Pail, and Vite together. Configure a local database and non-production integration credentials before testing integration flows. Never commit `.env` or credentials.

## Production Releases

Production deployment is intentionally tied to one GitHub event: merging a
same-repository pull request from `dev` into `main`. A push to `dev`, a direct
push or local merge pushed to `main`, a pull request from another branch or
fork, and a closed-but-unmerged pull request do not deploy. Promote work through
feature branch → `dev` → a final `dev` → `main` pull request.

The `Deploy production` GitHub Actions workflow serializes releases, connects
to the existing VPS checkout through the protected `production` environment,
fast-forwards `main`, and runs `bash ./deploy.sh`. The script builds the Docker
images, backs up the database, migrates, finalizes the application version,
restarts the services, and checks `/up`. Only after that succeeds does the
workflow create the matching `v<APP_VERSION>` GitHub release. See
[Deployment](DEPLOYMENT.md#github-actions-production-deployment) for required
secrets, trigger details, retry behavior, and diagnostics.

## Verification

```bash
composer test
npm test
npm run build
composer analyse
```

Run the smallest relevant test set while developing, then expand verification in proportion to the risk of the change.
