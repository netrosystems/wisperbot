# Project changelog

This is a documentation-level changelog for user-visible and operationally significant changes. Git history remains the detailed source.

## Unreleased

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
