# Integrations

Last verified against code: 2026-09-03.

All application secrets belong in encrypted database configuration or environment variables. Never place credentials in this document.

## Integration ownership model

Super Admin configures platform-level applications/gateways. A client then authorizes or configures workspace-specific accounts. Tokens and credentials are encrypted and never returned in full to the browser. Provider asset IDs are bound to a workspace to avoid duplicate routing.

| Platform | Super Admin responsibility | Client responsibility | Inbound/outbound behavior |
| --- | --- | --- | --- |
| Meta Messenger | Meta app/configuration, OAuth redirects, permissions, webhook | Select/authorize Page | Page messages into inbox; agent replies; Page post publishing via Social module. |
| Instagram | Same Meta app plus Instagram permissions | Select linked professional account/Page | DMs into inbox; content publishing; provider capability limitations apply. |
| WhatsApp Cloud API / Coexistence | Meta app, Embedded Signup configuration, webhook fields | Choose a Cloud API-only number or connect an existing WhatsApp Business app number | Inbound/outbound messaging, templates, auto replies; Coexistence additionally syncs app contacts/history and phone-app message echoes. |
| Telegram Business | Integration configuration/webhook | Connect supported Telegram business/bot authorization | Queued inbound updates and agent replies. |
| Email | Google/Microsoft OAuth apps; server PHP IMAP for generic accounts | Connect multiple Gmail/Microsoft/IMAP-SMTP mailboxes | Email MasterBox sync/compose, separate from Omni Channel Inbox. |
| eBay | Developer keyset, RuName, scopes/environment | Seller OAuth | Poll/sync seller messages and reply; see provider guide. |
| Amazon SP-API | LWA/SP-API public app and messaging role | Seller OAuth | Order-specific allowed message actions; no mirrored inbound inbox. |
| Ecommerce | Platform OAuth/app settings as required | Connect Shopify, WooCommerce, BigCommerce stores | Products/orders/customers, webhooks, automation context. |
| AI | Managed OpenAI availability/defaults; optional Qdrant | Choose managed/BYOK/automatic mode; optionally add workspace keys/models and knowledge bases | Credit-metered managed completion, zero-credit BYOK/embeddings, RAG and smart bots. |
| SMS | Enable supported gateway definitions | Configure workspace sender credentials | SMS campaigns and delivery callbacks. |
| Billing | Stripe, PayPal, Paddle credentials/webhooks | Select plan/add-on and checkout | Subscription and payment lifecycle. |
| Realtime | Pusher/Reverb server configuration | None beyond authenticated session/mobile token | Workspace/conversation broadcasts and presence. |
| Push | OneSignal and/or web-push configuration | User/device registration and permission | Agent mobile/browser notifications. |

## Meta

Meta uses two functional areas:

1. Inbox channel setup for Messenger/Instagram/WhatsApp conversations.
2. Connected Social Media for Facebook/Instagram publishing.

Do not assume authorization in one area automatically creates the correct record/token for the other. OAuth redirect URIs must exactly match the production URL, without an accidental `www` or trailing slash.

Connected Social Media treats Meta's granular OAuth `target_ids` as the source of truth for asset selection. Business Portfolio discovery may enumerate additional owned/client Pages, but the callback must filter those results before persistence so selecting one Page never connects another Page implicitly. Capability-specific write scopes (`pages_manage_posts` for Facebook and `instagram_content_publish` for Instagram) take precedence over broader discovery/read scopes because Meta can retain more previously authorized assets on the latter. If the selected targets cannot be verified, the connection fails without saving accounts.

Remote post edit/delete requires the original connected account, usable token, and stored provider post ID. If that account has been removed, WisperBot may offer a clearly labelled local cleanup action; it removes only the stale WisperBot record and does not claim or attempt deletion on Facebook or Instagram.

Current relevant Meta permissions include (depending on use case):

- `pages_show_list`
- `pages_manage_metadata`
- `pages_read_engagement`
- `pages_messaging`
- `pages_manage_posts`
- `instagram_basic` or Meta's current replacement for the selected API product
- `instagram_manage_messages` / `instagram_business_manage_messages` as applicable to the chosen Instagram API
- `instagram_content_publish`
- `whatsapp_business_management`
- `whatsapp_business_messaging`
- `business_management` only for flows that genuinely require Business Portfolio asset discovery

Meta renames/deprecates products and permissions. Confirm the current names in Meta's official documentation and app dashboard before changing requested scopes. Request only scopes used by an end-to-end working flow.

### Webhooks

- WhatsApp: `/webhooks/whatsapp/global` or token-specific routes.
- Messenger/Instagram: `/webhooks/meta/{token}`.
- Controller verification and inbound idempotency are mandatory.
- Messenger/Instagram processing is dispatched on `whatsapp`; keep that worker active.

### WhatsApp Embedded Signup modes

WisperBot intentionally exposes two distinct WhatsApp setup choices:

- **Existing WhatsApp Business app (Coexistence):** the Facebook SDK login payload sets `extras.featureType` to `whatsapp_business_app_onboarding` and `sessionInfoVersion` to `3`. Meta may finish with `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING`. WisperBot must not call `/{PHONE_NUMBER_ID}/register`, because the number is already registered to the Business app.
- **Cloud API-only:** uses the normal Embedded Signup flow and registers the selected phone number when required.

