# Architecture

Last verified against code: 2026-09-03.

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

### Managed AI credits

All production text generation routes through `App\Modules\AI\Services\LlmGateway`. The gateway requires a known feature key from `config/ai_credits.php`, chooses the workspace's managed/BYOK/automatic mode, and creates an account-scoped idempotent ledger reservation before a managed provider call. Success moves reserved credits to used; provider failure, timeout, moderation/malformed-output rejection, and stale ten-minute reservations refund them. BYOK calls are recorded at zero managed credits. Embeddings remain zero-credit infrastructure.

Managed generation resolves the tested, enabled Super Admin AI / LLM integration marked as default. Alibaba Qwen 3.7 Flash is a Super Admin-only generation provider with a server-derived, region-specific Model Studio endpoint; it is not accepted through workspace BYOK. OpenAI or Gemini remains required for Knowledge Base embeddings.

`ai_credit_periods` are owned by the Client organization when a workspace belongs to a Client, otherwise by the workspace owner. The current subscription controls the finite `limits.ai_credits_per_month` allowance. Periods follow monthly subscription-anniversary boundaries even on annual billing; upgrades may raise an open allowance, downgrades do not shrink it until the next period, and unused credits never roll forward.

Crossing 80% and 100% stores one threshold timestamp per period before dispatching database, realtime, and email notifications, so concurrent completions cannot duplicate alerts. An AI-dependent automation that cannot reserve credits is stored as `paused`, keeps its current node cursor, and may be explicitly retried from its run history after credits or a tested BYOK provider become available.

## Tenancy and ownership

- A client may have multiple workspaces and team members.
- The active workspace is derived from the authenticated user's current/primary workspace and membership.
- Workspace-owned records must always be scoped by `workspace_id`, directly or through an owned parent.
- Provider identities (Page, Instagram account, WABA, seller account) are intentionally prevented from routing to multiple workspaces when that could duplicate or leak messages.
- Broadcast authorization uses `BroadcastChannelsServiceProvider`, which checks primary/current workspace, pivot membership, ownership, and same-client access.
- Jobs receive durable record identifiers and must re-check ownership/state when they execute.

## Request and event flow

### WhatsApp health operations

`WhatsappConnectionHealthService` owns scheduled/onboarding/manual checks, separate `whatsapp_connection_health` snapshots, and workspace-scoped `whatsapp_connection_operations` history. Jobs carry operation/workspace IDs, serialize per WABA with locks, and discard results when credentials, phone membership, or connection state change. Credential fingerprints use HMAC; no plaintext credential is persisted in diagnostics. Provider calls are isolated in `WhatsappHealthProbe`, with a cached platform check and rate-limit backoff. Receipt and processing evidence is best-effort and cannot block webhook dispatch. Existing account status continues controlling routing independently of health.

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

### WhatsApp Coexistence

1. The client chooses the existing WhatsApp Business app path; the browser starts Meta Embedded Signup with the Coexistence feature type.
2. The callback stores the workspace-scoped WABA/phone token, subscribes Coexistence webhook fields, deliberately skips Cloud API phone registration, and requests contact then history sync.
3. The global WhatsApp webhook verifies and queues all payloads on `whatsapp`.
4. `WhatsappDriver` imports `history` without emitting `MessageReceived`, so historical content cannot trigger AI/automations or unread counts.
5. `smb_message_echoes` are persisted as outbound human messages and emit `MessageSent` for realtime inbox display. All lookups remain scoped through the matching phone `ChannelAccount` and its workspace.

### AI knowledge base

Knowledge Base client authoring exposes URL, file, and sitemap ingestion. Text, FAQ, and dedicated `video` source types remain API/data compatible for existing clients. During extraction, validated YouTube, Vimeo, and public HTTPS MP4 links found in pages or files are normalized into trusted metadata. The surrounding guidance is embedded normally, and the reranker may attach at most one video only when the passage containing that link clears the chatbot's `video_match_threshold`. Widget, web inbox, AI chat API, and mobile message serializers use the same versioned `resources` payload. WhatsApp, Messenger, and Instagram receive a canonical link appended to the text response.

1. A client creates a knowledge base and adds a focused URL, reviewed file, or sitemap. Compatible older records may still use text, FAQ, or dedicated-video types.
   The client-facing Website option accepts either a homepage or sitemap: indexing resolves safe redirects, HTML declarations, `robots.txt`, and common sitemap paths before falling back to same-host links. All candidates pass DNS/IP SSRF checks and the configured page cap.
2. `IndexDocumentJob` runs on the `ai` queue, extracts/chunks text, requests embeddings, and stores vectors.
3. MySQL vector-like storage is the functional fallback; Qdrant is optional for scale.
4. Smart bots retrieve workspace/knowledge-base scoped context before generating a concise answer.

### Social publishing

1. `GET /app/social/automation` supplies the workspace-scoped account summary, post tabs/counts, filters, and on-demand calendar data without serializing provider credentials. Legacy list/account/calendar URLs redirect to this canonical workflow.
2. Client selects connected accounts and composes content at `/app/social/automation/schedule`, explicitly choosing scheduled or immediate delivery.
3. A post and per-account mappings are stored.
4. Immediate/delayed `PublishSocialPostJob` runs on `social`; the scheduler is a safety net.
5. Provider results store remote IDs and capability information used to show valid edit/delete actions.
6. `DELETE /app/social/posts/{post}` performs capability-checked provider deletion before local deletion. `DELETE /app/social/posts/{post}/local` is a workspace-scoped recovery route available only for orphaned published mappings whose connected account is unavailable; it never calls the provider.

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
# Guarded Knowledge Base pipeline (2026-09-04)

When `KB_GUARDED_PUBLISHING=true`, Knowledge Base writes create or modify a draft revision. Add/remove operations change only draft membership; editing, reindexing, or toggling a source inherited from a published revision creates a draft document copy before mutation. `IndexDocumentJob` uses the `ai` queue for extraction, deterministic quality checks, section-aware chunks, embedding reuse, and regression gating. Only `published_revision_id` is passed into chatbot retrieval. The relational revision-document link—not a mutable document flag alone—is the authority for revision membership, including Qdrant results that are post-filtered against MySQL.

Exact approved FAQ answers and safe revision-keyed cache hits return without generation. Query embeddings are model-keyed and reused for seven days. Unsupported business queries record score-only diagnostics plus a hashed knowledge-gap key and follow the configured clarify/handoff action.
