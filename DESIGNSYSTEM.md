# WisperBot — UI/UX Design System & Strict Style Guidelines

## Conversation activity

Join and resolve activity uses a compact centered system label. In the website widget it is muted text without a filled container, border, or shadow, with the actor name slightly darker but at the same weight for scanning; staff web and Flutter use an equivalent neutral treatment appropriate to their light and dark surfaces. It never uses message-bubble tails, avatars, delivery status, or timestamps.

## Suggested reply buttons

Place up to three wrapping text choices immediately below an AI answer. Web choices use a compact 34px minimum height, 8px corners, soft neutral borders, a subtle fill, and 13px medium-weight labels; keep 6px between choices and 8px above the group. On devices with a coarse pointer, retain a 44px minimum touch target. Preserve visible focus, dark-mode contrast where supported, and a labelled group. Do not replace the text composer. Disable historic, already-answered, in-flight and human-handoff choices without making their labels illegible. Agent inbox choices are read-only labels, not agent CTAs. Avoid repeated numbered text when the structured buttons are rendered.

## Customer chat widget shell

Starter questions (2026-09-19): when a Smart Bot has them on, up to five full-width, left-aligned outlined chips (12px radius, 13px text, 44px minimum on touch screens, `role="group"` labelled “Common questions”) stack directly under the welcome message, indented to the bubble column. They stay at the top of the conversation and remain tappable after later messages, but are disabled while sending, during pre-chat and while a person handles the chat. They look like reply pills but are not tied to one message.

The approved website widget uses a compact white identity header with a small brand-colour avatar tile. Scheduled teammate photos appear before the plain-language `Team available now` label. When human help becomes eligible, the slim peach `Need a person? / Talk to an agent` row sits directly below the header and does not use an arrow. The conversation uses a soft-grey canvas, white assistant bubbles, brand-colour visitor bubbles, and recognisable outlined reply pills. Keep attachment and voice-record controls in the composer, use a very short peach Powered by strip, retain the elevated panel shadow and lightweight launch animation. After handoff, show the joined teammate's public name and photo; use initials when no teammate photo exists. Smart Bot messages continue to use the configured company mark.

This document defines the mandatory UI/UX standards, component specifications, color tokens, layout archetypes, and accessibility patterns for **WisperBot**. All frontend components, page views, and design modifications must strictly adhere to these guidelines.

## Public marketing website — product-led system (2026-09-18)

The full content architecture is maintained in [docs/DESIGN.md](docs/DESIGN.md). The website introduces the entire platform through a rich homepage, mega navigation, product/solution/channel pillars, and a developer surface. It must not be reduced to a generic feature-card landing page.

### Positioning and truth

- Promise: **Every customer channel. One AI support team.**
- Lead with the customer outcome, then show how AI, knowledge, channels, and people work together.
- Use clearly labelled product illustrations. Only call an asset a real screenshot when it is captured from the actual product and safely sanitized. Never present demonstration records as customers.
- No invented testimonials, customer logos/counts, growth metrics, uptime, or compliance claims.
- Pricing uses actual enabled plans. Free white-label claims require a matching enabled free-plan entitlement. Missing app-store/pub.dev URLs stay hidden.
- Messaging, social publishing, comments, Email MasterBox, and marketplace operations have different provider capabilities; preserve those differences.

### Visual language

