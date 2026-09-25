# WisperBot Public Website — Product-Led Revamp

Last reviewed: 2026-09-18. Experimental on `oris`; not promoted or deployed.

## Objective and positioning

Present the complete WisperBot platform, not only a chatbot landing page. The central promise is **Every customer channel. One AI support team.** Help visitors understand what they can connect, what AI can do, where people take over, and how to begin.

This document owns the public-site content architecture and rollout. [DESIGNSYSTEM.md](../DESIGNSYSTEM.md) owns the shared UI and approved chat-widget design. This redesign does not alter the actual customer widget, mobile app, SDK, AI runtime, or provider permissions.

## Reference direction

### Product-led artwork direction — 2026-09-25

Photography refinement: the team photograph now uses a bright, vibrant lifestyle-campaign treatment with orange and cobalt wardrobes, sky-blue surroundings, sunny light, and warm candid interaction. This supersedes the muted corporate photograph without changing the authentic product screenshots or page palette. Exact prompt: `output/marketing-visuals/2026-09-25/VIBRANT_PHOTOGRAPHY_PROMPT.md`.

The user rejected the initial generated sculptural collection. The revised direction follows the supplied TRU and Mullet brand-board references: clean bento layouts, actual interface crops, layered cards, restrained photography, and confident whitespace. WisperBot retains its orange/evergreen palette. Homepage hero, tour, lifecycle, and supporting product panels use actual product captures; mobile uses official App Store screenshots. One fictional American-European workplace photo adds human context. No AI-generated product screens are used. Assets live in `public/images/marketing/product-ui/`; capture fixture, photo master, exact prompt, and rejected exploration archive live in `output/marketing-visuals/2026-09-25/`. See the [visual audit](MARKETING_VISUAL_AUDIT.md). Local on `oris`, pending approval; other product pages are outside this imagery update.

Voiskey informs spacious composition, editorial headlines, subtle sage surfaces, product-led animation, and alternating visual rhythms. Widgo informs the progression from a problem to a working product. Crisp and BotSailor inform deep product navigation and multiple ways to explain a broad platform. These are directional references, not copied layouts, assets, or claims.

Keep WisperBot Orange `#FF762E`, Space Grotesk, and short Fraunces accents. Use a mostly white/light canvas, pale sage product stages, warm peach details, and selective evergreen contrast sections. Do not turn the whole site orange or dark.

## Information architecture

The header contains Products, Channels, Solutions, Resources, Developers, Pricing, conditional Download links, Log in, and Start free. Desktop disclosures support click, Escape, outside dismissal, and visible keyboard focus. Mobile uses a focus-managed slide-over with grouped disclosures.

The hero uses **Get Agent App** as its secondary action. It opens a minimal, position-stable anchored dropdown with Android/Google Play and iOS/App Store choices using recognizable store glyphs and the official WisperBot destinations. The menu dismisses on selection, Escape, or outside click and restores trigger focus after Escape. It is intentionally separate from the customer-facing SDK.

New durable pages:

| Group      | Routes                                                                                                                                                                                       |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Products   | `/products/omnichannel-inbox`, `/products/smart-ai-agent`, `/products/chatbots`, `/products/email-masterbox`, `/products/automation`, `/products/social-media`, `/products/mobile-agent-app` |
| Solutions  | `/solutions/customer-support`, `/solutions/sales-lead-generation`, `/solutions/ecommerce-marketplaces`, `/solutions/marketing-engagement`                                                    |
| Channels   | `/channels/customer-messaging`, `/channels/social-commerce`                                                                                                                                  |
| Developers | `/developers`                                                                                                                                                                                |

Each pillar has its own problem/outcome copy, benefits, three explanatory sections, illustrative product experience, FAQs, and related pages. Existing Pricing, Integrations, Use Cases, FAQ, About, Contact, Blog, article, and public CMS surfaces share the new shell. Existing contact submission, blog publishing, CMS HTML, and signup behavior remain intact.

