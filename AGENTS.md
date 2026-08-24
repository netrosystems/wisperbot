# WisperBot repository instructions

These instructions apply to every file in this repository and to every person or coding agent making a change.

## Source of truth

The running code and database migrations are authoritative. Documentation explains the intended system. If code and documentation disagree, investigate the discrepancy; do not silently preserve contradictory behavior.

Start work by reading `docs/README.md`, `docs/HANDOFF.md`, and the domain document relevant to the change.

## Documentation is part of every change

Before finishing any change, perform a documentation impact check. Update the relevant files in the same commit:

| Change type | Required documentation |
| --- | --- |
| Modules, routes, APIs, events, data flow, schema, tenancy | `docs/ARCHITECTURE.md` |
| UX, naming, entitlements, plan behavior, platform limitations | `docs/PRODUCT_DECISIONS.md` |
| OAuth, permissions, credentials, webhooks, provider capabilities | `docs/INTEGRATIONS.md` and any provider-specific setup guide |
| Environment, scheduler, workers, build, deployment, recovery | `docs/OPERATIONS.md` and/or `DEPLOYMENT.md` |
| Authentication, authorization, secrets, validation, privacy | `docs/SECURITY.md` |
| An unresolved defect, limitation, workaround, or operational risk | `docs/KNOWN_ISSUES.md` |
| User-visible or operationally significant completed work | `docs/CHANGELOG.md` |
| Active work, partial implementation, or a handoff dependency | `docs/HANDOFF.md` |

If a code change has no documentation impact, say so in the commit/hand-off summary. Do not bump the application version manually for ordinary commits; production deployment finalization owns patch-version increments.

## Documentation standards

- Record facts verified from code or provider documentation; label assumptions and pending decisions.
- Include exact route names, queue names, configuration key names, and platform limitations where useful.
- Never include passwords, API keys, access tokens, app secrets, private keys, license codes, reviewer credentials, or real customer personal data.
- Use placeholders such as `YOUR-DOMAIN`, `YOUR_APP_ID`, and `REDACTED`.
- Distinguish local, staging, and production behavior explicitly.
- Add dates to status snapshots and decisions that may become stale.
- Preserve provider-specific setup guides and link them from `docs/INTEGRATIONS.md`.

## Engineering invariants

- Preserve workspace isolation on every query, webhook, broadcast channel, job, and API action.
- Keep public widget conversations private to a stable visitor/session identity; never expose a shared public transcript.
- Treat inbound webhooks as untrusted: verify signatures/tokens, apply idempotency, and queue expensive work.
- Do not expose encrypted credentials back to the browser. Blank credential fields mean “keep the stored value.”
- Browser authentication uses session cookies and CSRF; mobile and external APIs use Sanctum bearer tokens.
- Do not conflate provider capabilities. In particular, Facebook and Instagram post-edit/delete support differ and must be capability-driven.
- Keep production license enforcement enabled. Any local testing bypass must be explicitly local-only and fail closed outside local environments.
- Preserve unrelated user changes in a dirty worktree.

## Required checks

Use focused tests first. For a typical backend/frontend change, consider:

```bash
php artisan test --filter=RelevantTest
npm test -- --run
npm run build
./vendor/bin/pint --test
```

For route, config, or deployment changes, also clear/rebuild relevant caches in a safe environment. Never claim an external integration works merely because a unit test passes; identify what still requires provider-side verification.