- Mostly white and warm pale-sage surfaces: `#FFFFFF`, `#FAFBF7`, and softly tinted product stages.
- WisperBot Orange `#FF762E` is the primary brand/action color, not a full-page fill. Use dark button text where needed for contrast.
- Primary ink `#20241F`, muted copy `#686C66`; restrained deep evergreen `#183C31` for contrast bands.
- Space Grotesk throughout; short Fraunces emphasis adds editorial character in display headings.
- Large clear headlines, generous whitespace, 18–28px framed product stages, fine borders, soft shadows, and purposeful channel/flow details.
- The homepage opens on a dark graphite hero with a dotted network sphere, orange/amber horizon light, and restrained evergreen atmosphere. The decorative backdrop follows a fine mouse pointer through a requestAnimationFrame-throttled tilt and local light; disable that response for coarse pointers, reduced motion, and manual pause. Keep it clipped and noninteractive, with no external animation assets, WebGL, or video.
- Use one compact channel signal between **Every** and **customer** in the default headline. Rotate recognizable local platform SVG marks for WhatsApp, Facebook, Instagram, Messenger, Telegram, LinkedIn, X, TikTok, and YouTube through that single position; do not substitute monograms, generic email/chat glyphs, or restore the surrounding icon cloud. The complete headline remains one accessible text alternative, and custom client-authored hero titles fall back to a clean animated text treatment.
- Provider identities across the public website use the centralized `ProviderBrandIcon` renderer and recognizable local brand artwork. Never represent Gmail, Microsoft, LinkedIn, X, TikTok, YouTube, Shopify, marketplaces, or messaging providers with an initial, Unicode character, or improvised generic glyph. Use `lucide-react` for non-brand interface concepts and actions such as inbox, email, SMS, API, SDK, search, menus, arrows, and status; Lucide icons must not impersonate provider logos.
- On the homepage, the sticky header is dark only while it overlays the dark hero. Smoothly return to the standard white header as the hero's lower edge passes behind the header; inner marketing pages retain their normal light header throughout.
- The homepage launch sequence is a short decorative brand reveal followed by staggered headline, support copy, actions, and product-stage entrance. It cannot block controls, must disappear entirely under reduced motion, and must leave every element readable when animations are paused or unavailable.
- Alternate product tours, scroll stories, split layouts, bento, phone demos, workflow diagrams, a capability matrix, solution selector, and editorial links. Avoid endless identical card grids.

### Navigation and conversion

The shared header contains Products, Channels, Solutions, Resources, Developers, Pricing, conditional Download, login/workspace, and Start free. Mega menus explain product destinations, not only names. Mobile uses a focus-managed slide-over. The footer retains deep product, solution, resource, legal, language, and motion controls.

The homepage secondary hero action is **Get Agent App**. It opens a minimal, position-stable anchored iOS/Android dropdown rather than a modal or another marketing block. Use recognizable official App Store and Google Play glyphs with the official WisperBot destinations, support Escape/outside-click dismissal, restore focus to the trigger, and keep the staff Agent App clearly separate from the Customer Chat SDK.

Use **Start free** consistently; explain one channel and 100 monthly credits without implying a temporary trial. The hero uses outcome copy and an illustrative inbox/AI/handoff demonstration. Give each major capability a dedicated story and route. Separate staff Agent App downloads from the customer-app SDK.

Registration is a compact account-creation form, not a second Pricing page. Direct visitors see one small “Start on Free — no card required” assurance. When Pricing supplied a paid intent, show only a compact selected-plan summary, the next-step checkout explanation, and a Change link. Never render the full plan grid inside registration. Disable account creation with support guidance only when no enabled initial Free plan exists.

### Authentication surfaces

Sign in and registration use the same product-led language as the public website without becoming mini landing pages. On desktop, pair one restrained product-story panel with a focused form surface; the story panel is white in light mode and graphite in dark mode, then collapses to the form and mobile logo below `lg`. It may show recognizable local provider artwork and only verified capabilities—never fabricated customer totals, message volume, uptime, conversion, time-saved, or testimonial claims. Keep the headline concise, use Space Grotesk throughout the operational journey, and reserve WisperBot Orange for the main action and emphasis.

Forms use one clearly ordered heading, compact supporting copy, 48px controls, visible labels, password reveal controls, strong focus states, and a single primary action. Retain locale and theme controls, responsive stacking, dark mode, reduced-motion support, and the Free/paid-intent registration behavior above. Third-party sign-in uses recognizable provider artwork rather than generic glyphs. Authentication status/error messages must expose semantic live-region roles.

### Interaction, accessibility, and performance

- Lightweight CSS/React/SVG sequences and scroll-linked state, not heavy animation frameworks or autoplay video. Demos pause offscreen and when the browser tab is hidden.
- Respect reduced motion and the Pause animations control. All content remains readable without animation.
- Product tabs support arrow keys; disclosures expose state; menus close on Escape/outside navigation and restore focus. Mobile navigation traps focus through the existing Headless UI dialog.
- Preserve visible focus, descriptive labels, readable contrast, keyboard navigation, and touch targets. Only tabs/tables may scroll horizontally; the page must fit 320px.
- Use the existing page code splitting and lazy image loading. Do not claim measured performance scores without running the production-bundle audit.
- Reuse `resources/js/Components/marketing/*`, `LandingLayout.jsx`, and scoped `resources/css/marketing.css`; do not leak public-site styles into the authenticated dashboard.
- Admin-editable supporting copy, CTA labels/links, FAQs, SEO, contact, and download destinations stay in Site Content. Capability definitions and pillar structure stay version-controlled in `config/marketing.php`.

