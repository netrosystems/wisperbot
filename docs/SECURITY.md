# Security and privacy

Last reviewed against code: 2026-08-21.

## Trust boundaries

- Public browser: marketing site and widget JavaScript.
- Authenticated browser: session cookie, CSRF, verified user, client/workspace middleware.
- Mobile/external clients: Sanctum bearer tokens and explicit abilities.
- Provider webhooks: unauthenticated transport endpoints verified inside controllers.
- Queue workers: trusted application processes consuming untrusted serialized input references/payloads.
- Super Admin: privileged system configuration and impersonation capabilities.

## Workspace isolation

Every client-owned query must scope records to a workspace the authenticated user can access. Never authorize only by a model ID supplied in the URL. Broadcast channel authorization, queued jobs, downloads/media, API controllers, and webhooks require the same ownership discipline.

Provider assets should not be attached to multiple workspaces when inbound routing would become ambiguous. Deleting/disconnecting an account must remove or deactivate the local routing record and revoke/unsubscribe provider access when supported.

## Authentication

- Web client: Laravel session, CSRF, verified email, client role and scope.
- Super Admin: separate admin guard, license middleware, RBAC/permissions.
- Mobile: Sanctum bearer token; rate-limited login with a structured `429` response.
- Developer API: Sanctum tokens plus paid add-on and per-token abilities.
- Private broadcast auth: session endpoint for web; Sanctum endpoint for mobile.

## Secrets

- `.env` is never committed.
- Provider secrets/tokens stored in the database must use Laravel encrypted casts/services.
- UI credential placeholders never reveal stored values; blank update means retain current secret.
- Logs and exception messages must redact Authorization headers, access/refresh tokens, API keys, app secrets, webhook secrets, widget identity secrets, license codes, and passwords.
- Documentation and tests use fake values only.

## Webhooks

CSRF exemptions do not mean “unverified.” Every provider controller must validate the provider signature, secret, challenge token, or equivalent before accepting work. Use `WebhookIdempotencyService`/inbound event records so provider retries do not duplicate messages, posts, orders, or billing transitions.

Webhook endpoints should acknowledge quickly and queue expensive processing. The shared Meta rate limit is intentionally high because many workspaces share Meta egress IPs.

## Website widget identity and privacy

- A visitor transcript is bound to an opaque, stable visitor/session identity and widget key.
- A host website may pass name/email/avatar/external ID, but trusted identity requires a server-generated HMAC using the widget secret.
- Never embed the widget secret in public JavaScript.
- Invalid/unsigned identity is anonymous; do not accept browser claims as verified customer identity.
- Visitor IP/presence must be covered by privacy disclosures, retention policy, and customer configuration as required by law.
- Media access must enforce conversation/workspace/session authorization; storage URLs should not become a cross-tenant public file browser.

## Licensing

Production licensing is a server/admin-panel control and must remain enforced. Local bypass logic is acceptable only when `APP_ENV=local` (or an equally strict local-only guard) and must fail closed in staging/production. Licensing must not be scattered as a check on every business API request unless explicitly designed that way.

## Data retention and deletion

Disconnect, conversation/contact deletion, workspace export, and account deletion require explicit ownership checks and predictable cascading/soft-delete behavior. Provider-side deletion is separate from deleting a local record and should be attempted only when the API supports it, with failures made visible to the user.

## Operational security

- Restrict trusted proxies in production instead of leaving `TRUSTED_PROXIES=*` when infrastructure IPs are known.
- Use HTTPS and secure cookies in production.
- Rotate provider secrets after exposure and invalidate old tokens.
- Keep PHP, Composer, npm dependencies, and provider API versions under review.
- Keep debug mode disabled in production and send actionable errors to logs/Sentry rather than displaying stack traces.
