# Current handoff

Snapshot date: 2026-09-05.

## Repository state at documentation creation

- Working directory: `/Users/macbookair/Documents/Github-Projects/wisperbot`
- Active branch: `main`
- Baseline commit: `53b7bd5 Clarify published post delete controls`
- One pre-existing, uncommitted user change was present in `resources/js/Pages/Social/Posts/Index.jsx`. It was intentionally not modified by the documentation work.

Always run `git status --short` and inspect recent history before starting; this snapshot will naturally become stale.

## Current focus

2026-09-05 Qdrant hotfix: final HTTP retry responses reach explicit status handling, allowing document deletion when the collection is absent and first-write collection creation. Ten focused Qdrant/guarded KB tests and changed-file Pint pass. Repository-wide analysis remains blocked by existing unmatched Leads/Twitter ignore paths. Backend-only release; no migration or frontend build required.

Local verification on 2026-09-05: the final connection-health, Embedded Signup, and scheduler-guide run passed 31 tests (87 assertions); frontend tests passed 22 tests, and the production build passed with bundle-size warnings. Changed-file Pint and focused health-code static analysis pass. Repository-wide static analysis remains blocked by existing configuration references to removed Leads/GooglePlaces/Twitter paths. No production migration, worker installation, or live customer verification was performed by this implementation.

WhatsApp connection health and explicit repair are implemented locally behind `CHANNEL_HEALTH_ENABLED=false`. New health snapshots/history require migration, the matching frontend, and a separate `channel-health` worker (120-second timeout; queue retry_after at least 180 seconds). Operator-only rollout uses `CHANNEL_HEALTH_WORKSPACE_IDS`; operator token use additionally requires `META_OPERATOR_BUSINESS_ID` and `META_OPERATOR_WABA_IDS`, verified against Graph ownership. Customer tokens never fall back to the operator token in this flow. Channel Setup distinguishes configuration checks from observed real delivery; Admin → Cron Setup shows platform/worker checks. External customer Cloud API and Coexistence verification remains a deployment gate. No production rollout or version bump is implied by local tests.

Social publishing is consolidated locally under `/app/social/automation`. The unified client page places compact connection management above Upcoming-first post tabs and integrated List/Calendar views; empty workspaces suppress inactive tabs/filters and use concise onboarding rows instead of oversized panels. `/app/social/automation/schedule` uses an explicit Schedule for later or Publish now choice. Legacy accounts/posts/composer/calendar URLs remain compatibility redirects. The i18n endpoint now versions its server cache from locale-file modification times and disables browser caching, preventing newly deployed labels from rendering as raw translation keys. Deploy the matching backend and Vite bundle together.

Guarded Knowledge Base publishing is implemented locally behind `KB_GUARDED_PUBLISHING=false`. It adds revision snapshots, staged extraction/validation/indexing, guided quality review/test/publish UI, exact FAQ and safe answer/query-embedding caches, retrieval diagnostics, knowledge gaps, rollback, and workspace-scoped API actions. Before enabling it, migrate, restart the `ai` worker, rebuild Vite assets, verify migrated revision membership/embeddings, and test that a failed draft leaves the prior live revision unchanged. The legacy retrieval path remains active while the flag is false.

Knowledge Base source authoring now presents URL, File, and Sitemap only. Indexing automatically discovers validated YouTube, Vimeo, and public HTTPS MP4 links in extracted pages/files (including supported iframe and Office hyperlink targets); existing Text, FAQ, and Video records remain compatible. Reindex existing URL/file sources to populate discovered video metadata.

The client label for internal `source_type=sitemap` is now Website. Homepage inputs safely resolve redirects and discover HTML/robots/common sitemap locations; sites without a sitemap fall back to capped same-host links. The production `ai` worker must be restarted after deployment for this behavior.

The Knowledge Base detail screen renders a single active setup/management step rather than stacking every panel. Initial setup gates Define → Sources → Review → Test → Publish; published records default to Monitor and can return to Sources for safe draft changes. Published-source edits, toggles, and reindexing use copy-on-write so the active revision remains immutable; additions and removals affect only draft membership. Automatic publication now requires saved regression tests, preventing a newly indexed source from bypassing the guided client review.

Verification on 2026-09-04: the guarded migration applied locally; the focused AI/KB/API/video suites passed 49 tests (151 assertions), the final guarded/indexing/chatbot/API regression pass passed 12 tests (36 assertions), and `npm run build` succeeded. Targeted changed-file Pint passes. Repository-wide Pint still reports pre-existing formatting in `app/Modules/AI/Services/Llm/GeminiProvider.php`; PHPStan cannot start because `phpstan.neon` contains unmatched ignore paths for removed Leads/Twitter files.

