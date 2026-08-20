# Architecture

Last verified against code: 2026-08-21.

## System shape

WisperBot is a modular Laravel 12 monolith with an Inertia/React frontend. It serves four primary surfaces:

1. Public marketing, blog, CMS, install, license, and authentication pages.
2. Client web application under `/app`.
3. Super Admin application under `/admin`.
4. JSON APIs under `/api/v1`, public widget APIs under `/widget/v1`, and provider webhooks under `/webhooks`.

MySQL is the durable store. Laravel queues handle inbound messages, AI indexing, broadcasts, social publishing, automations, ecommerce synchronization, and notifications. Pusher or Reverb provides realtime browser/mobile updates.

## Technology

- PHP 8.2+, Laravel 12, Sanctum, Socialite, Inertia Laravel.
- React 19, Inertia React, Vite 6, Tailwind CSS.
- MySQL; cache/session/queue drivers are environment-configurable.
- S3-compatible storage is supported through Flysystem.
- PHPUnit, Vitest, ESLint, Pint, and PHPStan provide verification.

## Module boundaries

Modules are auto-discovered by `App\Providers\ModuleServiceProvider` from `app/Modules/*`.

| Module | Responsibility |
| --- | --- |
| `Shared` | Contacts, segments, conversations, messages, channel accounts, common contracts/services. |
| `Inbox` | Omni-channel agent inbox, website widgets, Meta/Telegram/email/eBay/Amazon setup, presence, notes, labels, canned replies. |
| `Whatsapp` | WABA/phone setup, templates, auto replies, inbound WhatsApp processing, WA chatbot widgets. |
| `Social` | Social OAuth accounts, composer, scheduled publishing, provider capability handling, token refresh. |
| `AI` | Provider configuration, knowledge bases, indexing, vector retrieval, smart bots, playground. |
| `Broadcasting` | SMS gateways, campaigns, recipients, launch/finalization, usage metering. |
| `Automation` | Trigger/action definitions, automation runs, delayed execution, webhook triggers. |
| `Ecommerce` | Store OAuth/configuration, products, orders, customers, webhooks and automation context. |
| `Integrations` | Encrypted integration configuration and integration management foundations. |
| `Leads` | Legacy lead-related models/jobs; client lead scraper UI/integration is intentionally not a product feature. |

Cross-cutting application code in `app/` owns accounts, workspaces, billing, licensing, admin, mobile APIs, notifications, audit logs, media, CMS/blog, and deployment commands.

## Tenancy and ownership

- A client may have multiple workspaces and team members.
- The active workspace is derived from the authenticated user's current/primary workspace and membership.
- Workspace-owned records must always be scoped by `workspace_id`, directly or through an owned parent.
- Provider identities (Page, Instagram account, WABA, seller account) are intentionally prevented from routing to multiple workspaces when that could duplicate or leak messages.
- Broadcast authorization uses `BroadcastChannelsServiceProvider`, which checks primary/current workspace, pivot membership, ownership, and same-client access.
- Jobs receive durable record identifiers and must re-check ownership/state when they execute.

## Request and event flow

### Inbound provider message

1. Provider calls a `/webhooks/...` route.
2. Controller verifies provider challenge/signature/token and applies inbound idempotency.
3. Expensive parsing is dispatched to a named queue.
4. The processor resolves a workspace-scoped channel account, contact, conversation, and message.
5. `MessageReceived` triggers automations, AI/auto-reply behavior, outbound developer webhooks, notifications, and realtime broadcasts.

Meta Messenger and Instagram webhook processing currently uses the `whatsapp` queue despite the broader channel name; production workers must include it.

### Website widget

1. `/widgets/chat/{key}.js` returns the embed loader.
2. `/widget/v1/session` creates/resumes a visitor-private session.
3. Send, poll, typing, and human-handoff endpoints operate on that private identity.
4. Signed user identity is accepted only when its server-generated HMAC verifies; otherwise the visitor remains anonymous.
5. The resulting conversation appears as `webchat` in the Omni Channel Inbox and is broadcast to workspace agents.

### AI knowledge base

1. A client creates a knowledge base and adds text, URL, sitemap, or file content.
2. `IndexDocumentJob` runs on the `ai` queue, extracts/chunks text, requests embeddings, and stores vectors.
3. MySQL vector-like storage is the functional fallback; Qdrant is optional for scale.
4. Smart bots retrieve workspace/knowledge-base scoped context before generating a concise answer.

### Social publishing

1. Client selects one or more connected social accounts and composes media/content.
2. A post and per-account mappings are stored.
3. Immediate/delayed `PublishSocialPostJob` runs on `social`; the scheduler is a safety net.
4. Provider results store remote IDs and capability information used to show valid edit/delete actions.

## Route organization

- `routes/web.php`: public site, blog/CMS, billing webhooks, health endpoints.
- `routes/client.php`: client account, workspace, billing, settings, developer add-on.
- `routes/admin.php`: Super Admin and system configuration.
- `routes/api.php`: mobile API plus paid developer API.
- `routes/webhooks.php`: provider callbacks; CSRF-exempt and controller-verified.
- `routes/console.php`: scheduler definitions.
- `app/Modules/*/routes/*.php`: domain UI routes.

## API boundaries

- Browser web app: session cookie, CSRF, verified client user, workspace scope.
- Mobile app: Sanctum bearer token under `/api/v1/mobile`; private broadcast auth uses `/api/v1/broadcasting/auth`.
- External developer API: `/api/v1` plus the paid `developer_tools` add-on and token abilities.
- Public widget: throttled, key/session based, no client authentication.
- Webhooks: public transport surface with provider verification and idempotency.

## Queues

Production must process:

- `default` — email sync, exports, queued notifications and general work.
- `whatsapp` — WhatsApp plus Meta Messenger/Instagram inbound processing.
- `broadcast` — campaign launch/chunks/messages/finalization.
- `ai` — document indexing and AI background work.
- `social` — social publishing, seller/Telegram sync work.
- `leads` — retained legacy lead jobs.
- `automation` — automation runs and delayed steps.

There is one inconsistency to avoid extending: one API controller dispatches to `automations` (plural), while production uses `automation` (singular). See `KNOWN_ISSUES.md`.

## Realtime

Pusher settings can be stored in the database by Super Admin and override environment configuration at boot. Private channels include `workspace.{workspaceId}`, `conversation.{conversationId}`, and `presence-conversation.{conversationId}`. Mobile clients authenticate channel subscriptions with Sanctum at `/api/v1/broadcasting/auth`; browser clients use `/broadcasting/auth` with session/CSRF.
