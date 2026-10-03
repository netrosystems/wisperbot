# Map — operations

Open the guide in "Where" for the procedure. This table routes; it never replaces the rules in [AGENTS.md](../../AGENTS.md).

| Task | Where | Notes |
|---|---|---|
| Local setup | [docs/OPERATIONS.md § Local environment](../OPERATIONS.md#local-environment) | Copy `.env.example`. Needs a database; `composer dev` also starts a listener for every queue. Never point a local run at production credentials. |
| Checks before a PR | [AGENTS.md § Required checks](../../AGENTS.md#required-checks) | `php artisan test`, `npm test -- --run`, `npm run build`, `./vendor/bin/pint --test`, `composer analyse`. Integration tests do not prove provider-side behaviour. |
| CI | [`.github/workflows/deploy-production.yml`](../../.github/workflows/deploy-production.yml) | The only workflow. It deploys; there is no test CI, so opening a PR runs nothing. |
| Production deploy | [DEPLOYMENT.md § GitHub Actions production deployment](../../DEPLOYMENT.md#github-actions-production-deployment) | Ships only when a same-repo `dev` → `main` PR is merged. Direct pushes to `main` do not deploy. |
| Docker server layout, Nginx, updates | [docker/README.md](../../docker/README.md) | Single-VPS stack; `deploy.sh` at the repo root. Never `docker compose down -v` in production. |
| Non-Docker / shared hosting | [DEPLOYMENT.md § Atomic shared-hosting releases](../../DEPLOYMENT.md#atomic-shared-hosting-releases) | Scripts in `scripts/production/`. |
| Rollback | `scripts/production/rollback-production.sh`, [DEPLOYMENT.md](../../DEPLOYMENT.md) | `deploy.sh` backs up the database before migrations. |
| Release and version | [DEPLOYMENT.md § GitHub Actions production deployment](../../DEPLOYMENT.md#github-actions-production-deployment) | `app:deploy:finalize` assigns `APP_VERSION`; the workflow tags `v<APP_VERSION>`. Do not bump the version by hand. Notes go in [CHANGELOG](../CHANGELOG.md). |
| Health and verification | [docs/OPERATIONS.md § Health and verification](../OPERATIONS.md#health-and-verification) | `/up`, `/healthz/db`, `/healthz/redis`, `/healthz/queue`. |
| Scheduler and queue workers | [docs/OPERATIONS.md § Scheduler](../OPERATIONS.md#scheduler), [§ Queue workers](../OPERATIONS.md#queue-workers) | Meta inbound jobs run on the `whatsapp` queue; see [KNOWN_ISSUES](../KNOWN_ISSUES.md). |
| Frontend builds | [docs/OPERATIONS.md § Frontend builds](../OPERATIONS.md#frontend-builds) | `public/build` is not in Git. |
| Incident triage | [docs/OPERATIONS.md § Incident triage order](../OPERATIONS.md#incident-triage-order) | Also mobile API diagnostics and WhatsApp connection monitoring in the same guide. |
| Configuration | [`.env.example`](../../.env.example) | Names only. Never open or quote a real `.env`. |
| Deploy secrets | [DEPLOYMENT.md § GitHub Actions production deployment](../../DEPLOYMENT.md#github-actions-production-deployment) | `VPS_HOST`, `VPS_PORT`, `VPS_USERNAME`, `VPS_APP_PATH`, `VPS_SSH_PRIVATE_KEY`, `VPS_SSH_KNOWN_HOSTS` in the `production` environment. |
| Provider setup (Meta, Google, eBay, Amazon…) | [docs/INTEGRATIONS.md](../INTEGRATIONS.md) | Provider guides: [eBay](../../EBAY_SELLER_MESSAGING_SETUP.md), [Amazon](../../AMAZON_SELLER_MESSAGING_SETUP.md). |
| Feature rollouts (AI credits, KB, Smart Bot) | [docs/OPERATIONS.md](../OPERATIONS.md) rollout sections, [KNOWLEDGE_BASE_SMART_BOT](../KNOWLEDGE_BASE_SMART_BOT.md) | Flags such as `KB_GUARDED_PUBLISHING`, `AI_CREDITS_ENFORCE`. |
| Data and migrations | `database/migrations/`, `app/Modules/*/database/` | Migrations are authoritative. `deploy.sh` imports `wisperbot.sql` only into an empty database. |
