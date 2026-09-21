# Project changelog

This is a documentation-level changelog for user-visible and operationally significant changes. Git history remains the detailed source.

## Unreleased

- Removed the legacy Envato/Botble distribution licensing system pending a new business model. Fresh installs no longer request a purchase code, the Super Admin panel no longer redirects to license activation or exposes “License & Updates,” and WisperBot no longer calls the legacy license/update server. The license-coupled updater was removed with it. Existing subscription plans and customer billing are unchanged.

- Added a production Docker deployment for a single Ubuntu VPS: immutable
  PHP/Vite images, private MariaDB and Redis, hosted Pusher configuration, all
  named queue workers, scheduler, guarded legacy SQL import, pre-migration and
  operator backups, loopback-only HTTP exposure, and host Nginx/Certbot setup.
  The PHP image includes IMAP for generic mailbox sync, and container startup
  repairs persistent Laravel storage ownership before workers begin. An isolated
  Docker test target verifies the backend without adding dev packages to runtime
  images; the test command now matches its installed dependencies. Refreshed the
  production PHP dependency lock to remove all reported security advisories.

- Added LinkedIn Company Page publishing. Clients can connect the Pages they administer and post as the Page, choosing targets on a new screen after authorization. LinkedIn's Community Management API must be the only product on its app, so Company Pages use a second LinkedIn app: admin fills optional Company Page Client ID/Secret on the LinkedIn integration, and the Connect dialog then offers “LinkedIn Company Page” beside the personal connection. Each connection stores its actor type and publishes as person or organization; rows from one authorization share and rotate one token, refreshed with that app's keys. Existing personal-profile connections are unaffected.

- Replaced the browser `confirm()` pop-up on every delete-type button (39 across client and admin pages: delete, remove, disconnect, revoke, move to trash, delete all) with a shared in-app confirmation dialog. Chrome silently suppresses repeated native prompts, which made Delete appear to do nothing after several deletions (reported on a Knowledge Base source). New `@/Components/ConfirmDialog` (`confirmDialog()` + `ConfirmDialogHost` in `app.jsx`), tests, and a guard test that fails if a delete button uses `confirm()` again. The 13 non-delete confirmations (publish, takeover, cancel subscription, rotate secret, etc.) are unchanged.

- Fixed Website widget Appearance saving: with an AI schedule enabled, every save was rejected ("Enter a valid time") because multipart form data encoded schedule windows as `windows[][start]` / `windows[][end]`, which PHP splits into separate items. Settings and Create now send `queryStringArrayFormat: 'indices'`. The form also showed no validation errors (they arrived as page props, not on its `useForm` instance); it now names the first problem and shows “Saving…” while submitting. Clearing every allowed domain (or every pre-chat field) now saves: multipart data omits empty arrays, so the controller treats a missing list as empty.

