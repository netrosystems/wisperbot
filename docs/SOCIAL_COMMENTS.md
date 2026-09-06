# Social comments: API, operations, and review checklist

Status: local implementation, 2026-09-05. Public Meta approval and external-client verification are still required. Native mobile screens are not part of this repository release.

Local QA preview: `http://127.0.0.1:8011/app/social/automation/comments` uses a process-only enabled flag. This does not enable `.env` or existing queue workers. Real end-to-end testing requires the flag enabled consistently for the web, scheduler, `social`, and `ai` processes, plus verified Meta access. Temporary UI sample records were removed after testing.

## Client workflow

Social Media Automation has Posts and Comments tabs when `SOCIAL_COMMENTS_ENABLED=true`. Comments is a compact list/detail workspace; list order does not jump when realtime events arrive. Agents reply publicly, resolve/reopen, pause AI on a thread, and use supported moderation actions with confirmation. Read markers are per user. Drafts survive selection/filter changes while the page remains mounted, but are not persisted to browser storage.

Owners/admins open AI reply settings, select the account and a chatbot with a published KB, confirm its public suitability, and save Suggestions only. Generate a suggestion and select **Mark preview reviewed** before enabling Automatic replies. A changed published revision requires renewed confirmation and preview. Off is the default. A paused or missing ancestor, unavailable author identity, imported history, business-account echo, or prior pending/sent reply prevents automatic response.

Public AI uses only the current published KB revision, a fixed public-safety instruction, and the current comment. It never calls the private conversation/order runner. Failed validation is refunded by the existing gateway. `social_comment_reply` costs one managed credit per successful generated result; sending/retrying that result costs no further AI credits. Public answer caching is separate from private answer caches and checks the current retrieved context. Exact approved FAQs may bypass generation.

## Web and mobile contract

Web base: `/app/social/automation/comments` (session + CSRF). Mobile base: `/api/v1/mobile/social/comments` (Sanctum + active mobile workspace). All IDs below are local WisperBot IDs except the opaque operation UUID.

| Method/path relative to base | Contract |
| --- | --- |
| `GET /` | Cursor-paginated `comments`, `counts`, safe `accounts`, `filters`, `canReply`, `canManage`, `workspaceId`; admin-only chatbot choices. Web navigation renders Inertia; JSON Accept returns JSON. |
| `GET /{comment}` | Comment, safe account, source post, cursor-paginated direct replies, latest 20 operations, account settings. GET does not mark read. |
| `POST /{comment}/read` | Mark read for this authenticated user. |
| `POST /{comment}/reply` | `body` (1–2,000 characters), `idempotency_key` (max 100). Returns 202 with `operation`. Keep the same key when retrying an uncertain HTTP submission. |
| `POST /{comment}/suggest` | Queue a public KB suggestion; 202 with operation. |
| `POST /{comment}/suggestions/{operation}/review` | Owner/admin acknowledges a current successful preview. |
| `PATCH /{comment}` | Optional `status: resolved|needs_attention`, `ai_paused: boolean`. |
| `POST /{comment}/moderate` | `action: hide|unhide|delete`, `idempotency_key`; explicit user confirmation required by UI. |
| `POST /operations/{operation}/retry` | Retry a definitely failed outbound action only. Delivery-unknown returns 409 and never resends blindly. |
| `POST /accounts/{account}/settings` | Owner/admin only: `mode: off|suggestions|automatic`, `chatbot_id`, `public_kb_confirmed`. Published/public-confirmed KB required for AI. |
| `POST /accounts/{account}/sync` | Owner/admin connection check and bounded sync; 202. Per-account 60-second coalescing. |

List parameters: `tab=needs_attention|all|ai_handled|resolved` (default needs_attention), `network=facebook|instagram`, `account_id`, `search`, `cursor`, `selected`. List page size 40; reply page size 50. Fetch `next_page_url` until complete. Preserve draft text client-side during pagination. Native apps must use capability flags and never treat a queued operation as delivered.

Operation statuses: queued, sending, suggested, sent, needs_attention, failed, canceled, delivery_unknown. Replies have agent/AI attribution. Realtime event `.social.comments.changed` on private `workspace.{id}` carries only workspace/comment IDs; re-fetch authorized data. Mobile authenticates this channel through the existing Sanctum broadcast endpoint. Feature disabled returns 404; role denial 403; stale/unknown delivery conflicts 409; validation 422; rate limiting 429.

## Meta access and limitations

### Platform availability (2026-09-05)

`SocialCommentCapabilities::catalog()` supplies the connection picker, Comments help, and additive `commentPlatforms` web/mobile response. `implemented` means an adapter exists; `enabled` also requires the release flag. Neither implies verified account access.

