# Architecture

Suggested reply contracts and Flutter rollout are specified in [Suggested customer replies](CHAT_REPLY_OPTIONS.md). Generation stays on the existing gateway and credit path, while the visitor API remains unchanged. The agent inbox shows choice labels read-only.

Closed-question generation explicitly requests matching choices. `ChatReplyOptions` can recover limited unambiguous English closed-choice prompts when enabled; both conversation and API runners use the same normalization after generation. No second model call or extra charge is introduced.

Last verified against code: 2026-09-09.

## System shape

Static analysis reads both `database/migrations` and `app/Modules/*/database/migrations`, parses model `casts()` methods, and uses declared related-model generics on direct Eloquent relationships. Keep these contracts aligned with the actual schema and relationships; this configuration does not change runtime database schema or relax the analysis level.

WisperBot is a modular Laravel 12 monolith with an Inertia/React frontend. It serves four primary surfaces:

1. Public marketing, blog, CMS, install, and authentication pages.
2. Client web application under `/app`.
3. Super Admin application under `/admin`.
4. JSON APIs under `/api/v1`, public widget APIs under `/widget/v1`, and provider webhooks under `/webhooks`.

MySQL is the durable store. Laravel queues handle inbound messages, AI indexing, broadcasts, social publishing, automations, ecommerce synchronization, and notifications. Pusher or Reverb provides realtime browser/mobile updates.

## Signup plan invariant (2026-09-18)

`PlanSelectionService` resolves the first enabled zero-cost plan by `sort_order` and ID. It is the only no-checkout activation path and creates an idempotent active `Subscription` with gateway `free`. `RegisteredUserController`, new social OAuth accounts, and new Firebase accounts activate that plan inside the account-creation transaction. If no initial Free plan exists, account creation rolls back and fails closed. An optional enabled `plan_id` and supported billing cycle from public Pricing are treated only as paid checkout intent; paid plans become effective only after provider fulfilment.

`EnsurePlanSelected` remains a recovery safeguard for clients marked `plan_selection_required=1` in `client_settings`. New registrations normally satisfy it immediately through their Free subscription. If a marked account has no effective user, admin-assigned client, or same-client self-service plan, it is redirected to `client.pricing`; JSON requests receive HTTP 402 with the safe `plan_required` code. Only Pricing, Free recovery, Checkout, and Billing fulfilment remain reachable. Legacy clients are not silently disabled. This uses existing plan, subscription, and client-setting tables; no schema migration is required.

## Public marketing catalogue (2026-09-18)

The public Inertia surface uses `config/marketing.php` and `App\Support\MarketingCatalog` for 14 allowlisted pillar pages and provider capability metadata. `LandingController::pillar` rejects unknown slugs; `routes/web.php` includes public pillars in the sitemap only when the landing site is enabled. `HandleInertiaRequests` shares the safe marketing catalogue and public Site Content projection only with marketing/blog/CMS routes, not client APIs.

Global copy, FAQs, SEO, official download destinations, and CTAs remain in existing `SystemSetting` records; no schema migration is required. `LandingPageController` validates an allowlist and HTTPS/official-host constraints before writing. Legacy seeded proof fields are not serialized; version-two copy replaces legacy defaults in the public projection without deleting stored rows. Pricing uses enabled `Plan` values. Free branding claims depend on the real free-plan entitlement; social comment claims depend on the existing feature flag and remain permission-qualified. No provider, widget, mobile, or SDK contract changes are introduced. See [Public Website Design](DESIGN.md).

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
| `Inbox` | Omni-channel agent inbox, website widgets, server-evaluated widget AI schedules, Meta/Telegram/email/eBay/Amazon setup, presence, notes, labels, canned replies. |
| `Whatsapp` | WABA/phone setup, templates, auto replies, inbound WhatsApp processing, WA chatbot widgets. |
| `Social` | Social OAuth accounts, composer, scheduled publishing, provider capability handling, token refresh. |
| `AI` | Provider configuration, knowledge bases, indexing, vector retrieval, smart bots, playground. |
| `Broadcasting` | SMS gateways, campaigns, recipients, launch/finalization, usage metering. |
| `Automation` | Trigger/action definitions, automation runs, delayed execution, webhook triggers. |
| `Ecommerce` | Store OAuth/configuration, products, orders, customers, webhooks and automation context. |
| `Integrations` | Encrypted integration configuration and integration management foundations. |
| `Leads` | Legacy lead-related models/jobs; client lead scraper UI/integration is intentionally not a product feature. |

Cross-cutting application code in `app/` owns accounts, workspaces, billing, admin, mobile APIs, notifications, audit logs, media, CMS/blog, and deployment commands.

