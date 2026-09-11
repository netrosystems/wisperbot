# Operations

Last verified against code: 2026-08-21.

## Local environment

From the repository root:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer dev
```

`composer dev` starts Laravel, a queue listener, Pail, and Vite. Local `.env` must use local URLs/database and test credentials. Do not copy production `.env` into source control.

## Production deployment

Managed OpenAI model overrides must be tested with the active project key. The admin test now exercises both distinct `AI_MANAGED_ROUTINE_MODEL` and `AI_MANAGED_COMPLEX_MODEL` values plus `AI_MANAGED_EMBEDDING_MODEL`; after changing these values rebuild config and restart `ai` workers. A provider test alone does not verify widget assignment, queues, retrieval, or credit finalization. Indexing status label changes in `resources/js/locales/en.json` are served by `/i18n/{locale}` and do not require a Vite rebuild when no JS component changes.

Follow [DEPLOYMENT.md](../DEPLOYMENT.md). The essential properties are:

- deploy the `main` branch;
- preserve production `.env` and `storage/app/public`;
- run Composer and migrations;
- deploy a current `public/build` for frontend changes;
- run `php artisan app:deploy:finalize` once for the new Git revision;
- bring the application back up even if an optional step fails.

The server should remain a clean checkout. Runtime files such as logs, lock files, uploaded builds, backups, and macOS metadata should not be placed inside tracked source paths.

## Scheduler

Run Laravel's scheduler every minute:

```cron
* * * * * cd /home/USER/wisperbot.com && php artisan schedule:run >> /dev/null 2>&1
```

Long-running `schedule:work` is also valid where process supervision exists. Important tasks include social dispatch (ten-second cadence within the scheduler process), campaign launch, email sync, eBay sync, token refresh, billing reconciliation, trial expiry, digests, unanswered reminders, and cleanup.

The scheduler also runs `reconcile-ai-credit-reservations` every five minutes. It refunds reservations older than `config/ai_credits.php`'s configured ten-minute window; a stopped scheduler can therefore leave managed credits temporarily reserved.

The Super Admin Cron Setup heartbeat confirms scheduler activity; it does not prove every queue is being consumed.

## Queue workers

All of these queues must be consumed:

```text
default,whatsapp,broadcast,ai,social,leads,automation
```

A single shared-hosting worker can use:

```bash
php artisan queue:work --queue=default,whatsapp,broadcast,ai,social,leads,automation --sleep=3 --tries=3 --timeout=120
```

Dedicated supervised workers are preferred; `docker-compose.queues.yml` documents the intended split. Restart workers after deployment:

```bash
php artisan queue:restart
```

Diagnostics:

```bash
php artisan queue:failed
php artisan queue:retry <uuid>
php artisan schedule:list
tail -n 200 storage/logs/laravel.log
```

Do not retry all failed AI indexing jobs until the provider/model/root cause is corrected.

After deploying the 2026-09-11 automation queue normalization, drain jobs created by the former Developer API typo once, using the deployment's normal queue connection:

```bash
php artisan queue:work --queue=automations --stop-when-empty --sleep=1 --tries=3 --timeout=1800
```

Run this only after the corrected code is active, inspect failed jobs afterward, and continue normal operation with workers consuming only the canonical `automation` queue.

## Frontend builds

Source changes under `resources/js` are not visible in production until Vite creates a new `public/build`.

```bash
npm ci
npm run build
```

On memory-constrained cPanel hosting, build locally from the exact deployed `main` commit and upload the contents of `public/build`. Verify `public/build/manifest.json` and a changed asset hash after upload.

## Upload limits

Laravel validation, PHP `upload_max_filesize`, PHP `post_max_size`, web-server/proxy limits, and the actual web SAPI must all permit the advertised size. CLI output alone does not prove the web runtime's limit. Temporary diagnostic PHP files must be removed immediately after checking.

## Health and verification

- `/up` — Laravel health.
- `/healthz/db`, `/healthz/redis`, `/healthz/queue` — service checks where enabled by routes.
- `git log -1 --oneline` — deployed backend revision.
- `git status --short` — should be clean except explicitly understood runtime artifacts.
- Sidebar version — deployment finalizer version, not proof of a current frontend bundle by itself.

## Managed AI rollout

AI-credit enforcement defaults to enabled. `AI_CREDITS_ENFORCE=false` is an explicit diagnostic shadow mode: the ledger records completed managed demand and provider cost, but over-limit actions are not blocked or allowed to create a negative visible balance. Configure each plan's finite `ai_credits_per_month` value, validate the selected managed integration, compare ledger totals with provider billing, and confirm the stale-reservation scheduler before deployment. Restart queue workers and clear/rebuild configuration caches after changing the flag. Hard enforcement returns `402 ai_credits_exhausted`; automatic mode uses only a successfully tested workspace provider.

### Credit-blocked automation runs

An AI node that cannot reserve credits leaves its automation run in `paused` rather than marking it completed or sending a generic fallback. After upgrading the plan or enabling a successfully tested BYOK provider, open the automation's run history and select **Retry**. The run resumes from its stored `current_node_id`; its stable run/node idempotency key prevents a completed AI action from being billed twice.

## WhatsApp connection monitoring (2026-09-05)

Deploy the `2026_09_05_120000_create_whatsapp_connection_health_tables` migration and matching frontend before enabling `CHANNEL_HEALTH_ENABLED=true`. Initially set `CHANNEL_HEALTH_WORKSPACE_IDS=YOUR_OPERATOR_WORKSPACE_ID`; an empty list enables every workspace. This does not change messaging account status or consume AI credits.

Run a **separate supervised process** in addition to existing workers:

```bash
php artisan queue:work --queue=channel-health --sleep=3 --tries=1 --timeout=120 --max-time=3600
```

The queue connection's `retry_after` must exceed 120 seconds (use at least 180 seconds). The Docker queue overlay includes this worker. Admin → Cron Setup displays the command, platform component checks, and up to 50 accounts needing review. The minute scheduler dispatches heartbeats and due checks; initial WABAs are distributed over 15 minutes. Meta rate limits use bounded backoff and honor `Retry-After`. History is pruned after 90 days.

Only for platform-owned accounts, configure `META_OPERATOR_BUSINESS_ID` and comma-separated `META_OPERATOR_WABA_IDS`. The checker verifies the WABA owner against that business before using the system token for subscription repair. Customer WABAs always use their stored account credential; these environment settings never belong in a customer form.

Clear/rebuild config caches and restart workers after configuration changes. Verify the operator WABA, then a customer Cloud API account and a Coexistence account. A real incoming message must be processed after a repair before delivery is verified. Monitoring does not send test messages, register phones, replay messages, or restore events Meta never delivered. Stop rollout by disabling `CHANNEL_HEALTH_ENABLED`; existing messaging continues. External uptime monitoring remains necessary to alert when the entire application/scheduler is stopped.

## Team availability and ownership rollout (2026-09-12)

Deploy matching backend, Vite build, and `public/widget/wisperbot-chat-widget.js`, then run `php artisan migrate --force` for `2026_09_12_000100_add_availability_and_join_state.php`. Clear application/config/view caches and restart web, queue, and realtime workers so notification routing and ownership events use the new fields. Verify one web and one Sanctum mobile join/leave flow, a resolved conversation reopening unowned, an off-shift notification route, and widget waiting-to-joined transition. The migration is additive; rollback removes availability and joined-owner state but should be done only after reverting code that reads those columns.

## Incident triage order

Social comments deployment is separate and off by default: migrate `2026_09_05_150000_create_social_comments_tables`, deploy the matching local-built Vite assets, then restart `social` and `ai` workers. The `social-comments-sync` scheduler runs every 15 minutes. Use worker timeout at least 120 seconds, queue retry_after at least 180 seconds, and a shared lock-capable cache. Set `SOCIAL_COMMENTS_ENABLED=true` only after Meta scope/subscription checks and controlled testing. Detailed limits, rollback, mobile API, and reviewer recording steps are in [Social Comments](SOCIAL_COMMENTS.md). No production deployment is implied by a local build.

1. Capture request ID, exact time/timezone, workspace, route, and user-visible error.
2. Verify deployed Git revision and frontend asset manifest.
3. Check `storage/logs/laravel.log`, dedicated error logs, failed jobs, scheduler heartbeat, and worker processes.
4. Inspect the relevant workspace-scoped database record without exposing secrets.
5. Confirm provider dashboard/webhook delivery/token permissions.
6. Reproduce in local/staging with sanitized data and add a regression test before changing production code.
# Guarded Knowledge Base rollout

Deploy the migration and code with `KB_GUARDED_PUBLISHING=false`, restart workers consuming `ai`, and compute/inspect migrated readiness without changing retrieval. Existing indexed documents with valid embeddings are placed in an initial published revision without re-embedding; indexed documents without embeddings become degraded and need review. Enable the flag first for internal/staging environments, validate exact FAQ/cache behavior, revision rollback, knowledge gaps, regression tests, and queue retries, then roll out to selected client deployments. Keep the flag reversible until production answer quality and token telemetry are stable.

Operational checks: `php artisan migrate --force`, restart the `ai` worker, clear config cache after changing the flag, rebuild Vite assets, and verify that a failed draft leaves the prior `published_revision_id` answering normally.
# Crawler / managed AI production checks (2026-09-05)

After deploying a runtime-model or crawler change, finalize deployment to refresh cached configuration and restart `ai` workers. Test the actual configured managed models, not only credential validity. Website homepage responses can stall even when `/sitemap.xml` and individual pages work: discovery probes sitemaps first and extraction uses bounded timeouts. Qdrant credentials need payload-index creation rights for `kb_chunks.document_id` and `kb_chunks.kb_id`. Retry only affected Knowledge Base documents; do not replay unrelated failed jobs. SQL migrations and Vite rebuilding are not required for the v1.3.42 backend/locale-only hotfix.