## Homepage narrative

1. Outcome-led dark hero with a mouse-responsive dotted network, short launch reveal, one rotating channel signal inside the headline, free-plan value, and illustrative AI-to-agent inbox. The channel signal reuses local SVGs and never competes with the copy or actions.
2. Supported-provider ribbon using recognizable local provider SVG artwork. Provider brands never fall back to initial-letter tiles; non-brand concepts use the shared Lucide interface set.
3. Keyboard-operable product tour: inbox, AI, automation, social, commerce.
4. Scroll-linked connect → understand → respond → handoff → improve story.
5. Smart AI Agent, business knowledge, answer scope, and follow-up choices.
6. Omni Inbox and separate Email MasterBox.
7. Website chat, WhatsApp entry point, and customer-app SDK.
8. Android/iOS Agent App, distinct from the Customer Chat SDK.
9. Visual automations, contacts/segments, and run visibility.
10. Social publishing, with comment claims conditional on the rollout flag.
11. Store/order context and provider-specific marketplace workflows.
12. WhatsApp/SMS/segmented engagement.
13. Capability matrix explaining messaging vs publishing vs comments vs email/commerce.
14. Role/solution selector.
15. Integrations and developer extensibility.
16. Workspace access, team roles, official connections, authenticated APIs.
17. Free-plan entry and paid-plan comparison.
18. Actual published articles, or clearly labelled product guides when none exist.
19. Practical FAQs, final CTA, and deep product/company/legal footer.

## Product and proof rules

- Never invent customer counts, customer logos, reviews, uptime, compliance certificates, guaranteed AI accuracy, or measured conversion improvements.
- Code-native demonstrations are explicitly illustrations, not screenshots or evidence of real customer activity. Example names and conversations contain no customer records.
- Do not imply publishing implies DM/comment access. Instagram/Facebook permissions, Telegram Business eligibility, marketplace constraints, and provider fees remain explicit.
- Comment copy appears only when `social_comments.enabled` is true; enabling this flag alone is not evidence of public Meta approval.
- Prices, currencies, limits, and white-label entitlement on Pricing come from enabled plan records. A configured zero-credit limit is not rendered as unlimited.
- Free white-label messaging follows the enabled free plan's `white_label_enabled` value. Local plans currently do not grant it. Changing the commercial entitlement is a separate product decision, not a marketing edit.
- Do not publish app-store or pub.dev guesses. Missing links are hidden. The Agent App is for staff; the Customer Chat SDK is for embedding customer chat in the client's app.

## Content ownership

`config/marketing.php` is the version-controlled page/capability catalogue. `MarketingCatalog` adds safe public metadata and conditional claims. The Site Content admin controls announcement, navigation labels/links, hero, final CTA, contact email, eight categorized FAQs, SEO, demo destination, app-store links, pub.dev, and developer documentation URL.

Important settings:

- `landing.content_version=2`: adopts the new public copy projection; old stored rows are retained until the next save.
- `landing.agent_app_ios_url`: HTTPS, `apps.apple.com` only.
- `landing.agent_app_android_url`: HTTPS, `play.google.com` only.
- `landing.chat_sdk_pubdev_url`: HTTPS, `pub.dev` only.
- `landing.developer_docs_url`, `landing.demo_url`: validated HTTPS or same-site relative URLs.
- `landing.page_enabled`: preserves the existing public-site disable behavior.

Settings writes are allowlisted and fully validated before any write. Credentials, deprecated fabricated proof settings, and internal control keys are never public props. New copy uses `marketing.*` translation keys with English fallbacks; translated marketing copy remains a separate editorial task.

## Motion and performance

Use small CSS/React/SVG product demos, not GIF downloads, autoplay video, WebGL, or a new animation framework. Product tabs change the story; the lifecycle responds to scroll; message sequences, connectors, and subtle floating marks bring the product to life.