### Managed AI credits

All production text generation routes through `App\Modules\AI\Services\LlmGateway`. The gateway requires a known feature key from `config/ai_credits.php`, chooses the workspace's managed/BYOK/automatic mode, and creates an account-scoped idempotent ledger reservation before a managed provider call. Success moves reserved credits to used; provider failure, timeout, moderation/malformed-output rejection, and stale ten-minute reservations refund them. BYOK calls are recorded at zero managed credits. Embeddings remain zero-credit infrastructure.

Managed generation resolves the tested, enabled Super Admin AI / LLM integration marked as default. Alibaba Qwen 3.7 Flash is a Super Admin-only generation provider with a server-derived, region-specific Model Studio endpoint; it is not accepted through workspace BYOK. OpenAI or Gemini remains required for Knowledge Base embeddings.

`ai_credit_periods` are owned by the Client organization when a workspace belongs to a Client, otherwise by the workspace owner. The current subscription controls the finite `limits.ai_credits_per_month` allowance. Periods follow monthly subscription-anniversary boundaries even on annual billing; upgrades may raise an open allowance, downgrades do not shrink it until the next period, and unused credits never roll forward.

Crossing 80% and 100% stores one threshold timestamp per period before dispatching database, realtime, and email notifications, so concurrent completions cannot duplicate alerts. An AI-dependent automation that cannot reserve credits is stored as `paused`, keeps its current node cursor, and may be explicitly retried from its run history after credits or a tested BYOK provider become available.

## Tenancy and ownership

- A client may have multiple workspaces and team members.
- Browser web requests may carry a session-scoped `current_workspace_id` selected by the workspace switcher. `ResolveWebWorkspace` overlays that value onto the in-memory request user so existing web controllers can read `workspace_id`, but switching in the web UI must not persistently overwrite `users.workspace_id`.
- Mobile and developer API requests use `users.workspace_id` as their active workspace until the API workspace selector explicitly changes it.
- Workspace-owned records must always be scoped by `workspace_id`, directly or through an owned parent.
- Provider identities (Page, Instagram account, WABA, seller account) are intentionally prevented from routing to multiple workspaces when that could duplicate or leak messages.
- Broadcast authorization uses `BroadcastChannelsServiceProvider`, which checks primary/current workspace, pivot membership, ownership, and same-client access.
- Jobs receive durable record identifiers and must re-check ownership/state when they execute.

### Workspace-scoped notifications

Client notifications carry an immutable `workspace_id` captured from their source record before queueing. The customized Laravel database and broadcast channels add that scope to stored/realtime payloads; OneSignal and web-push payloads carry the same identifier. Web and Sanctum notification list, unread-count, read-all, read-one, and delete operations resolve the active accessible workspace and cannot mutate another workspace's records. Background producers resolve recipients from workspace ownership and pivot membership rather than only `users.workspace_id`, so a user with multiple workspace memberships receives each event under its originating workspace. Account-wide preferences remain user-level.

Mobile clients use `GET /api/v1/notifications`, `GET /api/v1/notifications/unread-count`, `POST /api/v1/notifications/read-all`, `POST /api/v1/notifications/{notification}/read`, and `DELETE /api/v1/notifications/{notification}` after selecting the active workspace. Responses and push payloads include `workspace_id`; a client must switch to or validate that accessible workspace before opening a notification deep link.

Master Email Inbox alerts have a separate user-level source preference. `users.email_inbox_notifications_enabled` defaults to true; when false, an inbound message with `messages.channel=email` is still synchronized and remains visible/unread in the email inbox, but `NewMessageNotification` selects no database, broadcast, web-push, or OneSignal channel for that user. Non-email messages and all outbound/authentication/billing email behavior are unchanged. Mobile login and `GET /api/v1/auth/me` expose the additive boolean, and `PUT /api/v1/notifications/preferences/email-inbox` accepts required boolean `enabled` and returns only the resulting preference. Native-app UI/repository adoption remains owned by the app team.

Workspace member availability is stored separately per workspace and evaluated in each member's IANA timezone. It filters only inbox new-message and human-handoff notifications. An available joined owner receives the alert alone; when that owner is off shift, other available members receive it. Realtime inbox updates and unread counts are never suppressed.