---

## Operational product interface — admin and client panels (2026-09-18)

Authenticated WisperBot surfaces use a compact, border-led SaaS workspace. The supplied dashboard reference informs density and hierarchy only; do not import its blue branding, ecommerce language, invented metrics, or unsupported widget controls.

### Shell and tokens

- Light canvas: `#F4F5F7`; light surface: `#FFFFFF`; border: `#E7E9EE`; primary text: `#20241F`; muted text: `#737A75`.
- Dark canvas: `neutral-950`; dark surface: `neutral-900`; dark border: `neutral-800`. Every shared primitive requires a complete dark variant.
- WisperBot Orange `#FF762E` owns primary actions, focus, progress, and selected navigation. Green, amber, and coral remain semantic status colours.
- Desktop sidebar width is `236px`; topbar height is `64px`; content is bounded at `1600px`. Cards use 14px corners, controls use 10px corners, and normal page gaps are 16–24px.
- Application UI uses Space Grotesk. Fraunces is not used inside admin/client operational interfaces.

### Navigation and first-run clarity

- Light mode uses a white sidebar; dark mode uses graphite. The active row has a pale-orange surface, orange leading rail, and orange icon. Inactive groups collapse, while Home and the group containing the current route open automatically. Group and scroll preferences are session-local.
- Client navigation is ordered by work frequency: Home, Customer conversations, Smart AI, Customers and outreach, Automation and social, Commerce, Reports and assets, Workspace management, then entitled developer tools.
- The client dashboard exposes compact links to the existing Inbox, Channel Setup, Smart Bots, Knowledge Bases, and Team destinations. These are navigation shortcuts, not new product features.
- Admin navigation is grouped into Overview, Clients and subscriptions, Revenue and plans, Content and localization, Platform services, Access and security, and Settings without changing permission checks.
- Mobile navigation opens from the 44px topbar menu control. Never restore a floating navigation button over page or composer content.

### Data and surfaces

- Dashboards show four priority KPIs first, compact secondary metrics next, and then a dominant trend panel with supporting charts and activity tables. Render only real server-provided values and existing actions; never imitate an unavailable Add Widget feature.
- Shared cards, inputs, selects, dropdowns, drawers, modals, tabs, pagination, tables, empty states, warnings, and loading states use the operational tokens above.
- Tables have 11px uppercase headers, compact readable rows, subtle hover, and horizontal containment on narrow screens. Status always includes readable text or an icon, never colour alone.
- Inbox, Email MasterBox, social comments, and the Automation Builder retain their task-specific viewport and canvas behavior while inheriting the shell, theme, controls, and focus treatment.

---

## 1. Typography & Font Hierarchy

### 1.1 Font Families: Space Grotesk & Fraunces

- **Primary Body / UI Font**: Strictly uses **`Space Grotesk`** (`'Space Grotesk', sans-serif`) across all standard headings, navigation, data tables, modals, badges, and controls.
- **Editorial Display Serif**: Uses **`Fraunces`** (`'Fraunces', 'Georgia', serif`) exclusively for marketing landing page display headers.
- **Font Stack**: `['Space Grotesk', ...defaultTheme.fontFamily.sans]`.

### 1.2 Typographic Scale & Weights