Managed AI credits use each plan's configured `ai_credits_per_month` limit without price-derived defaults. Organization/owner periods, encrypted idempotent ledger replay, workspace provider modes, and provider test state remain in place. Clients see remaining/total credits in the global header and a human-readable fixed-action breakdown on Subscription; successful managed actions charge once, while failures, BYOK, provider tests, and embeddings do not consume credits. Enforcement now defaults on, so production must have a tested managed provider and plan limits verified before deployment. DeepSeek remains restricted to the Super Admin `llm_deepseek_default` integration and is excluded from client provider screens, routes, BYOK resolution, and automatic fallback.

Credit entitlement resolution mirrors billing: an admin-assigned Client subscription takes precedence, then a valid self-service subscription owned by an organization user is pooled at the Client account. This prevents subscribed organization users from incorrectly seeing `0 / 0` while preserving one shared monthly pool.

Active renewable subscriptions now remain credit-eligible when a gateway or legacy admin assignment retains a stale prior-cycle `ends_at` value. The explicit active/trialing status is authoritative; canceled subscriptions still stop receiving credits after their access end. This fixes the Bella Salon & Spa Pro fixture resolving as `0 / 0` despite its configured 100-credit plan allowance.

The managed-credit migration uses explicit `DATETIME` billing-period boundaries for compatibility with MySQL servers that retain legacy implicit `TIMESTAMP` defaults. Its new columns/tables are guarded so a deployment can safely rerun the migration after a partial schema failure.

Alibaba Qwen 3.7 Flash is available locally as the Super Admin-only `llm_qwen_default` managed generation provider. It requires a region-matched Model Studio API key and Workspace ID, must pass its connection test before selection, and does not replace the OpenAI/Gemini embedding path used by Knowledge Bases.

Knowledge Base video answers are now implemented locally. Deployments must run migrations, rebuild the Vite bundle, restart the `ai` queue worker, and verify the customer site's `frame-src` CSP before provider playback can be claimed as operational. Native SDK clients receive the additive `payload.resources` contract but require their own UI release to render the inline card.

Recent work has concentrated on multi-format attachment support (PDFs, Docs, Spreadsheets, Presentations, TXT/CSV, ZIP, Images, Apple HEIC auto-conversion, 10MB limit) and Meta App Review permissions / Facebook & Instagram social publishing behavior:

- unified paperclip attachment button and document cards on Chat Widget and Web Inbox;
- strict 10 MB file validation and MIME normalization across Inbox, Widget, and Mobile APIs;
- automatic Apple HEIC/HEIF photo conversion to JPEG with graceful document fallback;
- channel-level guards preventing document sends over Instagram DM;
- correct OAuth redirects/scopes and publishing controls;

Before changing these flows, read `docs/PRODUCT_DECISIONS.md`, `docs/INTEGRATIONS.md`, the Social controllers/jobs/drivers, and their feature tests.

The current uncommitted work adds WhatsApp Business app Coexistence alongside the existing Cloud API flow. Local tests verify the signup payload, registration skip, sync requests, silent history import, and outbound phone-app echoes. Provider-side verification still requires deploying the matching backend/Vite bundle and completing Embedded Signup with a customer-owned WABA. The Business Portfolio that owns the WisperBot Meta app is not selectable through its own customer Embedded Signup and remains a system-user/operator connection path.

## Immediate technical follow-up

1. Resolve the `automations` versus `automation` queue mismatch documented in `KNOWN_ISSUES.md`.
2. Keep the production checkout clean; do not overwrite server-only `.env` or uploaded storage.
3. When diagnosing a UI/backend mismatch, verify both `git log -1` and `public/build/manifest.json`.
4. For Meta issues, separate code capability, token/scopes, Page/app subscription, App Review approval, and provider API limitations.
5. Continue adding regression tests for provider-side edit/delete and webhook routing before further production changes.

## Handoff checklist

For the next developer or LLM:

1. Read `AGENTS.md` and `docs/README.md`.
2. Run `git status --short`; preserve unrelated changes.
3. Confirm local `.env` is local-only and contains no production credentials copied into files/logs.
4. Identify the workspace and provider asset ownership path for the feature.
5. Trace controller → service/driver → job/event → model/provider result → UI.
6. Run focused backend/frontend tests and a production build when frontend changes.
7. Update the documentation files required by the impact matrix in the same commit.
8. Record unresolved external/provider dependencies in `KNOWN_ISSUES.md` rather than claiming completion.
