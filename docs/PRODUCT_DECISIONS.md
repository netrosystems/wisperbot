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
- Widget Appearance uses **Smart Bot** consistently in its AI-answering toggle, selector and empty-state guidance; internal chatbot identifiers and API fields remain unchanged.
- Media Library remains a normal content/group asset because campaigns, posts, automation, email, and chat reuse uploads.

## Website widget

- Website widget and customer SDK availability are intentionally separate controls on the same workspace widget. `Widget enabled` controls only the website embed/launcher; `SDK enabled` controls only customer apps using the SDK key. The staff/agent mobile app is unaffected because it uses authenticated mobile APIs, not the public widget surface.
- Website-widget and customer-SDK conversations both remain `webchat` channel conversations. New conversations record `conversations.started_from` as `web_widget` or `customer_sdk` when the public session creates the thread; old conversations remain unset rather than being guessed. Agent UIs may use a different icon or detail label for SDK-origin chats without changing channel filtering or routing.
- Free users may set their footer company name; the widget displays “Powered by {Company}”.
- The default launcher is the WisperBot icon. Custom launcher images are a paid-plan feature, not a separate “white-label branding” add-on.
- The widget must isolate every visitor conversation. Anonymous visitors receive stable generated labels such as Customer 1, Customer 2; they never share a public transcript.
- Device-cached widget history is usable only after it has been bound to a server-confirmed conversation. Every session response is authoritative for that conversation and replaces the cached history window, so renewed identities or conversations cannot inherit stale local messages.
- Logged-in identity is supplied by the customer's own server/application through the embed settings. WisperBot cannot infer a host site's login state by itself.
- Identity verification uses an HMAC generated server-side from the widget secret. Unsigned/invalid identity falls back to anonymous instead of trusting browser-supplied personal data.
- Visitor IP may be captured for operational context subject to privacy/legal disclosures.
- Agent/customer typing indicators and realtime messages should be lightweight and ephemeral.
- Customer and agent media attachments support images (with automatic HEIC/HEIF to JPEG conversion), recorded audio/voice messages, and business documents (PDF, Word .doc/.docx, Excel .xls/.xlsx, PowerPoint .ppt/.pptx, Text .txt/.csv, and ZIP archives) up to a 10 MB upload limit. Widget uploads persist their storage path and the team inbox renders them through the authenticated inbox media proxy; direct public storage URLs are compatibility data, not the only render path.
- Instagram Direct Messaging (DM) Graph API only supports images, video, and audio; document attachments are explicitly guarded and disabled in the UI and backend validation for Instagram conversations.
- New agent messages should create sound/unread launcher feedback when the visitor is not actively engaged. Visitors can mute the sound from the header “•••” menu (remembered per widget in the visitor's browser); the same menu offers “Talk to an agent” while a handoff can be requested (2026-09-19).
- When AI is enabled, offer human handoff after two customer turns. Once connected to a human, subsequent messages stop going to AI until handed back.
- The launcher's “Live Chat!” preview bubble first appears after five minutes on the page, stays eight seconds, and returns at most every five minutes while the chat is closed. It previously appeared after 20 seconds and repeated every 20 seconds (2026-09-19).
- The launcher carries an online indicator. Compact team availability may appear in the widget header. While the chat is open the launcher is hidden, because the header already has a close (X), and the panel extends into that space; closing returns focus to the launcher (2026-09-19).
- Live Users means recent, expiring presence based on heartbeat/last-seen—not accumulated conversations—and must not claim analytics-level certainty.

## AI behavior

The durable implementation and rollout contract for the decisions in this section is [Knowledge Base and Smart Bot Answering](KNOWLEDGE_BASE_SMART_BOT.md). Future changes to routing, retrieval, approved research, grounding, credits, or customer-facing metadata must update that guide in the same change.

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
- Smart Bot replies keep facts exact but use the bot's own natural wording (2026-09-19). Scripted Knowledge Base conversations guide the flow rather than being copied. Clients who need approved wording enable **Use Knowledge Base wording exactly** per bot. Follow-up questions, next-step offers, and goodbyes are conversation, not facts, and never trigger a handoff by themselves. Any figure the bot states must exist in the evidence.
- One focused Knowledge Base is assigned to a Smart Bot. Exact approved FAQs may bypass generation; safe non-personalized answers may cache per published revision. Answer scope is independent from unsupported-answer behavior: **Business only** (recommended), **Verified sources only**, or **General assistant**. Business only handles deterministic social turns, grounded business facts, and stable domain guidance while rejecting unrelated topics. Verified sources only requires evidence for substantive facts. General assistant preserves broad help. Company-specific prices, policies, availability, specifications, and promises always require evidence.
- Standalone greetings, thanks, acknowledgements, and goodbyes are localized deterministic replies and consume zero AI credits. A greeting combined with a substantive question follows normal retrieval. Business-aware routing derives relevance only from that workspace's Knowledge Base purpose, brand, audience, sources, and private conversation. An incomplete business profile fails closed to Verified sources only.
- Optional live research is limited to URL and sitemap domains already configured in the selected Knowledge Base. It performs a bounded ephemeral fetch with HTTPS, redirect, DNS, SSRF, size, robots, and timeout protections, requires supporting text, and emits safe citations. It never performs open-web search, trusts a visitor-supplied URL, or silently publishes fetched text into the Knowledge Base.
- Private Smart Bot retrieval searches each current customer message on its own. Previous dialogue is added only for a genuine continuation such as a CTA choice, yes/no, pronoun, or incomplete follow-up; greetings, acknowledgements, handoff text, and fallback replies are excluded. Semantic similarity is never reduced because exact words differ. Normalized wording and generic typo/character matching are ranking boosts derived from tenant content, not an industry synonym list.
- Retrieval has three client-safe outcomes: strong verified evidence answers; a supported but incomplete business intention asks one grounded clarification; weak, unrelated, unsafe, or repeatedly ambiguous input falls back. Generated answers and successful generated clarifications each cost one chatbot credit. Retrieval, deterministic greetings, fallbacks, and failed/rejected generations cost zero.
- Retrieval-engine tuning is platform-managed. Clients do not edit raw context-passage counts, similarity thresholds, token budgets, or video thresholds; they choose business-facing answer scope, fallback, approved-source research, tone, and instructions. Legacy per-bot numeric fields remain compatibility data but cannot weaken runtime safeguards through the client update path.
- A headed multi-turn example is one semantic unit until normal size chunking requires overlap; later selections must not be detached from the question that gives them meaning. When a Smart Bot owner enables approved-source research, a purchase or fresh-information request may combine relevant indexed passages with a bounded live read from that KB's configured website sources. Research failure never suppresses an otherwise supported indexed answer.
- Website-widget AI availability is configured in Appearance as Permanent or Scheduled active hours. Scheduled mode accepts split/all-day/overnight windows in an IANA timezone and is evaluated for every inbound website message. Legacy inside/outside schedules retain their effective periods when converted. Keyword automations and non-widget channel settings are unchanged.
- Smart Bots have no separate global active/inactive state. A bot becomes operational only when explicitly selected for a widget, channel, automation, or social account; each website widget selects exactly one bot in Appearance. The legacy `enabled` database/API field remains as an always-true compatibility field and must not gate selection or execution. A missing or deleted selected bot fails closed until another bot is chosen.
- Private-message AI activation is workspace-segment based. Channel Setup owns one Omni policy shared by all supported WhatsApp, Messenger, Instagram DM, Telegram Business, and eBay accounts; Email Setup owns one separate policy shared by every mailbox. Each segment selects Off, Always on, or exact Scheduled active hours plus one Smart Bot. Email sends automatically when eligible. Website widgets remain in Appearance, public comments retain their separate guarded modes, and unsupported Amazon/SMS inboxes expose no misleading AI control.
- Team availability is workspace-specific and limits only new-message/handoff alerts. It never prevents access or manual work. A conversation has one explicit joined owner separate from pre-assignment; only that owner sends human replies. Resolution retains history but clears ownership and handoff state. Staff timelines retain durable join, transfer, leave, assignment, unassignment, reopen, pending, snoozed, and resolution activity with actor/subject snapshots where applicable. Website widget/SDK customers see only joined and resolved activity, with the joined display name/avatar; takeover appears as the new agent joining, while transfer details and all other workflow activity remain private to staff. External providers do not receive synthetic activity messages.
- New Knowledge Base authoring exposes only URL, File, and Sitemap sources. Supported video links discovered in their extracted content are normalized server-side and appear in customer chat as a “See Tutorial →” link below the reply text that opens the video on its provider's page in a new tab (2026-09-19); legacy Text, FAQ, and Video records remain readable for backward compatibility.
- New Knowledge Base file uploads accept PDF, DOCX, TXT, and Markdown—the formats with the most reliable customer-facing extraction. Existing CSV, XLSX, and JSON sources remain readable for backward compatibility but are no longer offered for new client uploads. The upload UI explicitly recommends adding supported video URLs when visual guidance can improve the answer.
- Knowledge Base onboarding is a gated, single-pane flow: Define, Sources, Review, Test, and Publish. Monitoring becomes available after the first publication. Clean indexing never auto-publishes unless the client has saved regression tests; otherwise explicit testing and publication are required. Published Knowledge Bases reopen in management mode, and source changes create a safe draft without disturbing the live revision.
- Channel connection drawers are onboarding-only. They close after a successful connection and never duplicate webhook, sync, template, phone-number, chatbot-assignment, rename, or disconnect controls; connected-channel management belongs exclusively to Channel Setup cards. This rule applies consistently to WhatsApp, Instagram, and Messenger.
- Channel Setup presents every connection action once in its primary setup area. The account grid renders connected channels only; empty WhatsApp, Instagram, Messenger, Telegram, eBay, and Amazon cards must not repeat connection calls to action farther down the page. The former Getting Started, webhook, and external-resource footer is intentionally omitted because it duplicates the guided setup surface.
- Client UI says sources, passages, readiness, and review—not vectors or embeddings—unless the user opens advanced diagnostics.

## Dynamic Smart Bot questions and CTA replies

Product requirement clarified: 2026-09-08. This is an existing horizontal SaaS capability, not an industry-specific assistant or fixed decision tree. Its implementation contract and verification limits are in [Suggested customer replies](CHAT_REPLY_OPTIONS.md).

- Each client's selected Smart Bot uses that client's assigned Knowledge Base and the current private conversation. One customer's products, terminology, troubleshooting steps or policies must not become defaults for other workspaces.
- When additional information is needed, ask one useful contextual question. For a question with a small set of meaningful answers, generate two or three corresponding `quick_replies` dynamically in the customer's language. Questions, labels and subsequent branches are not limited to Yes/No, devices or any particular industry.
- Use free text for open questions, large catalogs or information that cannot safely be reduced to a few choices. Keep the composer available even when choices exist. Do not force unnecessary questions after a complete answer or invent business options absent from verified sources.
- The website widget and customer SDK render the same server-provided labels generically. They must not decide behavior by matching industry names or hardcoded labels. A selected label is sent as a normal customer message; the next answer uses its conversational context and verified business knowledge, then answers, asks the next useful question, or follows the configured clarification/handoff fallback.
- Reply buttons do not execute purchases, cancellations, bookings or permission changes. Those require separate authorized action workflows. Human handoff, stale-choice protection, privacy, tenancy and fixed-action credit rules still apply.
- Implementation presence, successful automated tests, production deployment and native SDK release are different states. Verify all relevant stages before claiming the complete customer journey works. A language-specific recovery heuristic is not a universal implementation of this requirement.

## Smart Bot starter questions

Product decision: 2026-09-19. Clients can pre-answer their most common questions.

- Each Smart Bot has an on/off **Starter questions** switch and up to **five** questions with fixed answers, written by the client. The feature is generic: any business writes its own questions; nothing is industry-specific.
- When on, website-chat and customer-SDK customers see the questions on a new chat and permanently at the top of the chat, under the welcome message.
- Tapping a question sends it as the customer's message, and the bot replies instantly with the exact saved answer. There is no AI rewording and it costs no credits. Typing the same question, ignoring case, punctuation and spacing, gets the same answer.
- The questions are shown only while the Smart Bot is answering (AI on and within its schedule). Once a person handles the chat, the questions are disabled and do not trigger the bot.
- Other channels do not show the list, but an exact typed match there also receives the saved answer.

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

- The legacy Envato/Botble purchase-code licensing and license-coupled updater were retired on 2026-09-21. Installation and Super Admin access do not require distribution-license activation.
- A future licensing model is intentionally undecided and must be designed as a new feature rather than restoring assumptions from the retired implementation.
- Production deployment finalization increments the patch version once per Git revision. Frontend UI changes still require a current Vite build.