| Element                     | Size                | Weight         | Line Height | Tracking | Tailwind Class                                                                          |
| :-------------------------- | :------------------ | :------------- | :---------- | :------- | :-------------------------------------------------------------------------------------- |
| **Page Header Title**       | 24px (1.5rem)       | 700 (Bold)     | 32px        | -0.02em  | `text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100`              |
| **Section / Modal Title**   | 18px (1.125rem)     | 600 (Semibold) | 24px        | -0.01em  | `text-lg font-semibold text-neutral-900 dark:text-neutral-100`                          |
| **Card / Subheading Title** | 15px (0.9375rem)    | 600 (Semibold) | 20px        | 0        | `text-sm sm:text-base font-semibold text-neutral-800 dark:text-neutral-200`             |
| **Body & Table Cell Text**  | 13.5px (0.84375rem) | 400 (Regular)  | 20px        | 0        | `text-sm font-normal text-neutral-700 dark:text-neutral-300`                            |
| **Table Column Header**     | 11px (0.6875rem)    | 600 (Semibold) | 16px        | 0.05em   | `text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400` |
| **Badges / Micro-Labels**   | 11px (0.6875rem)    | 500 (Medium)   | 14px        | 0        | `text-xs font-medium`                                                                   |

---

## 2. Color Palette & The Brand Restraint Rule

The visual identity of WisperBot is defined by its signature **Orange & Amber** palette:

```text
Surface Canvas:  #fff8f3   Surface Subtle: #fff1e8   Card Surface: #FFFFFF
Brand Orange:    #FF762E   Accent Amber:   #FFBF00   Highlight:    #FFF78D   Coral Danger: #f04e2e
```

### 2.1 Brand Color Tokens (Source of Truth: `.branding`)

```json
{
    "brand": {
        "50": "#fff5ed",
        "100": "#ffe8d4",
        "200": "#ffcda8",
        "300": "#ffab70",
        "400": "#ff8a45",
        "500": "#ff762e",
        "600": "#f05a12",
        "700": "#c74310",
        "800": "#9e3615",
        "900": "#7f2f14",
        "950": "#451507"
    },
    "accent": {
        "50": "#fffdeb",
        "100": "#fffbc4",
        "200": "#fff78d",
        "300": "#ffe24a",
        "400": "#ffcf1f",
        "500": "#ffbf00",
        "600": "#e29400",
        "700": "#bb6c02",
        "800": "#985308"
    },
    "secondary": {
        "50": "#f6f5f4",
        "100": "#e7e5e3",
        "500": "#6f6660",
        "800": "#2b2621",
        "900": "#1c1814",
        "950": "#14100c"
    },
    "coral": {
        "50": "#fff3f1",
        "100": "#ffe4df",
        "500": "#f04e2e",
        "600": "#d8331a",
        "700": "#b32512",
        "900": "#7a1e16"
    }
}
```

### 2.2 The Brand Restraint Rule

> **IMPORTANT**:
>
> - **Brand Orange (`#FF762E` / `brand-500`)** is reserved for primary Call-To-Action buttons (e.g. "Save Changes", "Send Message", "Create Campaign"), active navigation highlights, and interactive toggles.
> - **Warm Charcoal (`#14100C` / `secondary-950`)** is used for structured navigation contrast, sidebar headers, and dark accent surfaces.
> - Avoid excessive saturated color fills across large background areas; maintain crisp whitespace with warm canvas surfaces (`#fff8f3`).

---

## 3. Border-Driven Surfaces & Soft Elevation

- **Soft Border Standard**: Structural definition relies on soft, clean borders (`border-soft` / `1px solid rgb(228 228 231 / 0.8)` in light mode, `border-neutral-800` in dark mode).
- **Subtle Shadows**: Use lightweight diffused shadows (`shadow-soft` or `shadow-soft-md`) on cards, floating popovers, and dialogs. Harsh, dark drop shadows are forbidden.
- **Rounded Corners**: Standardize on `rounded-soft` (6px) for buttons/inputs, `rounded-soft-lg` (12px) for cards and modals, and `rounded-full` for status badges and avatars.

---

## 4. Page Layout Archetypes

### 4.1 Standard Page Layout (`ClientLayout`)

