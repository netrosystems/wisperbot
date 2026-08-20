# Integrations

Last verified against code: 2026-08-21.

All application secrets belong in encrypted database configuration or environment variables. Never place credentials in this document.

## Integration ownership model

Super Admin configures platform-level applications/gateways. A client then authorizes or configures workspace-specific accounts. Tokens and credentials are encrypted and never returned in full to the browser. Provider asset IDs are bound to a workspace to avoid duplicate routing.

| Platform | Super Admin responsibility | Client responsibility | Inbound/outbound behavior |
| --- | --- | --- | --- |
| Meta Messenger | Meta app/configuration, OAuth redirects, permissions, webhook | Select/authorize Page | Page messages into inbox; agent replies; Page post publishing via Social module. |
| Instagram | Same Meta app plus Instagram permissions | Select linked professional account/Page | DMs into inbox; content publishing; provider capability limitations apply. |
| WhatsApp Cloud API | Meta app, Embedded Signup configuration, webhook | Authorize WABA and phone numbers | Inbound/outbound messaging, templates, auto replies. |
| Telegram Business | Integration configuration/webhook | Connect supported Telegram business/bot authorization | Queued inbound updates and agent replies. |
| Email | Google/Microsoft OAuth apps; server PHP IMAP for generic accounts | Connect multiple Gmail/Microsoft/IMAP-SMTP mailboxes | Email MasterBox sync/compose, separate from Omni Channel Inbox. |
| eBay | Developer keyset, RuName, scopes/environment | Seller OAuth | Poll/sync seller messages and reply; see provider guide. |
| Amazon SP-API | LWA/SP-API public app and messaging role | Seller OAuth | Order-specific allowed message actions; no mirrored inbound inbox. |
| Ecommerce | Platform OAuth/app settings as required | Connect Shopify, WooCommerce, BigCommerce stores | Products/orders/customers, webhooks, automation context. |
| AI | Provider availability/defaults; optional Qdrant | Workspace API keys/models and knowledge bases | Chat completion, embeddings, RAG and smart bots. |
| SMS | Enable supported gateway definitions | Configure workspace sender credentials | SMS campaigns and delivery callbacks. |
| Billing | Stripe, PayPal, Paddle credentials/webhooks | Select plan/add-on and checkout | Subscription and payment lifecycle. |
| Realtime | Pusher/Reverb server configuration | None beyond authenticated session/mobile token | Workspace/conversation broadcasts and presence. |
| Push | OneSignal and/or web-push configuration | User/device registration and permission | Agent mobile/browser notifications. |

## Meta

Meta uses two functional areas:

1. Inbox channel setup for Messenger/Instagram/WhatsApp conversations.
2. Connected Social Media for Facebook/Instagram publishing.

Do not assume authorization in one area automatically creates the correct record/token for the other. OAuth redirect URIs must exactly match the production URL, without an accidental `www` or trailing slash.

Connected Social Media treats Meta's granular OAuth `target_ids` as the source of truth for asset selection. Business Portfolio discovery may enumerate additional owned/client Pages, but the callback must filter those results before persistence so selecting one Page never connects another Page implicitly. If the selected targets cannot be verified, the connection fails without saving accounts.

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
- Provider tests must surface the actual category (invalid key, model unavailable, quota, network), not collapse everything into “bad credentials.”
- Only select chat/embedding models that the provider project can list/access.
- Qdrant uses `QDRANT_URL` and `QDRANT_API_KEY`; MySQL fallback remains functional when absent.

## Billing

Only Stripe, PayPal, and Paddle are supported. Webhooks are CSRF-exempt but must be signature-verified in their controllers. Provider price IDs and recurring subscription reconciliation are operational configuration, not client-supplied values.

## Realtime and mobile

- Browser Pusher auth: `/broadcasting/auth`, Laravel session and CSRF.
- Mobile Pusher auth: `POST /api/v1/broadcasting/auth`, Sanctum bearer token.
- Mobile subscribes only to channels authorized by the same workspace checks as the web app.

## Provider-specific guides

- [eBay Seller Messaging](../EBAY_SELLER_MESSAGING_SETUP.md)
- [Amazon Seller Messaging](../AMAZON_SELLER_MESSAGING_SETUP.md)
