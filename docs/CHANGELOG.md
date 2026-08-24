# Project changelog

This is a documentation-level changelog for user-visible and operationally significant changes. Git history remains the detailed source.

## Unreleased

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