- **Used For**: Dashboard, Settings, Team Management, Contacts Directory, Billing, Reports.
- **Structure**: Centered content container (`max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8`). Includes page header with breadcrumb navigation and right-aligned action buttons.
- **Contextual schedules**: Keep timing controls beside the feature they govern. Widget Appearance places a compact Permanent/Scheduled choice beneath AI answering. Team availability uses the same searchable timezone and seven-day editor with collapsed split windows, explicit All day and Ends next day labels, and a three-window daily limit. Explain disabled days in text; never rely on color alone.
- **Segment AI answering**: Channel Setup and Email Setup each show one stable, workspace-wide settings row above account management, even when no account is connected. The row uses a restrained icon tile, mode badge, selected bot/timezone context, and one Configure action. Configuration opens in a focus-trapped drawer with visually equal `Off / Always on / Scheduled` choices, progressive bot/schedule disclosure, and an explicit Save changes action. Schedule editing uses a focused secondary drawer view and does not persist until the parent setup is saved. Account cards do not repeat the control. Use short states such as Paused and Needs setup.
- **Email connection details**: Client Email Setup shows connection actions and mailbox health only. OAuth callback URLs, provider configuration diagnostics, and missing server credentials belong to Super Admin/integration operations and must not appear in the client workspace.
- **Conversation ownership**: An unjoined conversation replaces the composer with one primary Join Chat action. Joined ownership uses a compact name/status chip; takeover requires confirmation and disabled actions explain why they are unavailable. Customer widgets use a small waiting/joined status row rather than an agent-style message bubble.

### 4.2 Viewport-Pinned Layout (`InboxLayout`)

- **Used For**: Omni-Channel Inbox and Master Email Inbox.
- **Structure**: viewport-pinned column flexbox (`100dvh`, with `h-screen` fallback), with `min-h-0` flex children so the message stream scrolls rather than pushing the composer below the viewport.
    - Left: 3-column navigation / conversation list.
    - Center: Scrollable real-time message stream with sticky bottom composer.
    - Right: Collapsible contact info and contextual CRM details panel.
    - Prevents outer browser scrollbars, maximizing agent efficiency.
    - Mobile navigation belongs in a compact, normal-flow top bar with a minimum 44px menu touch target; never float the menu over the reply composer. Chat detail hides desktop filter/list columns below `lg` and provides a top-bar return link. The Omni list fills narrow screens, with filters in a collapsed disclosure and desktop-only map/empty pane.

### 4.3 Infinite Canvas Layout (`AutomationBuilder`)

- **Used For**: XYFlow Visual Workflow Automation Engine.
- **Structure**: Full-screen interactive canvas with drag-and-drop node sidebar, mini-map, zoom controls, and floating step inspector panel.

---

## 5. UI Component Primitives & Composite Standards

### 5.1 Button Variants (`Button.jsx`)

- **`primary`**: `bg-brand-600 text-white hover:bg-brand-700 shadow-soft` — Used for the main action on a page.
- **`secondary`**: `bg-neutral-100 text-neutral-800 hover:bg-neutral-200` — Used for secondary actions.
- **`ghost`**: `bg-transparent text-neutral-700 hover:bg-neutral-100` — Used for inline toolbars and list actions.
- **`danger`**: `bg-coral-500 text-white hover:bg-coral-600` — Used for irreversible delete actions.
- **`outline`**: `border-neutral-300 text-neutral-700 hover:bg-neutral-50` — Used for filter triggers and downloads.

### 5.2 Status Badges & Semantic Pills (`Badge.jsx`)

| Semantic Status       | Light Mode Classes                                   | Dark Mode Classes                                                      | Use Cases                                |
| :-------------------- | :--------------------------------------------------- | :--------------------------------------------------------------------- | :--------------------------------------- |
| **Brand / Active**    | `bg-brand-50 text-brand-700 border-brand-200`        | `dark:bg-brand-900/30 dark:text-brand-300 dark:border-brand-700`       | Active channels, Pro plan, Verified      |
| **Success / Synced**  | `bg-emerald-50 text-emerald-700 border-emerald-200`  | `dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-700` | Delivered, Resolved, Connected, Paid     |
| **Warning / Pending** | `bg-amber-50 text-amber-700 border-amber-200`        | `dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-700`       | Snoozed, Quota Warning, Pending OAuth    |
| **Danger / Failed**   | `bg-coral-50 text-coral-800 border-coral-200`        | `dark:bg-coral-950/40 dark:text-coral-300 dark:border-coral-800`       | Failed delivery, Expired token, Canceled |
| **Neutral / Default** | `bg-neutral-100 text-neutral-700 border-neutral-200` | `dark:bg-neutral-800 dark:text-neutral-300 dark:border-neutral-700`    | Draft, Archived, Unassigned              |

### 5.3 Modal Dialogs (`Modal.jsx`) & Slide-Out Drawers (`Drawer.jsx`)