| Platform | WisperBot Comments | Requirements and differences |
| --- | --- | --- |
| Facebook | Implemented; permission/flag gated | Managed Pages, approved scopes and subscriptions; not personal profiles. |
| Instagram | Implemented; permission/flag gated | Linked professional account with comment scopes; no complete ad/live coverage claim. |
| YouTube | Not integrated | Separate Data API adapter, `youtube.force-ssl`, quota handling and per-thread `canReply`. Existing OAuth requests upload/readonly only. |
| LinkedIn | Not integrated | Community Management approval, member/organization-specific grants and actor roles; publishing authorization is insufficient. |
| TikTok | Not integrated | Separate eligible business comment integration. Content Posting is not comment access; Research API is not customer-service reply access. |

References: [YouTube replies](https://developers.google.com/youtube/v3/docs/comments/insert), [LinkedIn Comments API](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/comments-api), [TikTok official business Comments SDK](https://github.com/tiktok/tiktok-business-api-sdk/blob/main/js_sdk/docs/CommentsApi.md). No extra scopes are requested for unimplemented adapters. Non-Meta accounts fail closed before Meta HTTP, even with stale capability records. Hide/delete are checked independently at enqueue and delivery.

The implementation uses the existing Facebook Login flow. Request `pages_read_user_content`, `pages_manage_engagement`, and `instagram_manage_comments`, retaining `pages_show_list`, `pages_read_engagement`, `pages_manage_metadata`, and `instagram_basic`. Existing connections need consent for the new scopes. The Page linked to Instagram is retained in account metadata. App-level Page `feed` and Instagram `comments` webhook subscriptions must be configured by the operator using the existing signed Meta callback. Checks inspect the app subscription before claiming readiness and preserve existing Page messaging fields when subscribing the account.

Customer asset operations use the stored customer Page token. The app access token is used only for token/app-subscription diagnostics. No platform system-user asset assignment is performed. Provider permission rejections become safe reconnect/setup guidance.

Official reference: [Meta Instagram API collection](https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api), plus the live Meta permission dashboard. Facebook and Instagram access families are not interchangeable.

Initial discovery scans posts/media from the previous 30 days; incremental checks also scan up to 100 recently observed posts, including older posts received by webhook. Each run performs three API pages then resumes with encrypted cursors; a cycle is capped at 500 API pages. Imports never trigger AI. Full historical coverage and ad-account discovery are not claimed: `ads_discovery=false`. Supported ad-post comment events can be ingested when Meta delivers them for the authorized asset. Ads-specific external account tests remain required.

Read reconciliation may confirm an unknown reply only when a unique own-account reply matches the parent, exact body, and ten-minute request window. Missing matches remain unknown; they never justify automatic retry. A platform deletion tombstone cannot be resurrected by a stale import. Provider edit/delete/hide and Instagram event shapes need live verification; local fakes alone do not prove public availability.

## Deployment and review gate

1. Back up the database; deploy the matching backend and locally built `public/build` with the flag false.
2. Run migration `2026_09_05_150000_create_social_comments_tables`; no existing conversations or posts are migrated/deleted.
3. Consume `social` and `ai`; workers must allow the 120-second sync timeout and queue `retry_after` must be at least 180 seconds. Use shared cache locks across workers. Restart workers and rebuild config cache.
4. Enable on a staging/operator deployment, check account access and webhook subscriptions, then test manual replies before AI.
5. Confirm a real incoming comment, manual reply visible on Meta, AI preview, opt-in automatic reply, handoff, and mobile API behavior.
6. Obtain required public-client permission approval and verify a client with no app role before general rollout. Keep the unrelated rejected `pages_manage_posts` and `instagram_manage_messages` approvals tracked separately.

Rollback by disabling `SOCIAL_COMMENTS_ENABLED` and restarting workers. Existing posts and private messaging continue. Do not run migration rollback in production to disable the feature: it removes stored comments and operations.

### Recording checklist for the app owner

After the live flow is verified, record: client login → account authorization and asset selection → Comments page → a real customer comment on Facebook/Instagram → comment arrival in WisperBot → explicit public reply → reply visible on the original platform. Show AI off by default, choosing the published KB, reviewing a suggestion, enabling automatic replies, and a question answered publicly. Show a complaint left for an agent. Demonstrate only requested capabilities, with clearly identified test assets and no exposed tokens or private customer data.

Review explanations should identify the exact permission and working UI action. Never claim that this release is approved or that a permission has been used until the corresponding real API test succeeds. Video recording and final Meta submission remain with the app owner.
