# Project changelog

This is a documentation-level changelog for user-visible and operationally significant changes. Git history remains the detailed source.

## Unreleased

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