- **Modals**: Powered by `@headlessui/react` `Dialog` with backdrop blur (`backdrop-blur-[2px] bg-neutral-900/40`), smooth scale transitions, and trapped focus.
- **Drawers**: Right-side sliding panel (`w-screen max-w-md`) for quick contact editing, message template previews, and filter trays.
- **Dismissal**: `Escape` key and backdrop clicks dismiss the dialog gracefully.

---

## 6. Table & Pagination Standards

1. **Table Container**: Encapsulated in `Card` surface with `overflow-x-auto`.
2. **Column Headers**: Styled in `text-xs font-semibold uppercase tracking-wider text-neutral-500` with subtle border-b.
3. **Empty States (`EmptyState.jsx`)**: When no data matches the filter, display an illustrated empty state with an actionable primary button (e.g. "Create your first segment").
4. **Pagination Bar (`Pagination.jsx`)**:
    - Numbered buttons with active state in Brand Orange (`bg-brand-600 text-white`).
    - Explicit `Previous` and `Next` text labels.
    - Rows-per-page selector with clear range indicator (e.g. `Showing 1–25 of 142 contacts`).

---

## 7. Dark Mode & Accessibility (a11y)

1. **Dark Mode Integration**: Full class-based dark mode (`dark:bg-neutral-900`, `dark:border-neutral-800`, `dark:text-neutral-100`).
2. **Focus Visibility**: Visible focus rings on all interactive elements (`focus:ring-2 focus:ring-brand-500/30 focus:ring-offset-1`).
3. **Screen Reader Support**: All icon-only buttons require an `aria-label` attribute (e.g. `aria-label={t('common.close')}`).
4. **Internationalization (i18n)**: All UI strings must be resolved via `useTranslation()` (`t('nav.inbox')`, `t('common.save')`) to support multi-language localizations.

# Knowledge video cards