Conversation routing and live handling are separate: `assigned_user_id` may be set by a manager or automation, while `joined_user_id` is acquired atomically through Join Chat. Only the joined owner may send human replies. Successful join, takeover, leave, explicit assignment/unassignment, status changes, and resolution write durable system activity in the same locked transaction; resolving a conversation also clears its unread count. Automatic inbound reopen records a staff-only activity while clearing stale resolution, AI, and ownership state. Repeated no-op actions create no duplicate activity. Echoes, delivery callbacks, historical imports, and activity records do not reopen conversations. Mobile parity is provided by `/api/v1/mobile/conversations/{uuid}/join`, `/leave`, `/takeover`, `/assign`, and `/status`.

## Request and event flow

### WhatsApp health operations

`WhatsappConnectionHealthService` owns scheduled/onboarding/manual checks, separate `whatsapp_connection_health` snapshots, and workspace-scoped `whatsapp_connection_operations` history. Jobs carry operation/workspace IDs, serialize per WABA with locks, and discard results when credentials, phone membership, or connection state change. Credential fingerprints use HMAC; no plaintext credential is persisted in diagnostics. Provider calls are isolated in `WhatsappHealthProbe`, with a cached platform check and rate-limit backoff. Receipt and processing evidence is best-effort and cannot block webhook dispatch. Existing account status continues controlling routing independently of health.

### Inbound provider message

1. Provider calls a `/webhooks/...` route.
2. Controller verifies provider challenge/signature/token and applies inbound idempotency.
3. Expensive parsing is dispatched to a named queue.
4. The processor resolves a workspace-scoped channel account, contact, conversation, and message.
5. If the stored customer message belongs to a resolved conversation, the processor atomically reopens that same conversation before downstream listeners execute.
6. `MessageReceived` triggers automations, AI/auto-reply behavior, outbound developer webhooks, notifications, and realtime broadcasts.

One workspace-level Omni policy covers WhatsApp, Messenger, Instagram DMs, Telegram Business, and eBay; a separate workspace-level Email policy covers every mailbox. The selected segment policy is evaluated after deterministic rules and handoff detection on every inbound, including threads previously routed to humans only because AI was outside its schedule. Eligible replies are debounced on `ai`, re-check ownership and policy under the final-send lock, and use a durable source-message key. Joining, assignment, handoff, or observed human replies pause AI until resolution. Email additionally applies account connection cutoffs plus loop, authored-body, and attachment-only guards. Amazon order actions, SMS, webchat Appearance, and Social Comments remain separate.

Meta Messenger and Instagram webhook processing currently uses the `whatsapp` queue despite the broader channel name; production workers must include it.

### Website widget

Only joined and resolved ownership activity is exposed to the private widget/customer-SDK session as `role=agent`, additive `kind=activity`, fallback `body`, and redacted `activity.type`/`activity.actor_name`; takeover is presented publicly as the new agent joining. Leave, transfer details, reopen, assignment, unassignment, pending, and snoozed activity remains staff-only. Internal user IDs, email, roles, permissions, and previous-agent details are never public. Activity does not trigger sound, unread badges, push, delivery receipts, quick replies, or message bubbles. Older SDKs can keep rendering the body as an agent message; native activity rendering requires a separate SDK package release and host-app rebuild.

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
   The client-facing Website option accepts a bare domain, homepage, or sitemap. The shared web/API resolver adds HTTPS, preserves the submitted value, tests the entered apex/`www` host pair, follows bounded HTTPS redirects, and verifies the connected public IP. A same-site redirect that points through HTTP is upgraded and probed over HTTPS without making an HTTP request. The verified URL is stored separately as `canonical_url`; HTML declarations, `robots.txt`, and common sitemap paths are then checked before falling back to same-site links. All candidates pass DNS/IP SSRF checks and the configured page cap.
2. `IndexDocumentJob` runs on the `ai` queue, extracts/chunks text, requests embeddings, and stores vectors.
3. MySQL vector-like storage is the functional fallback; Qdrant is optional for scale.
   Root website discovery probes sitemaps before homepage content. Crawling distinguishes HTTP access failures from transport timeouts. `LlmGateway` rejects empty generation before credit finalization and records the resolved runtime model for failed attempts.
4. Smart Bots retrieve workspace/knowledge-base scoped context before generating a concise answer. Unless the client explicitly enables general answers, `unsupported_answer_action` enforces knowledge-only behavior independently of `KB_GUARDED_PUBLISHING`: an empty/low-score retrieval returns the configured fallback before chat generation, and generated JSON must declare a grounded answer before credit finalization. Short replies and CTA selections may include only the nearest two turns in the retrieval query; substantive new topics stand alone so prior business context cannot make an unrelated request appear relevant.

### Social publishing

`SocialCommentCapabilities` is the web/mobile platform catalog. Publishing connection, adapter availability, deployment flag and verified action capabilities are separate. Unsupported providers fail closed before Meta HTTP; moderation is independently checked at enqueue and delivery.