Embedded Signup requests Meta's `popup` presentation and WisperBot displays an in-page progress dialog until Meta returns control. Meta authentication cannot be embedded in an iframe inside WisperBot; browser popup policy may still present the provider-controlled window as a tab, especially on mobile or when the user's popup preference requires it. The originating WisperBot page must remain open so the Facebook SDK callback can complete.

For Coexistence, WisperBot subscribes the WABA to `messages`, template/account/phone updates, `history`, `smb_app_state_sync`, and `smb_message_echoes`. After connection it requests `smb_app_state_sync` followed by `history` through `/{PHONE_NUMBER_ID}/smb_app_data`. History is imported silently, without firing inbound AI/automation events or creating unread counts. Live phone-app echoes are stored as outbound human messages and broadcast to open agent dashboards.

Meta currently prevents a Business Portfolio that owns the Meta app from selecting its own WABA inside that app's customer Embedded Signup flow. This is a provider ownership rule, not a missing WisperBot selection. The platform owner's WABA must be connected operationally with an approved system-user token and explicit asset assignment; customer WABAs continue through Embedded Signup. Never expose that platform token in the client UI.

### Review evidence

For every requested permission, record the complete flow: login/authorization, exact user action in WisperBot, corresponding provider result, and the result back in WisperBot. Use a real app-role/admin Page/account while the app is unpublished. API test calls can take time to register in App Review.

## Email

- Google Workspace/Gmail and Microsoft should use OAuth configured by Super Admin.
- Generic cPanel/Zoho/Fastmail/custom accounts use IMAP for sync and SMTP for sending.
- PHP CLI and web SAPIs can load different `php.ini` files; verify `extension_loaded('imap')` in the web/runtime context as well as CLI.
- Multiple mailboxes per workspace are intentional.
- Scheduled sync dispatches active email accounts every minute on `default`.

## AI and vector storage

- Workspace AI credentials are encrypted in the database; UI placeholders mean “keep current key.”
- Knowledge ingestion discovers YouTube, Vimeo (including retained unlisted `h` hashes), and direct public HTTPS MP4 links in extracted websites and files. WisperBot derives player URLs and never stores arbitrary embed markup; clients do not create a separate Video source.
- YouTube/Vimeo are rendered only after Play is selected. Customer-site CSP may need `https://www.youtube.com`, `https://www.youtube-nocookie.com`, or `https://player.vimeo.com` in `frame-src`; Vimeo domain-level privacy must also permit the embedding customer domain.
- External messaging channels cannot render web players, so AI answers append `Watch video: CANONICAL_URL` while WisperBot clients use the structured resource card.
- Provider tests must surface the actual category (invalid key, model unavailable, quota, network), not collapse everything into “bad credentials.”
- Only select chat/embedding models that the provider project can list/access.
- Qdrant uses `QDRANT_URL` and `QDRANT_API_KEY`; MySQL fallback remains functional when absent.
- WisperBot managed generation uses the tested, enabled Super Admin AI / LLM integration marked as the managed default. If no database default has been selected, the configured legacy OpenAI managed provider remains the compatibility fallback. Workspace credentials are never substituted into the managed pool.
- Provider mode is `managed`, `byok`, or `auto_fallback`. New and unset workspaces default to `auto_fallback`. It consumes managed credits first and uses a customer provider only when that provider is enabled and has a successful connection test; invalid, expired, or missing fallback credentials pause the action and prompt provider setup or reconnection after managed credits become unavailable.
- DeepSeek is configured only by Super Admins as `llm_deepseek_default` under Integrations → AI / LLM. It is excluded from client provider payloads, client update/test routes, workspace BYOK, and automatic fallback. DeepSeek has no compatible embedding endpoint in this integration, so Knowledge Base embeddings require an enabled OpenAI or Gemini system/workspace provider. Review DeepSeek data-processing, retention, training, and data-location terms before enabling the system integration.
- Alibaba Qwen 3.7 Flash is configured only by Super Admins as `llm_qwen_default`. Its encrypted API key, region, and Model Studio Workspace ID derive an allowlisted region-specific `maas.aliyuncs.com` endpoint; arbitrary endpoints are rejected. The connection test calls `qwen3.7-flash` with thinking disabled. Qwen is generation-only in WisperBot, so Knowledge Base embeddings still require OpenAI or Gemini.

## Billing

Only Stripe, PayPal, and Paddle are supported. Webhooks are CSRF-exempt but must be signature-verified in their controllers. Provider price IDs and recurring subscription reconciliation are operational configuration, not client-supplied values.

## Realtime and mobile

- Browser Pusher auth: `/broadcasting/auth`, Laravel session and CSRF.
- Mobile Pusher auth: `POST /api/v1/broadcasting/auth`, Sanctum bearer token.
- Mobile subscribes only to channels authorized by the same workspace checks as the web app.

## Provider-specific guides

- [eBay Seller Messaging](../EBAY_SELLER_MESSAGING_SETUP.md)
- [Amazon Seller Messaging](../AMAZON_SELLER_MESSAGING_SETUP.md)