Customer-facing chat (website widget; the Customer Chat SDK should match) presents a matched guide video as a “▶ See Tutorial →” chip below the reply text, styled like the reply-choice buttons, that opens the video's own page (YouTube/Vimeo watch page, or the MP4 file) in a new tab with `rel="noopener noreferrer"`. Its accessible name includes the video title and the provider (“opens YouTube in a new tab”). Nothing is embedded, so the customer's site needs no frame permissions (decision 2026-09-19, replacing the same day's in-chat player). Agent-facing Inbox surfaces keep the click-to-play card with a title and “Open video” link for staff review.

## Guided Knowledge Base workflow

Website sources accept the address customers naturally know (`domain.com`, `www.domain.com`, or a full URL). While indexing, use the existing readable status labels. When canonical resolution changes the host, show one compact success line—**Connected securely as [URL]**—beneath the source rather than exposing redirect terminology or requiring a confirmation dialog.

The default client surface uses plain-language five-step progress: Define, Add sources, Review quality, Test answers, Publish. Ready uses success green, review uses amber, and blockers/failures use danger red; color is always paired with text and an icon. Technical vector/embedding details remain in advanced diagnostics. An empty Sources step presents three direct choices—Entire website, Specific web page, and Upload files—and hides management-only search, filtering, duplicate add buttons, and unavailable forward actions until a source exists. File guidance prioritizes PDF, DOCX, TXT, and Markdown and uses a bordered brand callout to explain that supported video URLs may become playable customer-chat guidance. Source dialogs explain appropriate usage and limits before selection, display extracted passages and stable findings, and keep factual corrections subject to explicit client approval. Dialogs must trap focus and support Escape/backdrop dismissal when migrated to the shared `Modal` primitive.

Smart Bot configuration presents three compact answer-scope choices: **Business only — Recommended**, **Verified sources only**, and **General assistant**. Keep the unsupported-answer selector separate. Approved-source research is a compact toggle with a plain-language domain boundary. When the selected Knowledge Base lacks purpose, brand, or audience, show an amber explanation that the bot safely behaves as Verified sources only. Do not expose enum names, vector thresholds, provider prompts, or retrieval implementation details.

The Knowledge Base tester labels results **Answer**, **Clarification**, or **Fallback**. It may show compact Meaning and Wording confidence plus expandable selected passages for management users, but never embeddings, full hidden context, prompts, or customer data. Regression shortcuts cover greetings, paraphrases, shorthand, typos, post-greeting questions, ambiguity, and unrelated topics. Clarification uses amber, answer success green, and fallback danger styling with text in addition to color.

## Social Media Automation workspace

The Connect account picker pairs provider names with truthful comment availability. An expandable “Which platforms support comments?” explanation appears in the picker and Comments workspace. Avoid large capability cards. The picker scrolls within 85dvh so expanded guidance remains usable on narrow screens.

When comments are enabled, shared Posts/Comments navigation sits beneath the page header. Comments uses one bordered, bounded-height two-pane workspace: a 340–390px desktop list with compact status filters and a flexible public thread/composer. Mobile displays either list or detail with a Back action. Use restrained unread dots, small platform indicators, two-line excerpts, and textual state labels. Do not repeat the account-card grid here. Account AI settings use a focus-trapped drawer; public reply destination remains explicit; destructive moderation uses confirmation. Realtime events offer list refresh instead of moving rows under the user's pointer.

Social publishing uses one standard page with a compact connected-account strip above a scan-friendly posts list. Provider cards show no more than two identities until expanded; account reconnect/disconnect and post lifecycle actions use keyboard-accessible Headless UI menus. The page has one orange primary action, **Schedule Post**, while AI planning is secondary. When there are no accounts or posts, use compact explanatory rows rather than tall illustrated panels, avoid duplicate calls to action, and hide tabs and filters that have nothing to operate on. Post status tabs default to Upcoming once posts exist, and List/Calendar use a segmented view control rather than separate navigation destinations. Desktop post rows become compact mobile cards below the table breakpoint. New translation keys must include readable English fallbacks at critical navigation and page-heading boundaries, and locale-file changes must invalidate the server dictionary cache automatically.

## WhatsApp connection health

Channel Setup Embedded Signup keeps waiting guidance inline in the existing side panel. Do not stack a centered WisperBot onboarding dialog over that panel. Meta's own authorization window remains separate; loading disables the initiating button, and inline progress uses a polite status announcement.

Channel Setup uses a compact bordered health panel per WhatsApp account with a text-labelled state, last check and incoming-message timestamps, and a contextual Check connection, Repair connection, or Reconnect WhatsApp control. Client panels do not display webhook configuration, raw WABA/phone identifiers, or infrastructure diagnostics. Problems use plain-language repair/reconnect/support guidance; technical component results remain in Admin → Cron Setup. Color never carries status alone. Ready configuration and verified delivery use different copy; inactive/quiet channels must not be described as broken. Members can view health; only workspace owners/administrators see mutation controls. Admin → Cron Setup groups platform health and affected accounts with the separate health-worker command.

## AI credit visibility

Client pages show a compact header control formatted as **remaining / monthly total** (for example, `88 / 100`). Its popover exposes used, processing, remaining, and reset date without internal action or provider-mode keys. The Subscription page uses a segmented usage bar and a scan-friendly action table generated from the authoritative backend credit catalog. Warning and exhausted states pair semantic color with text, retain the numeric counter on narrow screens, and provide visible keyboard focus.

The client header prioritizes operational controls and does not include global Search or a language selector. Locale selection remains available on public/authentication surfaces where language choice is part of entry into the product.

## Team identity and customer chat

The client Team screen shows a circular teammate photo beside the name and offers one compact image picker in both Add member and Edit member. Accepted photos are JPG, PNG, or WebP up to 5 MB; initials are the accessible visual fallback when no photo exists. Upload, replace, and remove actions must remain explicit, preview the pending file, and preserve the rest of the member form.

Widget chrome (2026-09-19): the header “•••” is a real menu button (`aria-haspopup="menu"`, `aria-expanded`) whose menu offers Mute sound / Turn sound on and Talk to an agent, with arrow-key navigation, Escape, and outside-click dismissal. It must never be a decorative, non-interactive glyph. While the panel is open the launcher is hidden and the panel's bottom aligns with where the launcher sat (desktop) or the safe-area bottom (mobile).

The public widget header stacks only teammates whose workspace availability schedule is active at the current time. Once a teammate joins, that person's photo and name replace generic team presence in the joined state, and their outbound messages use their photo. Smart Bot messages continue to use the configured company/launcher mark. When a teammate has no photo, render their initials rather than the company logo so human and automated identities remain distinguishable.
