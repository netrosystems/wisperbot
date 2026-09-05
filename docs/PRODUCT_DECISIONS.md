# Product decisions

Last reviewed: 2026-09-03. This file records intentional behavior so future work does not accidentally reverse product choices.

## Positioning

WisperBot is a white-label-friendly, multi-workspace customer communication platform. Its main promise is to centralize conversations and operational context, with AI assistance and human handoff rather than forcing customers into AI-only support.

## Client navigation and terminology

- The primary agent inbox is **Omni Channel Inbox**.
- Email is separate as **Email MasterBox**; email and SMS must not appear as channels inside the Omni Channel Inbox.
- Channel connection is **Inbox Channel Setup**.
- Website chat management is grouped as **Chatbot Widget**, with Widgets, Appearance, and Integrations.
- Social publishing uses one client destination named **Social Media Automation**. Account connection is the compact first section, post management defaults to Upcoming, and List/Calendar are views of the same workspace. The focused composer opens from **Schedule Post** and requires an explicit Schedule for later or Publish now choice.
- AI is presented as **AI Automations** and chatbots as **Smart Bots**.
- Media Library remains a normal content/group asset because campaigns, posts, automation, email, and chat reuse uploads.

## Website widget

- Free users may set their footer company name; the widget displays “Powered by {Company}”.
- The default launcher is the WisperBot icon. Custom launcher images are a paid-plan feature, not a separate “white-label branding” add-on.
- The widget must isolate every visitor conversation. Anonymous visitors receive stable generated labels such as Customer 1, Customer 2; they never share a public transcript.
- Logged-in identity is supplied by the customer's own server/application through the embed settings. WisperBot cannot infer a host site's login state by itself.
- Identity verification uses an HMAC generated server-side from the widget secret. Unsigned/invalid identity falls back to anonymous instead of trusting browser-supplied personal data.
- Visitor IP may be captured for operational context subject to privacy/legal disclosures.
- Agent/customer typing indicators and realtime messages should be lightweight and ephemeral.
- Customer and agent media attachments support images (with automatic HEIC/HEIF to JPEG conversion), recorded audio/voice messages, and business documents (PDF, Word .doc/.docx, Excel .xls/.xlsx, PowerPoint .ppt/.pptx, Text .txt/.csv, and ZIP archives) up to a 10 MB upload limit.
- Instagram Direct Messaging (DM) Graph API only supports images, video, and audio; document attachments are explicitly guarded and disabled in the UI and backend validation for Instagram conversations.
- New agent messages should create sound/unread launcher feedback when the visitor is not actively engaged.
- When AI is enabled, offer human handoff after two customer turns. Once connected to a human, subsequent messages stop going to AI until handed back.
- The launcher carries an online indicator. Compact team availability may appear in the widget header.
- Live Users means recent, expiring presence based on heartbeat/last-seen—not accumulated conversations—and must not claim analytics-level certainty.

## AI behavior