The optional Comments page `/app/social/automation/comments` and `/api/v1/mobile/social/comments` share a scoped controller/service and separate comment storage. `social` handles signed changes, sync, and delivery; `ai` handles public KB generation. Only published revision retrieval is allowed even when the legacy KB feature flag is off. Successful suggestions are charged once; delivery reuses stored text. Cursor-paginated lists and replies use the same additive web/mobile contract. See [Social Comments](SOCIAL_COMMENTS.md).

1. `GET /app/social/automation` supplies the workspace-scoped account summary, post tabs/counts, filters, and on-demand calendar data without serializing provider credentials. Legacy list/account/calendar URLs redirect to this canonical workflow.
2. Client selects connected accounts and composes content at `/app/social/automation/schedule`, explicitly choosing scheduled or immediate delivery.
3. A post and per-account mappings are stored.
4. Immediate/delayed `PublishSocialPostJob` runs on `social`; the scheduler is a safety net.
5. Provider results store remote IDs and capability information used to show valid edit/delete actions.
6. `DELETE /app/social/posts/{post}` performs capability-checked provider deletion before local deletion. `DELETE /app/social/posts/{post}/local` is a workspace-scoped recovery route available only for orphaned published mappings whose connected account is unavailable; it never calls the provider.

## Route organization

Authenticated `/api/v1/mobile/*`, `/api/v1/auth/*`, and `/api/v1/broadcasting/auth` use `throttle:mobile-api`, with a separate per-user budget (default 300/minute, `rate_limits.mobile_api_per_minute` / `MOBILE_API_RATE_LIMIT_PER_MINUTE`). Login uses `mobile-login`; generic/developer routes retain the upstream `throttle:api` allowance (120/minute). Mobile action-specific throttles are still applied in addition. Multiple devices for the same user share the mobile budget. Mobile HTTP 429 responses add `code: mobile_api_rate_limited` and `retry_after`, preserving `Retry-After` and rate-limit headers; clients must back off rather than immediately retrying writes.

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
- Public widget/customer SDK: throttled, key/session based, no client authentication. `/widgets/chat/{key}.js` is website-only and resolves the website `widget_key` with `enabled=true`. `/widget/v1/*` resolves either the website `widget_key` gated by `enabled=true`, or the customer SDK `sdk_widget_key` gated by `sdk_enabled=true`. Both surfaces keep `channel=webchat`; new conversations store `started_from=web_widget|customer_sdk` for source display and additive APIs.
- Webhooks: public transport surface with provider verification and idempotency.

## Queues

Production must process:

- `default` — email sync, exports, queued notifications and general work.
- `whatsapp` — WhatsApp plus Meta Messenger/Instagram inbound processing.
- `broadcast` — campaign launch/chunks/messages/finalization.
- `ai` — document indexing and AI background work.
- `social` — social publishing, seller/Telegram sync work.
- `leads` — retained legacy lead jobs.
- `automation` — automation runs and delayed steps. `ExecuteAutomationRunJob` owns the canonical queue assignment so API, event, retry, sub-flow, and delayed-resume dispatches cannot diverge.

## Realtime

Pusher settings can be stored in the database by Super Admin and override environment configuration at boot. Private channels include `workspace.{workspaceId}`, `conversation.{conversationId}`, and `presence-conversation.{conversationId}`. Mobile clients authenticate channel subscriptions with Sanctum at `/api/v1/broadcasting/auth`; browser clients use `/broadcasting/auth` with session/CSRF.

The browser inbox uses workspace websocket events as the fast path and a safe periodic Inertia reconciliation as a fallback: every 30 seconds with Echo available and every 4 seconds without it. Reconciliation pauses while the page is hidden, while a list request is active, or while the agent is searching or scrolled away, preserving list position and tenant isolation.

Website visitor presence remains timestamp-authoritative through `conversations.webchat_last_seen_at` and the 30-second online window. `WebchatPresence` broadcasts `LiveVisitorUpdated` on the private `workspace.{workspaceId}` channel when a visitor first becomes online or changes page context. The event contains only conversation identifiers, `last_seen_at`, and `online`; browser and mobile clients use it as an immediate reconciliation hint and continue periodic API reconciliation to expire stale visitors or recover missed events.
# Guarded Knowledge Base pipeline (2026-09-04)

