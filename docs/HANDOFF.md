# Current handoff

Snapshot date: 2026-08-21.

## Repository state at documentation creation

- Working directory: `/Users/macbookair/Documents/Github-Projects/wisperbot`
- Active branch: `main`
- Baseline commit: `53b7bd5 Clarify published post delete controls`
- One pre-existing, uncommitted user change was present in `resources/js/Pages/Social/Posts/Index.jsx`. It was intentionally not modified by the documentation work.

Always run `git status --short` and inspect recent history before starting; this snapshot will naturally become stale.

## Current focus

Recent work has concentrated on Meta App Review permissions and Facebook/Instagram social publishing behavior, especially:

- correct OAuth redirects/scopes;
- scheduled publishing timing;
- storing provider results/remote IDs;
- capability-driven edit/delete controls;
- keeping Facebook and Instagram mutation behavior distinct;
- ensuring the deployed backend revision and Vite bundle match.

Before changing these flows, read `docs/PRODUCT_DECISIONS.md`, `docs/INTEGRATIONS.md`, the Social controllers/jobs/drivers, and their feature tests.

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
