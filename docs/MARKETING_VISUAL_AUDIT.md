# Homepage visual audit and product-led artwork

Reviewed: 2026-09-25. Branch: `oris`. Included in the marketing artwork commit; not deployed.

## Decision

Latest photography revision: the initial muted office photo is superseded by `vibrant-support-team`, with sunny light, bold orange/cobalt wardrobe colors, and candid energy. Prompt: `output/marketing-visuals/2026-09-25/VIBRANT_PHOTOGRAPHY_PROMPT.md`. Actual UI captures remain unchanged.

The user rejected the initial generated sculptural/ceramic collection as childish and the product demonstrations as inaccurate. The replacement follows their TRU and Mullet visual references: authentic UI crops, layered product cards, clean bento compositions, generous whitespace, and selective professional photography. Preserve WisperBot Orange `#FF762E`, warm white, pale sage, and evergreen `#183C31`; do not copy the references' lime palette.

## Product inspection and boundaries

Reviewed the signed-in production client dashboard, Omni Inbox, Smart Bots, Knowledge Bases, Automations, Social Media Automation, Connected Stores, and Email Setup. These establish interface shapes and supported workflows, not proof of every integration functioning. The reviewed workspace had no connected store; AI rollout notices remain meaningful.

Captures exclude customer records, conversations, account identifiers, credentials, and metrics. The social composer contains fictional unsaved copy. Workflow nodes were arranged in an existing empty draft but never saved, tested, or activated. Those transient forms were discarded. The widget uses the actual local renderer with an isolated fictional session and sends no account message or AI request. No production record was changed for this artwork.

## Placeholder inventory and replacement map

| Homepage placement | Previous visual | Revised visual |
| --- | --- | --- |
| Hero | Code-rendered inbox demonstration | Brand board with real inbox filters and native widget |
| Product-tour tabs | Invented demo screens | Actual interface crops for each product |
| Conversation lifecycle | Repeated demonstrations | Matching real product excerpts; scroll behavior retained |
| Smart AI / Knowledge | Generated knowledge sculpture | Actual Add Document source form |
| Omni Inbox | Generated generic support scene | Professional workplace photo plus actual composer |
| Email MasterBox | Illustrative mailbox UI | Actual Email Setup choices |
| Website chat / SDK | Illustrative chat | Real website widget with fictional onboarding messages |
| Mobile Agent App | Generic phone/photo scene | Official WisperBot App Store screenshots |
| Automation | Sculptural branching objects | Real AI Reply and If/Else node cards |
| Social publishing | Generated calendar still life | Real composer with unsaved sample copy |
| Commerce | Generated shopping objects | Real Shopify/WooCommerce/BigCommerce connection choices |
| Integrations | Original provider marks | Retained authentic marks |
| Audience / developer explanation | Labelled code-native diagram/pseudocode | Retained; not presented as screenshots |
| Blog covers / final CTA | Published covers / decorative motion | Retained; unrelated to product-screen accuracy |

Scope is the homepage. Other product pages retain their existing labelled demonstrations and require separate review before claiming site-wide replacement.

## Asset provenance

Delivery folder: `public/images/marketing/product-ui/`.

| Assets | Source |
| --- | --- |
| `inbox-filters.png`, `inbox-composer.png` | Actual Omni Inbox, record-free crops |
| `knowledge-source.png` | Actual Add Document modal, no submitted form |
| `social-composer.png` | Actual post composer with fictional unsaved content |
| `store-connections.png`, `email-connections.png` | Actual empty connection interfaces |
| `automation-ai.png`, `automation-condition.png` | Native workflow cards, illustrative arrangement |
| `website-widget.png` | Real widget renderer and isolated local sample session |
| `agent-app-inbox.webp`, `agent-app-conversation.webp` | Official [WisperBot App Store listing](https://apps.apple.com/ng/app/wisperbot/id6797157205) |
| `vibrant-support-team.webp`, `vibrant-support-team-768.webp` | Built-in image generation; fictional American-European colleagues, not staff or endorsements |

The only generated image in the revised collection is the office photograph. No product interface is AI-generated. Original logos remain code/local assets. Captions disclose actual interface versus demonstration content; alt text and reserved dimensions are included. Images below the hero load lazily. Native UI text stays faithful to its captured locale; surrounding captions use translation fallbacks.

Master photograph, exact prompt, widget capture fixture, and rejected exploration archive: `output/marketing-visuals/2026-09-25/`. Current prompt: `VIBRANT_PHOTOGRAPHY_PROMPT.md`; earlier prompts are historical. PNG masters and rejected WebPs are local-only ignored files; prompts and the isolated fixture are tracked. Delivery assets are all tracked under `public/`. Native capture dimensions are preserved; no claim of higher-resolution source material is made.

Optimization check: all 13 delivery assets total 437,717 bytes (about 428 KiB). The vibrant photograph is 151,246 bytes at 1536px and 54,048 bytes at 768px, selected via `srcset`. Official App Store images are already WebP. Lossless WebP trials increased most native UI screenshot sizes, so their small PNG originals remain to preserve text fidelity. Trial encodings were discarded. Below-hero images are lazy-loaded with dimensions reserved to prevent layout shifts.

## Delivery and verification

Only frontend and static assets change. No migration, API, SDK, worker, or production settings change is required. Deploy the matching frontend bundle and `product-ui` assets only after review.

- Frontend suite: 26 files, 124 tests passed, including four new artwork provenance/rendering tests.
- Production build and changed-file ESLint passed; existing large-bundle warnings remain.
- Changed-file ESLint and `git diff --check` passed. Desktop and 390px Chrome review confirmed the artwork loads and fits; a mobile grid clipping issue was corrected.
- At 320px, the artwork fits, but the full page still reports 348px document width despite a 305px body/app width. This outer-page overflow is not yet diagnosed; do not mark the entire 320px journey as passed.
- Visual approval remains with the user; tests do not constitute approval or deployment.
