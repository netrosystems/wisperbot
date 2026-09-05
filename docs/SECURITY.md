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

### Knowledge Base trust boundary

- Draft, blocked, rejected, degraded, disabled, cross-workspace, and non-published revision content is excluded from live retrieval.
- URL/sitemap ingestion accepts public HTTPS destinations only, rejects credentials, localhost/private/reserved addresses, unsafe redirects, redirect loops, and cross-domain sitemap pages.
- Deterministic review blocks likely secrets/private keys, excessive personal data, unreadable extraction, and prompt-injection-style instructions before embedding/publishing.
- Retrieved document instructions are untrusted reference text. The server derives video embeds and the model cannot provide iframe HTML.
- Diagnostics retain document/revision IDs, scores, decisions, and token counts without logging customer text or secrets. Knowledge gaps use a normalized question hash and a bounded sample visible only within the owning workspace.

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

## Managed AI credits and abuse controls

- Managed credits are finite; a missing/null allowance means zero, never unlimited. They are pooled by organization/standalone billing owner so creating or deleting a workspace cannot mint credits.
- Every managed call reserves credits under a unique account-scoped idempotency hash. Row locks, reserved balances, concurrency limits, and request velocity limits prevent double-spend and final-credit races.
- Free managed credits require a verified account email. Only an HMAC hash of IP plus user-agent is retained for abuse clustering; raw prompts, IP addresses, API keys, and provider responses are not written to operational logs.
- Ledger replay output is encrypted at rest. Workspace provider credentials retain encrypted casts and are never returned to the browser.
- Threshold timestamps are written inside the same locked credit-finalization transaction; this makes 80%/100% alerts once-per-period even when several requests finish together.
- DeepSeek credentials are Super Admin system integrations only. Client provider lists, update/test endpoints, workspace BYOK resolution, and automatic fallback must never expose or accept DeepSeek. If a Super Admin explicitly enables DeepSeek for an applicable system runtime, its data-processing, retention, training, and data-location terms must be reviewed first; Knowledge Base embeddings still require OpenAI or Gemini.
- Alibaba Qwen credentials are Super Admin system integrations only and remain excluded from client provider APIs, BYOK, and automatic fallback. Qwen endpoints are derived server-side from an allowlisted Alibaba region and a validated Workspace ID; administrators cannot supply an arbitrary base URL. API keys and Workspace IDs remain encrypted and masked in the browser.

## Webhooks

WhatsApp health mutations require workspace manager authorization and are scoped by both workspace and WABA. Jobs revalidate credential revision and membership before persisting results. Platform tokens cannot be substituted for customer tokens; operator use requires an explicit deployment allowlist plus matching Graph owner business. Health requests do not follow redirects and keep raw provider errors, tokens, customer text, and message IDs out of health histories. A repair cannot grant access, register a Coexistence number, replay messages, or change shared app callbacks. Monitoring storage failures must not prevent valid webhook dispatch.

CSRF exemptions do not mean “unverified.” Every provider controller must validate the provider signature, secret, challenge token, or equivalent before accepting work. Use `WebhookIdempotencyService`/inbound event records so provider retries do not duplicate messages, posts, orders, or billing transitions.

Webhook endpoints should acknowledge quickly and queue expensive processing. The shared Meta rate limit is intentionally high because many workspaces share Meta egress IPs.

## Website widget identity and privacy

- A visitor transcript is bound to an opaque, stable visitor/session identity and widget key.
- A host website may pass name/email/avatar/external ID, but trusted identity requires a server-generated HMAC using the widget secret.
- Never embed the widget secret in public JavaScript.

### Knowledge video resources

- Never accept or render administrator-supplied iframe HTML. Video URLs are normalized server-side to YouTube, Vimeo, or direct public HTTPS MP4 metadata.
- Reject URL credentials, non-HTTPS schemes, localhost/private IP literals, malformed provider identifiers, and unsupported file types.
- Players load only after an explicit click. `payload.resources` exposes playback metadata but never the stored transcript or trigger phrases.
- Customer sites with restrictive CSP must allow the selected YouTube/Vimeo player origin in `frame-src`, and the direct MP4 origin in `media-src`; every card retains a canonical external-link fallback.
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