Hero direction follow-up (2026-09-18): direct desktop inspection of [Voiskey](https://www.voiskey.ai/) confirmed that its hero impact comes from a near-black stage, dotted pointer-responsive canvas, low horizon glow, and sequenced copy reveal. WisperBot translates that behavior into a code-native graphite, orange/amber, and evergreen treatment without copying its assets or claims. `HeroBackdrop` keeps a bounded SVG point cloud, adds requestAnimationFrame-throttled pointer tilt/light on fine pointers, and remains clipped and noninteractive. `HeroHeadline` replaces the surrounding icon cloud with one changing channel signal between “Every” and “customer,” using local platform SVG marks for WhatsApp, Facebook, Instagram, Messenger, Telegram, LinkedIn, X, TikTok, and YouTube rather than generic glyphs. `HeroLaunch` provides a short non-blocking brand reveal. Coarse pointers use a static composition; reduced motion removes the launch and rotation; manual pause disables pointer response. The shared header stays dark over this hero and transitions back to the normal white system at the hero boundary; other public pages are unchanged.

Icon-system follow-up (2026-09-18): the provider ribbon, integration directory, capability surfaces, and product illustrations share `ProviderBrandIcon`, backed by local provider SVGs including multicolour Gmail and Microsoft marks plus Telegram and marketplace artwork. The previous marketing-only text/Unicode monogram map was removed. Functional concepts and controls use `lucide-react`; Lucide is not used as a substitute for provider brand identity because it intentionally supplies interface icons rather than brand logos.

IntersectionObserver activates animation only near the viewport; hidden browser tabs pause demos. Respect reduced motion, provide a visible Pause animations control, and ensure content remains readable when animation is disabled. Reveal animations never gate navigation or interaction. The product illustrations are lightweight DOM rather than a second running application.

Reserve media dimensions, keep images lazy below the fold, and keep code split by existing Inertia/Vite page loading. Mobile layouts preserve reading order, horizontally scroll the capability table/tabs where necessary, and do not overflow the page. Do not claim a Lighthouse score without a production-bundle measurement.

## Accessibility and quality gates

Semantic headings, labelled forms, visible focus, keyboard tabs, Escape/focus restoration for menus, native FAQ disclosures, touch-sized actions, reduced-motion support, and contrast are part of the implementation. Test 320, 390, 768, 1024, and 1440px layouts.

Focused tests cover all 14 routes, invalid slugs, landing disable/sitemap behavior, conditional comments, safe official download URLs, public settings projection, entitlement-aware claims, mega-menu focus, downloads, filters, and billing-period switching. Run related blog/contact tests, all frontend tests, production build, changed-file Pint, ESLint, and static analysis; document unrelated analysis findings separately.

## Rollout

No migration, queue worker restart, SDK release, or application version increment is needed. Deploy matching backend/config/routes and locally built Vite assets together; clear relevant application caches through the normal release procedure. Remain on `oris` until design/content approval.

Before public launch: provide official iOS/Android/pub.dev URLs; confirm the free white-label commercial decision; approve final marketing copy and translations; run production-bundle performance/accessibility checks. Do not silently broaden integration permissions or billing entitlements.

## Authentication follow-through

Sign in and registration extend the public redesign into a conversion-focused auth shell. Desktop uses the graphite product story with truthful omnichannel, grounded-AI, and agent-handoff capabilities plus recognizable local provider marks. The form remains the dominant task on a warm light surface and becomes the entire content focus on mobile. Fabricated social proof and outcome metrics are prohibited. Registration stays compact: direct visitors begin on the enabled Free plan, while paid Pricing intent is summarized and continued after account creation rather than displaying a second plan catalogue.

The surface preserves locale selection, light/dark themes, accessible labels and focus, 48px form controls, password visibility, reduced motion, social login behavior, and the existing server-side validation and plan-selection flow.
