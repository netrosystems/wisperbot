# Integrations

Last verified against code: 2026-09-03.

All application secrets belong in encrypted database configuration or environment variables. Never place credentials in this document.

## Integration ownership model

Social publishing connections do not imply comment access. Only Facebook Pages and linked Instagram professional accounts have Comments adapters, with independent account checks. YouTube, LinkedIn and TikTok require separate adapters and authorization/approval; the client picker and mobile catalog mark them not integrated. See [platform availability](SOCIAL_COMMENTS.md#platform-availability-2026-09-05).

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
- Inbox Channel Setup includes a Meta connection repair action for Instagram and Messenger channel accounts. It re-registers the app-level Meta webhook and re-subscribes the connected Facebook Page to messaging callbacks without deleting the account. Use it when a channel account is active in WisperBot but `/webhooks/meta/{verify_token}` receives no hits.
- Messenger/Instagram private-message attachments must be normalized into typed inbox messages (`image`, `video`, `audio`, `document`, or `sticker`) instead of text-only payloads. Meta attachment URLs are treated as temporary provider URLs; web clients use the authenticated inbox media endpoint, and mobile payloads use an expiring signed mobile media endpoint so native image/video components can render without authorization headers while keeping cache keys stable across ordinary payload refreshes. The media proxy streams cached/provider files instead of depending on the public storage URL, so broken storage aliases do not block app rendering. HEIC/HEIF photos from staff uploads, website-widget uploads, Meta, or WhatsApp should be converted to cached JPEG previews server-side when one supported converter is available: PHP Imagick with HEIC support, ImageMagick `magick`/`convert`, `heif-convert`, or `ffmpeg`.
- Mobile conversation summaries may expose generic media preview labels such as `Image`, `Audio`, `Video`, or a document filename in `last_message.body` and `latest_message_preview` when the actual media message body is empty. Message detail payloads must keep the real body empty so provider-facing sends do not turn media-only attachments into customer-visible text.
- Outbound Messenger media replies must use attachment sends for `image`, `video`, and `audio`; generic voice labels such as `Voice message` are UI/body fallbacks and must not be sent as customer-visible text. Mobile voice uploads may arrive with generic MIME types, so the server normalizes common audio extensions such as `.m4a`, `.weba`, and `.opus` before provider upload.
- Outbound provider images are normalized at the server boundary for staff web and mobile sends. Actual JPEG/PNG bytes pass through; HEIC/HEIF, WebP and GIF become JPEGs. WhatsApp receives a temporary JPEG upload through the conversation-bound phone number, while Messenger, Instagram and Telegram receive a persisted provider-safe JPEG URL. Client filenames remain display metadata, captions are preserved, and a JPEG retaining an iOS `.heic` name is not needlessly decoded.

### WhatsApp Embedded Signup modes

WisperBot intentionally exposes two distinct WhatsApp setup choices:

- **Existing WhatsApp Business app (Coexistence):** the Facebook SDK login payload sets `extras.featureType` to `whatsapp_business_app_onboarding` and `sessionInfoVersion` to `3`. Meta may finish with `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING`. WisperBot must not call `/{PHONE_NUMBER_ID}/register`, because the number is already registered to the Business app.
- **Cloud API-only:** uses the normal Embedded Signup flow and registers the selected phone number when required.

Embedded Signup requests Meta's `popup` presentation. WisperBot shows compact inline progress inside the existing Channel Setup drawer, not a second centered dialog. Meta authentication cannot be embedded in an iframe inside WisperBot; browser popup policy may still present the provider-controlled window as a tab, especially on mobile or when the user's popup preference requires it. The originating WisperBot page must remain open so the Facebook SDK callback can complete.

For Coexistence, WisperBot subscribes the WABA to `messages`, template/account/phone updates, `history`, `smb_app_state_sync`, and `smb_message_echoes`. After connection it requests `smb_app_state_sync` followed by `history` through `/{PHONE_NUMBER_ID}/smb_app_data`. History is imported silently, without firing inbound AI/automation events or creating unread counts. Live phone-app echoes are stored as outbound human messages and broadcast to open agent dashboards.

Meta currently prevents a Business Portfolio that owns the Meta app from selecting its own WABA inside that app's customer Embedded Signup flow. This is a provider ownership rule, not a missing WisperBot selection. The platform owner's WABA must be connected operationally with an approved system-user token and explicit asset assignment; customer WABAs continue through Embedded Signup. Never expose that platform token in the client UI.

### WhatsApp health and repair

Channel Setup exposes `GET /app/whatsapp/setup/{waba}/health`, `POST .../health/check`, and `POST .../repair`. Mutation endpoints require workspace ownership/administrator membership, reuse active operations, and throttle new operations. Queued responses return HTTP 202 with `operation_id`, `success`, `message`, and an additive `health` summary. The legacy `.../reregister-webhook` route delegates to the repair flow and keeps those response fields; it no longer marks an account active based on a provider POST alone.

Checks validate token app/scopes, WABA access, subscription app identity, connected phone membership/status, shared callback/event fields, and scheduler/worker evidence. Phone detail fields unavailable from Meta remain unknown. A phone outside the first provider page is treated as unknown when pagination exists, never falsely reported removed. `whatsapp_business_management` and `whatsapp_business_messaging` are required; `business_management` is not universally required for customer health checks.

Repairs use the customer WABA credential, or an explicitly configured operator credential whose WABA ownership is verified. App access tokens are used only for app-level inspection/configuration, never WABA subscription writes. Repair restores a missing subscription and refreshes metadata for existing phones, then reads the app subscription back. Callback overrides require administrator review. Coexistence repair never calls `/register` or `/smb_app_data`.

Health is separate from routing status. `ready` means checks passed; `delivery_verified` requires observed processing of a real live customer message after repair. History, echoes, statuses, and unsigned local test callbacks do not establish receipt evidence. Check/repair histories contain sanitized reason codes and component results, not provider errors or credentials.

### Review evidence

Public comments now have a separate Social module path behind `SOCIAL_COMMENTS_ENABLED`. See [Social Comments](SOCIAL_COMMENTS.md) for matching Facebook Login scopes, signed webhook routing, provider limitations, and the pending external-client review gate. App Live status alone does not grant comments access. Checks preserve Page messaging fields and use customer tokens for every asset write; app tokens are diagnostic-only.

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
- In the website widget, YouTube/Vimeo videos open on the provider's own page from a “See Tutorial →” link (2026-09-19), so no customer-site CSP change or Vimeo embed-domain permission is needed. The agent Inbox still renders them after Play is selected.
- External messaging channels cannot render web players, so AI answers append `Watch video: CANONICAL_URL` while WisperBot clients use the structured resource card.
- Provider tests must surface the actual category (invalid key, model unavailable, quota, network), not collapse everything into “bad credentials.”
- Only select chat/embedding models that the provider project can list/access.
- Qdrant uses `QDRANT_URL` and `QDRANT_API_KEY`; MySQL fallback remains functional when absent.
- Qdrant HTTP retries return final HTTP responses for explicit status handling. A missing `kb_chunks` collection is a no-op during vector deletion and is created on the next vector write. Other deletion failures still block removal; transport failures remain exceptions.
- WisperBot managed generation uses the tested, enabled Super Admin AI / LLM integration marked as the managed default. If no database default has been selected, the configured legacy OpenAI managed provider remains the compatibility fallback. Workspace credentials are never substituted into the managed pool.
- Provider mode is `managed`, `byok`, or `auto_fallback`. New and unset workspaces default to `auto_fallback`. It consumes managed credits first and uses a customer provider only when that provider is enabled and has a successful connection test; invalid, expired, or missing fallback credentials pause the action and prompt provider setup or reconnection after managed credits become unavailable.
- DeepSeek is configured only by Super Admins as `llm_deepseek_default` under Integrations → AI / LLM. It is excluded from client provider payloads, client update/test routes, workspace BYOK, and automatic fallback. DeepSeek has no compatible embedding endpoint in this integration, so Knowledge Base embeddings require an enabled OpenAI or Gemini system/workspace provider. Review DeepSeek data-processing, retention, training, and data-location terms before enabling the system integration.
- Alibaba Qwen 3.7 Flash is configured only by Super Admins as `llm_qwen_default`. Its encrypted API key, region, and Model Studio Workspace ID derive an allowlisted region-specific `maas.aliyuncs.com` endpoint; arbitrary endpoints are rejected. The connection test calls `qwen3.7-flash` with thinking disabled. Qwen is generation-only in WisperBot, so Knowledge Base embeddings still require OpenAI or Gemini.

## Billing

Only Stripe, PayPal, and Paddle are supported. Webhooks are CSRF-exempt but must be signature-verified in their controllers. Provider price IDs and recurring subscription reconciliation are operational configuration, not client-supplied values.

## LinkedIn (2026-09-20)

LinkedIn needs **two developer apps**, because its Community Management API "requires that it be the only product on the application": an app that also has Sign In with LinkedIn or Share on LinkedIn can never be granted it.

| App | Products | Scopes | Used for |
|---|---|---|---|
| Sign-in app | Sign In with LinkedIn (OIDC), Share on LinkedIn | `openid profile email w_member_social` | The member's own profile |
| Company Page app | Community Management API only | `r_organization_admin w_organization_social` | Pages the member administers |

Both apps register the same callback (`{APP_URL}/app/social/accounts/callback/linkedin`). Admin → Integrations → LinkedIn OAuth holds the sign-in keys plus optional **Company Page Client ID/Secret**; Company Page connecting appears only when that pair is filled, so LinkedIn stays personal-profile only until the second app is approved.

- **Connecting.** `GET .../accounts/connect/linkedin` authorizes the member; `?target=pages` authorizes the Company Page app instead, and the variant travels in the OAuth state. The Page authorization has no sign-in scopes, so no member profile is read; Pages come from `GET /v2/organizationAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED`. The client then picks targets on `client.social.accounts.linkedin.select`, and only ids from that authorization can be stored.
- **Publishing.** Each connected target is its own `social_media_accounts` row. `meta.actor_type` (`member` or `organization`) selects the author URN: `urn:li:person:{id}` or `urn:li:organization:{id}` (`LinkedInDriver::publish()`).
- **Tokens.** LinkedIn issues no per-Page token and rotates refresh tokens, so every row from one authorization shares and rotates together (`RefreshSocialTokensJob::shareWithSiblings()`), refreshed with the keys of the app that issued them.
- **Comments** remain not integrated; they need Community Management comment permissions on top of posting access.

## X (2026-09-24)

X publishing allows **text with up to 3 images, or 1 video or GIF**, and **no links**, to keep X API credit use low. X's pay-per-use pricing, verified 2026-09-24:
- A post costs about $0.015.
- A post containing a URL costs about $0.20.
- Each uploaded image or video is billed like one more post, per an X staff answer on the developer forum on 2026-08-31. So one image or a video is about $0.03 in total, and 3 images about $0.06.
- Chunk appends and status checks are not billed.

WisperBot never sends media metadata (alt text, $0.005 per request).

- **Admin setup.** Admin → Integrations → X OAuth (`oauth_twitter`) holds the OAuth 2.0 Client ID and Client Secret from console.x.com, not the API key/secret pair. The app must be a confidential "Web App" with **Read and write** permission. It must register the callback `{APP_URL}/app/social/accounts/callback/twitter`. The X account that owns the app must hold credits and should have a spending limit. When credits run out, clients see "X API credits are unavailable. Contact your administrator."
- **Test Connection** (2026-09-25, `ConnectionTester::testX()`) sends a token request with a dummy code, using the stored Basic credentials. X checks client credentials first:
  - `invalid_client` means the Client ID or Secret is wrong, for example the API Key pasted instead of the OAuth 2.0 Client ID.
  - Any other 400 means the pair is valid.
  - No user is involved and nothing is billed.
  - Other social OAuth providers still report only that the credentials are present.
- **Connecting.** OAuth 2.0 Authorization Code with PKCE (S256): authorize at `https://x.com/i/oauth2/authorize`, and exchange at `https://api.x.com/2/oauth2/token` with Basic auth. Scopes are `tweet.read tweet.write users.read media.write offline.access`. An account connected before `media.write` was added can still post text. A post with media fails with "Reconnect your X account to allow images and video" before any X call. The exchange is rejected unless every scope and a refresh token are granted. The profile comes from `GET /2/users/me`.
- **Content rules** (`XContentRules`, mirrored client-side by `resources/js/Utils/xText.js`):
  - No links of any kind: any scheme, `www.`, bare or internationalised domains, shorteners, or e-mail addresses.
  - Media: at most 3 images (JPG, PNG or WEBP, 5 MB each), or 1 video (MP4 or MOV, 50 MB, capped by the 120-second `social` worker timeout), or 1 GIF (15 MB). No mixing.
  - At most 280 weighted characters. X's weighting counts emoji, CJK and most non-Latin characters as 2.
  - The composer, AI planner (`posts.{i}.body`) and `POST /api/v1/social/posts` all reject violating content with 422 or a validation error. Content is never silently altered. `XDriver::publish()` checks again, so no path can spend credits on a disallowed post.
- **Customize for X** (`social_media_posts.network_content`, `XPostContent`):
  - When an X account is selected, the composer and Edit show an **X version** panel.
  - **Off:** X publishes the shared text and media, and X's rules apply to them.
  - **On:** X gets its own text, and media picked from the post's own media. It starts from the shared text and the first video, or the first 3 images. Only this version must follow X's rules, so the shared post can keep links, long text and more media for the other networks.
  - Stored as `network_content.twitter = {body, media_urls}`, and read by `SocialPost::contentFor('twitter')` at publish time.
  - X media outside the post's media is rejected with 422 (`network_content.twitter.media_urls`). Errors are keyed `network_content.twitter.body` / `.media_urls`.
  - `POST /api/v1/social/posts` accepts the same optional `network_content.twitter` object. The AI planner creates shared posts only.
  - An X version is not stored when no X account is selected, and is not changed by a published-post text update.
- **Media upload** (`XMediaUploader`, `XMediaFetcher`, `XDriver::uploadMedia()`):
  - Every file is fetched and checked first, using real MIME sniffing and size. Nothing is uploaded, or billed, if any file fails the rules.
  - Media-library files (`{APP_URL}/storage/...`) are read from the public disk. Other URLs must be public HTTPS. They are downloaded with `KnowledgeUrlGuard`, without automatic redirects, with a connected-IP check and a size cap.
  - Upload is chunked: `POST /2/media/upload/initialize`, `/{id}/append` in 4 MB segments, then `/{id}/finalize`. Video processing is polled with `GET /2/media/upload?command=STATUS` for up to 45 seconds per attempt.
  - Each uploaded media id is saved at once in `social_media_post_accounts.provider_media` and reused until one hour before X's expiry (about 24 hours). A retry, a still-processing video or **Publish now** never uploads the same file twice. The ids are cleared once the post is published.
- **Tokens.** Access tokens last about 2 hours and X rotates the refresh token on every use. `SocialPublisher` refreshes right before publishing when the token expires within 5 minutes, under `Cache::lock('social-access-token:{id}')`, and stores the rotated pair. `RefreshSocialTokensJob` also refreshes X. An X account with a refresh token is not shown as expired and stays selectable in the composer.
- **No double charges.** X has no idempotency key. Media upload failures never create a post, so they are always safe to retry. `social_media_post_accounts.provider_attempted_at` is set just before the paid request. It is cleared only when X gives a definite answer: success, or a 4xx such as 401 reconnect, 402/403 credits, 403 permission, or 429 rate limit. After a timeout, a 5xx, or a success without a post ID, the outcome is unknown. The link fails with "X did not confirm this post. Check X before publishing it again." Queue retries never resend it. A client pressing **Publish now** clears the marker deliberately.
- **No remote edit or delete.** `XDriver` implements no `ManagesPublishedPosts`. `PublishedPostLifecycle::LOCAL_ONLY_NETWORKS` makes X-only posts removable from WisperBot only (`DELETE /app/social/posts/{post}/local`). Deleting a mixed post deletes the other networks' copies and leaves the X copy on X. The success message says so.
- **Comments** are not integrated.
- Live X API behaviour is covered only by faked HTTP in `tests/Feature/Social/XPublishingTest.php`. It still needs one real connect-and-post check after credits are bought.

## Realtime and mobile

- Browser Pusher auth: `/broadcasting/auth`, Laravel session and CSRF.
- Mobile Pusher auth: `POST /api/v1/broadcasting/auth`, Sanctum bearer token.
- Mobile subscribes only to channels authorized by the same workspace checks as the web app.

## Provider-specific guides

- [eBay Seller Messaging](../EBAY_SELLER_MESSAGING_SETUP.md)
- [Amazon Seller Messaging](../AMAZON_SELLER_MESSAGING_SETUP.md)
# 2026-09-05 verified crawler and AI compatibility

OpenAI admin connection tests exercise the configured managed routine/complex models and embedding model, rather than a separate hard-coded chat model. Default managed generation uses `gpt-4o-mini`; explicit configuration remains authoritative. Qdrant `kb_chunks` maintains integer payload indexes for `document_id` and `kb_id` so strict-mode cleanup and filtering work without weakening protection. Credentials must permit payload-index creation.
# Flutter suggested-reply support (2026-09-06)

The `Netro-Systems/wisperbot_chat` customer SDK supports additive text choices after its corresponding package/app release. A server deployment alone cannot update installed native UI. Older SDKs and external messaging channels retain numbered plain text. See [Suggested customer replies](CHAT_REPLY_OPTIONS.md).