- Made the full test suite pass on `oris` after adopting `main` (913 backend tests). App fixes found along the way: cached message media is matched by exact message id (message 1 could previously be served message 12's cached file); Instagram/Messenger app webhooks are registered with the public `APP_URL` instead of the host the admin browsed from; new `User` records report the email-inbox alert preference as on (matching the column default), and the preference is mass-assignable. Test-only corrections: tests never call the external license server, Developer API tests enable the Developer Tools add-on, conversation/campaign/contact URLs use their uuid route keys, and several tests were aligned with current behaviour (signed media route, six-per-minute WhatsApp health checks, resolution no-op, `.env`-independent Inertia version).

- Added Smart Bot **starter questions**: clients save up to five questions with fixed answers and switch them on or off in Smart Bot settings. Website-chat customers see them under the welcome message at the top of every chat; tapping (or typing the same question) returns the saved answer instantly with no AI call and zero credits (`answer_origin=starter_question`). The customer SDK receives `config.starter_questions` from the session endpoint and sends the label as a normal message. Exposed read-only on the AI chatbot API. Requires migration `2026_09_19_000300_add_starter_questions_to_ai_chatbots.php`, the updated `public/widget/wisperbot-chat-widget.js`, a dashboard build, and a worker restart.

- Added experimental live product pricing for Smart Bots behind `KB_LIVE_PRODUCT_FACTS_ENABLED`: approved product pages are auto-detected from structured data, normalized into non-vector product/offer records, refreshed every 15 minutes on `ai`, and reverified before stale facts are used. Exact price/availability replies and product/variant clarifications are deterministic and zero-credit, prefer fresh same-host connected-commerce data, preserve text/CTA compatibility, and add optional `answer_origin=live_product`, citations, and safe `product_facts`. Requires migration `2026_09_19_000100_create_live_kb_products.php`, scheduler, config-cache refresh, and worker restart.

- Moved Smart Bot retrieval tuning behind a bounded platform-managed policy. Clients now configure business-facing answer behavior without editing raw passage counts, confidence thresholds, token budgets, or video thresholds; legacy fields remain compatible, and public social replies keep a stricter evidence policy.

- Redesigned the shared admin/client operational interface on experimental `oris`: a compact light/dark application shell, 236px grouped navigation, mobile topbar drawer, visible existing admin search, orange active rail, normalized controls and surfaces, priority-first dashboards, and direct links to the existing Inbox, Channel Setup, Smart Bots, Knowledge Bases, and Team journeys. Authentication now uses the same light/dark operational language. No feature, permission, route, API, schema, SDK, or entitlement behavior changed.

- Replaced the homepage hero's “Explore the platform” action with **Get Agent App**. The action now opens a minimal keyboard-accessible iOS/Android dropdown linked to the official WisperBot App Store and Google Play listings.

- Redesigned Sign in and Register to match the product-led website: a truthful graphite product story, recognizable channel artwork, focused responsive forms, full dark mode, and accessible controls. Removed fabricated auth-page customer, message-volume, uptime, productivity, and conversion claims. The streamlined automatic-Free and paid-intent signup journey is unchanged.

- Simplified new-client conversion to a standard SaaS signup: registration no longer repeats the plan grid. Direct password, OAuth, and Firebase signups transactionally start on the configured Free plan with no card. A paid package chosen on public Pricing is shown as one compact intent summary, then continues to checkout after account creation; abandoning checkout retains usable Free access. The defensive no-plan gate, teammate organization access, and server-derived onboarding milestone remain. No database migration or application-version bump is required.

- Replaced the public website's remaining letter and Unicode provider placeholders with centralized, recognizable local brand SVGs across the channel ribbon, integration directory, capability surfaces, and product illustrations. Gmail, Microsoft Mail, Telegram, social, commerce, and marketplace identities now retain their own artwork, while non-brand concepts and interface actions consistently use Lucide. Unknown integrations no longer degrade to misleading single-letter logos.

- Rebuilt the homepage opening as a dark, cinematic WisperBot hero after direct Voiskey behavior inspection: a short brand launch, staggered text entrance, pointer-responsive dotted network, orange/amber horizon glow, and one rotating signal between “Every” and “customer.” The signal now uses real local WhatsApp, Facebook, Instagram, Messenger, Telegram, LinkedIn, X, TikTok, and YouTube SVG marks instead of generic email/chat symbols. The sticky header is dark over the hero and smoothly returns to white over page content. Removed the surrounding icon cloud. Coarse pointers, reduced motion, manual pause, accessible text, and client-authored headline fallback are protected. No backend, API, entitlement, or external animation-asset changes.

- Rebuilt the public website as a full product-led experience: richer mega navigation, 14 product/solution/channel/developer pages, an interactive homepage product tour and lifecycle, lightweight animated product illustrations, provider capability matrix, mobile Agent App and separate customer SDK story, role-based solutions, and deeper product explanations. Refreshed Pricing, Integrations, FAQ, About, Contact, Blog, Use Cases, and CMS presentation. Added safe admin-managed official app-store/pub.dev destinations, categorized FAQs, and versioned public copy; absent download links stay hidden. Real plan values determine pricing and free branding claims, and social-comment marketing follows its rollout flag. Removed fictional proof and unverified guarantees. Includes reduced-motion support, a manual motion pause, accessible menus/tabs, responsive layouts, regression tests, and durable design documentation. Experimental on `oris`; no migration, SDK/API behavior change, production release, or manual version bump.

- Restored the approved website-widget shell as one consistent implementation: compact white identity header, scheduled teammate stack with `Team available now`, slim peach top-positioned `Talk to an agent` control, outlined reply pills, voice recording, elevated panel shadow, and a shorter peach Powered by footer. Human replies retain joined-teammate identity while Smart Bot replies retain the company mark.

- Added an authoritative Knowledge Base and Smart Bot guide covering business-aware routing, semantic retrieval, grounded clarification, approved-source research, evidence boundaries, credits, customer payloads, teammate identity, feature flags, rollout, and native SDK responsibilities. The documentation index and agent routing rules now require it for future AI/KB work.

- Added client-admin teammate photo upload, preview, replacement, and removal on Team. Website chat now shows scheduled teammates in the header, the actual joined teammate in handoff state, and that teammate's photo on human replies instead of the company mark. Browser realtime and mobile APIs now return resolved avatar URLs through additive compatibility fields. Corrected the multipart Edit member submission so Save sends the update instead of failing in the browser before the request. No database migration or application version bump is required.

- Added experimental semantic Knowledge Base retrieval behind `KB_HYBRID_RETRIEVAL_ENABLED`: current-turn-first vector, wording, and typo matching; grounded clarification for related ambiguity; active-generation filtering; smaller meaning-preserving chunks; safe atomic reindexing; active-first rollout command; richer tester diagnostics; and additive `response_mode` across web/widget/API/mobile-compatible payloads. Migration `2026_09_16_000300_add_index_generations_to_knowledge_base_chunks.php` and a controlled `ai` reindex are required.
- Hardened Smart Bot buying guidance: headed multi-turn examples now retain their complete intent, lexical/fuzzy evidence cannot be dominated by one common word, capable providers receive a JSON-output constraint, and enabled approved-source research can enrich relevant purchase or fresh-information answers without replacing usable KB evidence.

- Added experimental business-aware Smart Bot routing with zero-credit localized social replies, separate Business only / Verified sources only / General assistant scopes, conservative domain guidance, optional approved-source research with citations, compatible widget/mobile/API metadata, and richer safe diagnostics. The feature defaults off behind `SMART_BOT_BUSINESS_AWARE_ROUTING`; deployment requires its migration, matching frontend assets, cache refresh, and worker restart.

- Added guarded Omni inbox reconciliation so incoming conversations appear within four seconds when local realtime is not configured, while retaining websocket delivery plus a slower recovery reconciliation in configured environments. No migration or mobile API change is required.

- Normalized outbound provider images for staff web/mobile sends: JPEG/PNG pass through, HEIC/HEIF/WebP/GIF convert to JPEG for WhatsApp, Messenger, Instagram and Telegram, WhatsApp media upload follows the conversation-bound phone number, and provider delivery errors are retained for diagnosis.
- Upgraded the public blog and editorial CMS with structural HTML repair, safe deterministic heading anchors, a visible table of contents, reading progress, meaningful freshness labels, Breadcrumb/FAQ structured data, responsive comparison tables, HTML-source editing, table and divider tools, and live content-quality signals. Legacy malformed articles are repaired on read without destructive database rewrites and persist normalized markup on their next save.

- Expanded the staff conversation audit timeline with transfer, leave, assignment, unassignment, reopen, pending, and snoozed activity. No-op transitions remain deduplicated; widget/SDK customers continue to receive only joined/resolved activity, with takeover safely represented as the new agent joining.

- Fixed website-widget conversation ordering when a missed realtime activity is recovered after a newer cached message; recovered rows now return to their canonical message-ID position instead of appearing at the end.

- Scoped website-widget cached history to the server-confirmed conversation and made session history authoritative, removing stale messages carried across renewed visitor sessions. Initial history now returns the latest 100 messages in chronological order.

- Fixed same-conversation Omni Inbox refresh reconciliation so server messages recovered after a missed realtime event merge into the open timeline instead of being ignored until the agent navigates away.

- Added durable joined/resolved conversation activity rows across staff web, MasterBox, staff mobile, website widget, and the public-safe customer SDK contract. Ownership state and activity are committed atomically; duplicate no-op actions, provider sends, AI/automation/webhooks, notifications, unread/SLA, list ordering, and content previews remain unaffected. Customer SDK package release remains a separate app-team rollout.

- Added a per-user Master Email Inbox alert switch on the web. Turning it off suppresses in-app, realtime, browser-push, and mobile-push notifications for newly synchronized inbound email while mailbox sync, unread email state, replies, and every non-email channel continue normally. The additive mobile profile field and authenticated update endpoint are ready for app-team integration; existing users default on.

- Resolved inbox conversations now reopen as Open/Unassigned when the same customer sends a genuine new message. The existing transcript and conversation link are retained, stale ownership and AI-pause state are cleared, and the web Resolved/Unassigned views reconcile in realtime; provider history, echoes, and status callbacks remain non-reopening.

- Separated website widget availability from customer mobile SDK availability on the same widget. Website embeds keep using the website key and `Widget enabled`; customer SDK apps get a separate SDK key and `SDK enabled`, with existing widgets and old SDK builds remaining available by default.
- Added webchat source detection for newly created conversations: website widget chats keep the existing website icon, customer SDK chats can show a mobile/app icon, and conversation APIs expose additive `started_from` metadata while old conversations remain unlabelled.

- Raised dedicated authenticated mobile API headroom from a shared 60 to an isolated configurable 300 requests/minute/user, including mobile profile and private-channel authorization. Login, generic/developer API, and stricter action-specific limits remain unchanged. Mobile throttling now provides a structured 429 reason and retry delay.

- Moved the mobile inbox menu from a floating composer overlay to a compact top bar shared by Omni chat and Email MasterBox. Mobile chat now uses the full width with a return-to-inbox link, narrow-screen lists retain collapsible filters, and the send button has a 44px touch target. No API changes.

- Redesigned the shared Omni and Email AI Answering control as a compact operational settings row with clear mode, bot, and timezone context. Configuration now uses a focused, responsive drawer with progressive disclosure and an explicit Save step, reducing page clutter and accidental changes.

- Removed OAuth callback URLs and provider-configuration diagnostics from client Email Setup; those operational details remain a Super Admin responsibility.

- Replaced per-account AI configuration with one workspace-wide Omni policy on Channel Setup and one independent workspace-wide Email policy on Email Setup. Each compact control applies to all present and future accounts in its segment, supports exact scheduled active hours, and remains visible before accounts are connected. Human ownership pauses AI until resolution; historical mailbox imports remain protected by account eligibility cutoffs.

- Added workspace-specific teammate availability, availability-aware inbox alerts, atomic Join Chat ownership, takeover/leave controls, clean ownership reset on resolution, realtime web/mobile ownership payloads, and customer-visible widget waiting/joined states. Added mobile APIs for ownership and administrator schedule management.

- Simplified Website Widget AI scheduling to Permanent or exact Scheduled active hours with split/overnight windows. Legacy inside/outside schedules are converted without changing effective answering periods. Removed the redundant global Smart Bot activation switch: explicit widget/channel/workflow selection is now the only activation state, and a missing or deleted selection fails closed.

- Normalized every automation-run dispatch onto the canonical `automation` queue. The job now owns its queue assignment, and the Developer API no longer strands manually triggered runs on the unused plural queue. Deployments should drain the legacy `automations` queue once after rollout.

- Added optional, timezone-aware Smart Bot scheduling to Website Widget Appearance. Clients can run AI inside or outside per-day office hours; enforcement happens server-side for inbound webchat messages and stays synchronized with public handoff availability. Scheduling defaults off, so existing widgets remain continuously available until configured. Deployment requires the new chat-widget schedule migration and matching frontend/backend.

- Made Smart Bots knowledge-only by default whenever a Knowledge Base is assigned, independent of the guarded-publishing rollout flag. Unrelated or unsupported questions now bypass chat generation when retrieval fails, provider output must pass a grounding contract before credits finalize, and clients can explicitly enable safe general answers with a plain-language setting. Short CTA/follow-up replies retain nearby conversational context without letting prior topics legitimize a substantive unrelated request.

- Scoped client notifications end to end by originating workspace. Web and Sanctum APIs now isolate list/count/read/delete operations, realtime ignores inactive-workspace events, push payloads identify their workspace, and background recipients include owners plus pivot members without leaking notifications across workspaces. Deployment requires the matching notification migration and refreshed backend/frontend/queue processes.

- In-progress analysis remediation: recognize modular database schema and model casts, type model relationships, and remove obsolete baseline suppressions. Harden resumed Messenger selection and sitemap fetch state. Repository-wide analysis and the full test suite are not yet passing; no Main merge is authorized by these results.

- Removed stale PHPStan baseline references to deleted Leads/Twitter files. Analysis now executes, but repository-wide findings still block the Main merge gate.

- Require matching quick replies for generated closed-choice questions and recover clear English yes/no and eSIM compatibility choices when the model omits them, without another model request. SDK payload shape remains unchanged.

- Renamed Widget Appearance's AI-answering labels and selection guidance to Smart Bot. Presentation only; no API, SDK or configuration changes.

- Refined chatbot reply buttons with shorter desktop height, softer borders, clearer text and tighter spacing while retaining larger touch targets. Web-only styling; no API changes.

- Removed the duplicate Meta setup overlay from Inbox Channel Setup; authorization progress stays inline in the existing connection side panel.

- Clarified comment availability across all five social providers in connection UI and mobile JSON; added unsupported-platform and independent moderation gates. This does not add YouTube, LinkedIn or TikTok comment adapters.

- Added an optional compact Comments workspace beside Posts, with Facebook/Instagram public comment handling, scoped mobile APIs, manual replies/moderation, explicit AI preview and public-KB consent, per-thread takeover, credit-safe generation, signed ingestion, bounded synchronization, and delivery-unknown protection. Requires migration, matching frontend, and Meta permission/external-client verification before public rollout. Native mobile screens and full ad discovery remain separate.

- Added optional WhatsApp connection-health monitoring and a guided Repair connection action, with separate readiness/delivery evidence, owner/admin authorization, rate-limit backoff, scoped operation history, incident/recovery notifications, and operational diagnostics. Deployment requires a migration and separate `channel-health` worker. WABA subscriptions now use account-authorized credentials; Coexistence repair never re-registers phones or imports history.

- Made the managed AI-credit migration compatible with MySQL installations that use legacy implicit `TIMESTAMP` defaults, and made its schema creation safe to resume after an interrupted deployment.

- Simplified the client header by removing the global Search and language-selection controls while retaining AI credits, notifications, theme, workspace, and account access.

- Made each plan's configured **WisperBot AI Credits / mo** limit the sole credit entitlement, added a global remaining/total header meter with live refresh, and redesigned Subscription usage with distinct entitlement states, processing reservations, human-readable per-action rates, totals, and recent activity. Active renewable subscriptions now retain their allowance when a gateway or legacy assignment leaves a stale prior-cycle end date.

- Consolidated Connected Social Media, Post Composer, Post Automation, and Calendar into **Social Media Automation**, with compact account management, Upcoming-first status tabs, search/filtering, responsive post rows, integrated List/Calendar views, compatibility redirects, and an explicit Schedule versus Publish now composer choice. Empty workspaces now use compact, action-focused rows and hide inapplicable post controls; locale-file changes also invalidate cached dictionaries so translation keys never appear as client-facing copy.

- Added Super Admin configuration and managed-provider selection for Alibaba Qwen 3.7 Flash, including region-bound endpoint validation, live connection testing, encrypted credentials, and OpenAI/Gemini-only Knowledge Base embedding fallback.

- Added guarded, revisioned Knowledge Base publishing with a guided five-step client workflow, deterministic source safety/quality review, extraction previews, regression testing, atomic publish/rollback, and last-healthy-revision continuity behind `KB_GUARDED_PUBLISHING`.
- Reduced Knowledge Base token usage through exact approved FAQ responses, revision-keyed safe answer caching, seven-day query-embedding caching, changed-chunk-only embedding, three-passage/1,200-token retrieval budgets, and low-confidence clarify/handoff behavior.
- Added Knowledge Base health/readiness, source review controls, retrieval testing with citations/confidence/token estimates, knowledge-gap records, score-only retrieval diagnostics, and additive guarded-workflow API fields/actions.

- Added organization-pooled monthly WisperBot AI credits with weighted actions, atomic reservations, immutable/idempotent usage ledger, failure/stale-reservation refunds, anniversary resets, upgrade/downgrade handling, encrypted result replay, provider cost telemetry, and shadow-to-hard enforcement rollout controls.
- Added a permission-protected Super Admin AI-credit operations report with date filtering, usage/cost/margin summaries, suspicious hashed-device signals, and audited account adjustments.
- Added workspace AI modes for managed credits, customer-owned providers, and successfully-tested automatic BYOK fallback. DeepSeek is now restricted to the Super Admin AI / LLM integration and is unavailable through client provider screens, endpoints, BYOK, and automatic fallback.
- Made **Credits, then my provider** the default AI usage mode for new and unset workspaces while preserving every explicitly saved workspace mode.
- Added client subscription/API credit summaries, once-per-period email/in-app 80% and exhaustion warnings, required finite admin plan allowances, reporting by workspace/feature/model/plan with estimated provider cost and gross margin, and audited grant/revoke endpoints.
- AI-dependent automation runs now pause at the affected node when credits are unavailable and can be retried safely without double charging.
- Simplified Knowledge Base authoring to URL, File, and Sitemap sources. Supported YouTube, Vimeo, and HTTPS MP4 links found inside extracted content now become threshold-matched, click-to-play widget/inbox answers automatically; legacy Text, FAQ, and Video records remain compatible.
- Replaced the technical Sitemap authoring choice with Website discovery: clients can paste a homepage, and WisperBot safely resolves canonical redirects, declared/standard sitemaps, or a capped same-site page crawl automatically.
- Reworked Knowledge Base setup into one distraction-free, gated step at a time. Published Knowledge Bases open in a compact Monitor view and retain source add, review, reindex, enable/disable, delete, test, republish, revision, and rollback controls without repeating onboarding.
- Simplified the empty Knowledge Base Sources step into three clear ingestion choices and defer search, filters, management actions, and review navigation until sources exist.
- Focused new Knowledge Base uploads on PDF, DOCX, TXT, and Markdown, with prominent guidance that supported video URLs can become playable customer-chat answers; existing spreadsheet/CSV/JSON sources remain compatible.
- Made WhatsApp, Instagram, and Messenger connection drawers onboarding-only; successful connections now close the panel instead of replacing it with a sticky duplicate account-management view. Channel Setup now hides empty account-management cards and removes the redundant Getting Started/resources footer so each setup action appears only once.
- Added comprehensive multi-format attachment support across Web Inbox, Live Chat Widget, and Mobile API (PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, TXT, CSV, ZIP archives, Images, and Audio/Video) with a strict 10 MB upload limit.
- Added automatic Apple HEIC/HEIF photo conversion to high-compatibility JPEG upon upload (with graceful document fallback if server ImageMagick HEIC delegate is absent).
- Enhanced Live Chat Widget UI with unified paperclip attachment button, pre-send document/thumbnail preview card, and rich `.wb-media-doc-card` in chat transcripts.
- Added Instagram Graph API guard preventing document dispatch on Instagram conversations across Web Inbox and Mobile API.
- Added separate WhatsApp Business app Coexistence and Cloud API onboarding choices, including the documented Meta signup mode, no re-registration of existing app numbers, contact/history sync, and live phone-app message echoes.
- Improved Meta onboarding UX with an explicit compact-popup request and an in-page progress dialog that guides customers back to WisperBot when browser policy presents Meta as a tab.
- Made Coexistence history import automation-safe: historical messages do not trigger AI replies, notifications, or unread-count inflation.
- Added the maintained project documentation system and repository-wide documentation impact policy.
- Fixed Meta social OAuth so only the Facebook Page or Instagram account explicitly selected by the client is connected; other Pages discovered through the same Business Portfolio are ignored.
- Prioritized Meta publishing-scope `target_ids` over broader retained discovery/read selections so reconnecting one Page cannot implicitly add another Page.
- Added a safe “Remove from WisperBot” action for orphaned published-post records when their connected social account is no longer available, with an explicit warning that the remote post may remain.

## Current baseline — 2026-08-21

The documented baseline includes:

- Multi-workspace Omni Channel Inbox and private website chat widgets.
- WhatsApp, Messenger, Instagram, Telegram, email, eBay, and Amazon connection foundations with provider-specific limitations.
- Facebook/Instagram social composer, scheduling, publishing, and capability-driven published-post controls.
- AI providers, knowledge bases, MySQL/Qdrant retrieval, smart bots, and human handoff.
- SMS campaigns, supported gateway configuration, automations, ecommerce context, reports, billing/add-ons, blog/CMS, and native mobile APIs.
- Pusher/Reverb realtime channels, OneSignal/web push notification foundations, scheduler/queue diagnostics, and deploy-time patch versioning.

Earlier feature history is available in Git. Do not reconstruct historical dates from memory; add future entries as changes are completed.

# Client WhatsApp setup simplification — 2026-09-05

- Hide webhook configuration, raw WhatsApp account/phone IDs, and infrastructure diagnostic disclosures from client Channel Setup. Keep status, delivery timestamps, and contextual Check/Repair/Reconnect actions with plain-language guidance; preserve Super Admin diagnostics.

# 2026-09-05 — Knowledge Base deletion hotfix

- Fixed Qdrant retry handling preventing old document deletion when the vector collection is absent; the same fix restores first-write collection creation. Genuine vector cleanup failures remain blocking. Backend-only deployment; no database migration.

# 2026-09-05 production v1.3.42

- Fixed sitemap-first website discovery, canonical www aliases, bounded extraction timeouts, and readable indexing-state labels.
- Aligned OpenAI admin validation with managed runtime models and rejected/refunded empty AI outputs.
- Fixed missing Qdrant payload indexes for strict-mode document cleanup. Verified a real website chatbot reply and its one-credit charge.

# Unreleased — 2026-09-06

- Added AI-generated suggested customer reply buttons to the website widget and Smart Bot Playground, with read-only labels in the agent inbox and additive mobile payloads.
- Added matching Flutter customer SDK decoding, prebuilt buttons and headless selection methods. Existing clients retain text fallback. No SQL migration; SDK and host apps require separate releases.
- Playground now forwards bounded history for context-aware follow-up choices.

# 2026-09-16 — Experimental Knowledge Base website normalisation

- Accepted bare domains, `www` addresses, and HTTP-form website inputs through a shared HTTPS-only Knowledge Base resolver used by web, developer API, page extraction, and sitemap discovery.
- Preserved the customer's submitted address, stored the verified canonical URL, safely selected working apex/`www` variants, detected redirect loops, and upgraded same-site HTTP redirect targets without making insecure requests.
- Added compact **Connected securely as…** feedback, distinct safe crawler errors, migration-backed URL provenance, and regression coverage including the redirect pattern used by `telzen.net`.
