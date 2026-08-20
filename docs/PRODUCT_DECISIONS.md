# Product decisions

Last reviewed: 2026-08-21. This file records intentional behavior so future work does not accidentally reverse product choices.

## Positioning

WisperBot is a white-label-friendly, multi-workspace customer communication platform. Its main promise is to centralize conversations and operational context, with AI assistance and human handoff rather than forcing customers into AI-only support.

## Client navigation and terminology

- The primary agent inbox is **Omni Channel Inbox**.
- Email is separate as **Email MasterBox**; email and SMS must not appear as channels inside the Omni Channel Inbox.
- Channel connection is **Inbox Channel Setup**.
- Website chat management is grouped as **Chatbot Widget**, with Widgets, Appearance, and Integrations.
- Social items use Post Composer, Post Automation, Calendar View, and Connected Social Media.
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
- Customer media includes images and recorded audio, with browser permission requested before microphone use.
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

## Social publishing capabilities

- Facebook and Instagram must not share assumed remote-edit behavior.
- Facebook Page posts may expose remote edit/delete only when the stored provider result confirms support and a usable remote ID/token exists.
- Instagram published content is not editable through the integrated API flow. Delete availability is capability/provider-result driven.
- Drafts and scheduled-but-unpublished records remain editable/cancellable locally.

## Seller messaging

- eBay supports seller OAuth and message synchronization, subject to production API approval.
- Amazon SP-API does not expose a general inbound Buyer–Seller inbox. WisperBot supports approved order-specific message actions and must not market it as a mirrored inbox.

## Licensing and versions

- Production license enforcement remains enabled.
- Local testing may bypass license validation only through an explicit local-environment guard; it must never weaken production.
- Production deployment finalization increments the patch version once per Git revision. Frontend UI changes still require a current Vite build.