When `KB_GUARDED_PUBLISHING=true`, Knowledge Base writes create or modify a draft revision. Add/remove operations change only draft membership; editing, reindexing, or toggling a source inherited from a published revision creates a draft document copy before mutation. `IndexDocumentJob` uses the `ai` queue for extraction, deterministic quality checks, section-aware chunks, embedding reuse, and regression gating. Only `published_revision_id` is passed into chatbot retrieval. The relational revision-document link—not a mutable document flag alone—is the authority for revision membership, including Qdrant results that are post-filtered against MySQL.

Exact approved FAQ answers and safe revision-keyed cache hits return without generation. Query embeddings are model-keyed and reused for seven days. Unsupported business queries record score-only diagnostics plus a hashed knowledge-gap key and follow the configured clarify/handoff action.
# Crawler and managed model validation (2026-09-05)

Website ingestion probes robots/common sitemaps before downloading a root homepage, normalizes validated www/non-www aliases only, and bounds page fetch time. Incomplete responses are not indexed as complete documents. Qdrant collection setup maintains integer payload indexes on `document_id` and `kb_id`; strict-mode filtered cleanup retries after creating missing indexes, without swallowing other failures. Managed OpenAI tests exercise configured runtime models and embeddings; empty generated output is rejected before credit finalization.

# Business-aware Smart Bot routing (2026-09-16)

The private Smart Bot pipeline is conversation routing → workspace-scoped retrieval → optional approved-source research → one generated answer or a non-charged fallback. Social turns are deterministic and zero-cost. Domain relevance uses only the selected tenant Knowledge Base profile and retrieval signals; no tenant data is shared. The public social-comment pipeline is intentionally separate and remains fully grounded in a published revision.

The additive reply contract includes `answer_origin` and safe `citations` while preserving `body`/`reply`, `display_body`, `quick_replies`, and `resources`. All generated paths continue through `LlmGateway` once, retaining fixed-action charging and idempotency.

# Semantic retrieval and grounded clarification (2026-09-16)

With `KB_HYBRID_RETRIEVAL_ENABLED=true`, `KnowledgeRetrievalService` is shared by live private chat, the direct/developer chat API, playground, and Knowledge Base tests. It searches the current customer turn independently, adds prior dialogue only for a genuine short continuation, and excludes social turns and fallback/handoff text. Vector similarity remains the authority; normalized wording and generic character similarity may boost ranking but cannot reduce a semantic score. Workspace, revision, enabled-source, publication, and active-generation filters are applied before any passage is usable.

`SmartBotRetrievalPolicy` owns the runtime passage cap, strong-answer confidence, token budget, and relevant-video threshold. Values come from conservatively bounded platform configuration, not tenant-editable bot fields. Legacy numeric columns stay populated for compatibility, but the client update path ignores them. Public comments derive a smaller and never-less-strict evidence window.

Retrieval returns `answer`, `clarification`, or `fallback`. A clarification contains only selected verified passages and permits one generated narrowing question; repeated clarification is prevented. Index version 2 keeps headings, FAQ pairs, procedures, lists, and each headed multi-turn Customer/AI flow semantically coherent; a country or option supplied on a later turn therefore remains attached to the preceding question and resulting instructions. A pending UUID generation is fully built before `active_index_generation` changes, so extraction/provider failures leave the previous index queryable. Qdrant remains an optional accelerator; MySQL is the filtering authority and fallback.

Private Smart Bot generation requests structured JSON at the provider transport when supported (OpenAI-compatible `response_format` or Gemini JSON MIME output), in addition to prompt and server validation. This prevents a useful plain-text provider answer from being discarded merely because it omitted the response envelope. When `trusted_research_enabled` is set, purchasing and fresh-fact questions may supplement indexed context with a bounded read from already-approved KB website sources; failed research never removes usable indexed evidence.

Live product facts remain outside vector chunks. Product-page indexing can normalize Schema.org JSON-LD or conservative metadata into `ai_kb_products` and `ai_kb_product_offers`; scheduled and request-time refreshes run on the approved canonical source with per-host limits. `LiveProductAnswerService` resolves price/stock intent before generation, enforces workspace/published-revision boundaries, clarifies ambiguous products/variants, prefers a fresh connected-store match on the approved host, and returns a deterministic zero-credit response or a safe unable-to-verify fallback.

# Team avatars in chat (2026-09-16)

Team member photos use the existing `users.avatar` storage path and are written through `StorageManager`; browser, widget, realtime, and Sanctum responses expose only `User::avatarUrl()`. Team mutation routes remain client-administrator-only and client-scoped. Public widget configuration filters the workspace team through `TeamAvailabilityService` before exposing the safe name/photo presence summary. After ownership is joined, `WidgetPayloadBuilder` adds the resolved teammate photo to handoff state and human `agent_avatar_url` message payloads; Smart Bot messages retain company branding.
