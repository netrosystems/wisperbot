# WisperBot repository instructions

These instructions apply to every file in this repository and to every person or coding agent making a change.

## Source of truth

The running code and database migrations are authoritative. Documentation explains the intended system. If code and documentation disagree, investigate the discrepancy; do not silently preserve contradictory behavior.

Start work by reading `docs/README.md`, `docs/HANDOFF.md`, and the authoritative domain specification for your change. The domain specification & documentation impact matrix lives in [docs/map/DOCS.md](docs/map/DOCS.md): read the authoritative document for your task domain before writing code, and update it in the same commit if your changes affect that domain.

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

- WisperBot is a multi-industry SaaS. A client's business, sample question, screenshot or KB is a test case, not a platform-wide product rule. Do not hardcode tenant names, industries, question scripts or choice labels into the primary Smart Bot flow.
- Dynamic Smart Bot reply choices are an existing feature. Trace generation → stored payload → session/poll/realtime serialization → widget/SDK rendering before proposing a replacement. Buttons are suggested customer text, not executable business actions. Never claim SDK deployment from server tests; the app team owns SDK implementation and release unless explicitly reassigned.
- Preserve workspace isolation on every query, webhook, broadcast channel, job, and API action.
- Keep public widget conversations private to a stable visitor/session identity; never expose a shared public transcript.
- Treat inbound webhooks as untrusted: verify signatures/tokens, apply idempotency, and queue expensive work.
- Do not expose encrypted credentials back to the browser. Blank credential fields mean “keep the stored value.”
- Browser authentication uses session cookies and CSRF; mobile and external APIs use Sanctum bearer tokens.
- Do not conflate provider capabilities. In particular, Facebook and Instagram post-edit/delete support differ and must be capability-driven.
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

---

## Workflow and release

- Work on a feature branch, merge it into `dev`, then open a GitHub pull request from `dev` to `main`.
- **Merging a `dev` → `main` pull request deploys production** through `.github/workflows/deploy-production.yml`. Never merge one without the owner's approval. Pushes to `dev` or directly to `main` do not deploy.
- Never run a local environment against production credentials or data, and never commit `.env` files.

---

## Project map

Before opening files, read the router for the task, then only the files it points to:

- [docs/map/PRODUCT.md](docs/map/PRODUCT.md): where the code for each area lives
- [docs/map/OPERATIONS.md](docs/map/OPERATIONS.md): local setup, checks, CI, deploy, release, rollback, health, configuration
- [docs/map/DOCS.md](docs/map/DOCS.md): which specification answers which question, and which to update

The map guides what to read first. It never replaces the rules above or a release gate.

**Keep the map true.** A change that adds, moves or removes a file a router names updates that router in the same change. Each code area keeps its own short README router (`app/Modules/README.md`, `resources/js/README.md`), linked from PRODUCT.md. Routers stay about 15–35 lines, link only to files that exist, and never hold secrets. Reference material goes in `docs/`, not here; keep this file under about 8 KB.