- Answers should be concise, natural, and personalized.
- Retrieval context is authoritative for company-specific facts. For harmless general requests (for example, translating an answer), the model may respond without a matching knowledge-base sentence.
- Do not invent business-specific facts. When confidence is insufficient, give a short honest response and offer human help.
- Render suggested URLs as hyperlinks.
- MySQL is always-available vector storage; Qdrant is optional for larger installations.
- Subscription AI usage is sold as completed-action credits, not raw tokens. Credits pool at the Client organization (or standalone billing owner), reset monthly without rollover, and have no automatic overage billing.
- The Super Admin plan field **WisperBot AI Credits / mo** (`ai_credits_per_month`) is the only allowance source; plan price does not imply an allowance. Fixed action costs come from one versioned catalog. Only successful WisperBot-managed actions consume credits; BYOK, provider tests, embeddings, rejected responses, and provider failures do not.
- The client header displays AI credits as remaining/total. Subscription provides the complete used, processing, remaining, per-action, and recent-activity explanation without exposing internal feature keys.
- Workspaces choose WisperBot-managed AI, their own provider, or automatic fallback. New and unset workspaces default to **Credits, then my provider** (`auto_fallback`): managed credits are used first, and customer-provider fallback is attempted only with an enabled provider that passed connection testing. Explicitly saved modes are preserved. BYOK calls consume no managed credits.
- Managed routing is internal: routine/RAG work uses the configured nano model and complex email/social/workflow generation uses the configured mini model. DeepSeek is available only to Super Admins under Integrations → AI / LLM; clients cannot view, save, test, or use it as BYOK or automatic fallback.
- Knowledge Base changes are drafts until quality and regression gates pass. Warnings require client review, blockers cannot publish, factual suggestions are never silently accepted, and the last healthy revision remains live.
- One focused Knowledge Base is assigned to a chatbot. Exact approved FAQs may bypass generation; safe non-personalized answers may cache per published revision. Company-specific facts require published evidence above the retrieval threshold, otherwise the bot clarifies once or offers human handoff.
- New Knowledge Base authoring exposes only URL, File, and Sitemap sources. Supported video links discovered in their extracted content are normalized server-side and can render as click-to-play resources; legacy Text, FAQ, and Video records remain readable for backward compatibility.
- New Knowledge Base file uploads accept PDF, DOCX, TXT, and Markdown—the formats with the most reliable customer-facing extraction. Existing CSV, XLSX, and JSON sources remain readable for backward compatibility but are no longer offered for new client uploads. The upload UI explicitly recommends adding supported video URLs when visual guidance can improve the answer.
- Knowledge Base onboarding is a gated, single-pane flow: Define, Sources, Review, Test, and Publish. Monitoring becomes available after the first publication. Clean indexing never auto-publishes unless the client has saved regression tests; otherwise explicit testing and publication are required. Published Knowledge Bases reopen in management mode, and source changes create a safe draft without disturbing the live revision.
- Channel connection drawers are onboarding-only. They close after a successful connection and never duplicate webhook, sync, template, phone-number, chatbot-assignment, rename, or disconnect controls; connected-channel management belongs exclusively to Channel Setup cards. This rule applies consistently to WhatsApp, Instagram, and Messenger.
- Channel Setup presents every connection action once in its primary setup area. The account grid renders connected channels only; empty WhatsApp, Instagram, Messenger, Telegram, eBay, and Amazon cards must not repeat connection calls to action farther down the page. The former Getting Started, webhook, and external-resource footer is intentionally omitted because it duplicates the guided setup surface.
- Client UI says sources, passages, readiness, and review—not vectors or embeddings—unless the user opens advanced diagnostics.

## Developer features

- API tokens, external webhooks, and API documentation are hidden by default and sold through the Developer Tools add-on.
- The native mobile app does not require this add-on; it uses dedicated Sanctum mobile endpoints.
- Media Library is not a developer-only feature.

## Payments and billing

- Supported customer payment gateways are Stripe, PayPal, and Paddle.
- Other legacy payment gateways should not be exposed in Super Admin or client checkout.
- Plans control usage and entitlements, including contacts/customers, messages, storage, AI usage, team capacity, and paid launcher customization.

## Broadcasting

- Campaign broadcasting is SMS-focused; email campaigns/email-server campaign setup are not product features.
- Visible SMS providers are SMSBD, REVE SMS, BulkSMS BD, MessageBird, Twilio, ProSMS (Alaris), and Amazon SNS.

## WhatsApp onboarding

- Channel Setup must clearly separate **Connect existing WhatsApp Business app** (Coexistence) from **Set up a Cloud API number**.
- Meta onboarding requests a compact provider popup and is accompanied by an in-page WisperBot progress dialog. Do not iframe Meta login; if a browser converts the popup to a tab, clearly tell the customer to keep WisperBot open and return after completing Meta.
- Coexistence preserves use of the phone app. Synced history is a silent backfill and must not trigger AI, automations, notifications, or unread inflation.
- Messages sent from the linked phone app appear in WisperBot as outbound human messages.
- The Meta-app owner's own WABA is an operator-managed first-party exception; the platform system-user token must never be offered to client workspaces.

## Social publishing capabilities

- Facebook and Instagram must not share assumed remote-edit behavior.
- Facebook Page posts may expose remote edit/delete only when the stored provider result confirms support and a usable remote ID/token exists.
- Instagram published content is not editable through the integrated API flow. Delete availability is capability/provider-result driven.
- Drafts and scheduled-but-unpublished records remain editable/cancellable locally.
- If a published post's connected social account is no longer available, workspace admins may remove the stale record from WisperBot. The action must be labelled as local cleanup and must explicitly warn that it does not delete the remote Facebook/Instagram post.

## Seller messaging

- eBay supports seller OAuth and message synchronization, subject to production API approval.
- Amazon SP-API does not expose a general inbound Buyer–Seller inbox. WisperBot supports approved order-specific message actions and must not market it as a mirrored inbox.

## Licensing and versions

- Production license enforcement remains enabled.
- Local testing may bypass license validation only through an explicit local-environment guard; it must never weaken production.
- Production deployment finalization increments the patch version once per Git revision. Frontend UI changes still require a current Vite build.
