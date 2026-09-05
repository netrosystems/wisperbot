# WisperBot — UI/UX Design System & Strict Style Guidelines

This document defines the mandatory UI/UX standards, component specifications, color tokens, layout archetypes, and accessibility patterns for **WisperBot**. All frontend components, page views, and design modifications must strictly adhere to these guidelines.

---

## 1. Typography & Font Hierarchy

### 1.1 Font Families: Space Grotesk & Fraunces
- **Primary Body / UI Font**: Strictly uses **`Space Grotesk`** (`'Space Grotesk', sans-serif`) across all standard headings, navigation, data tables, modals, badges, and controls.
- **Editorial Display Serif**: Uses **`Fraunces`** (`'Fraunces', 'Georgia', serif`) exclusively for marketing landing page display headers.
- **Font Stack**: `['Space Grotesk', ...defaultTheme.fontFamily.sans]`.

### 1.2 Typographic Scale & Weights

| Element | Size | Weight | Line Height | Tracking | Tailwind Class |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Page Header Title** | 24px (1.5rem) | 700 (Bold) | 32px | -0.02em | `text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100` |
| **Section / Modal Title** | 18px (1.125rem) | 600 (Semibold) | 24px | -0.01em | `text-lg font-semibold text-neutral-900 dark:text-neutral-100` |
| **Card / Subheading Title** | 15px (0.9375rem) | 600 (Semibold) | 20px | 0 | `text-sm sm:text-base font-semibold text-neutral-800 dark:text-neutral-200` |
| **Body & Table Cell Text** | 13.5px (0.84375rem) | 400 (Regular) | 20px | 0 | `text-sm font-normal text-neutral-700 dark:text-neutral-300` |
| **Table Column Header** | 11px (0.6875rem) | 600 (Semibold) | 16px | 0.05em | `text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400` |
| **Badges / Micro-Labels** | 11px (0.6875rem) | 500 (Medium) | 14px | 0 | `text-xs font-medium` |

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
    "50": "#fff5ed",  "100": "#ffe8d4", "200": "#ffcda8",
    "300": "#ffab70", "400": "#ff8a45", "500": "#ff762e",
    "600": "#f05a12", "700": "#c74310", "800": "#9e3615",
    "900": "#7f2f14", "950": "#451507"
  },
  "accent": {
    "50": "#fffdeb",  "100": "#fffbc4", "200": "#fff78d",
    "300": "#ffe24a", "400": "#ffcf1f", "500": "#ffbf00",
    "600": "#e29400", "700": "#bb6c02", "800": "#985308"
  },
  "secondary": {
    "50": "#f6f5f4",  "100": "#e7e5e3", "500": "#6f6660",
    "800": "#2b2621", "900": "#1c1814", "950": "#14100c"
  },
  "coral": {
    "50": "#fff3f1",  "100": "#ffe4df", "500": "#f04e2e",
    "600": "#d8331a", "700": "#b32512", "900": "#7a1e16"
  }
}
```

### 2.2 The Brand Restraint Rule
> **IMPORTANT**:
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

### 4.2 Viewport-Pinned Layout (`InboxLayout`)
- **Used For**: Omni-Channel Inbox and Master Email Inbox.
- **Structure**: `h-screen` pinned column flexbox layout.
  - Left: 3-column navigation / conversation list.
  - Center: Scrollable real-time message stream with sticky bottom composer.
  - Right: Collapsible contact info and contextual CRM details panel.
  - Prevents outer browser scrollbars, maximizing agent efficiency.

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

| Semantic Status | Light Mode Classes | Dark Mode Classes | Use Cases |
| :--- | :--- | :--- | :--- |
| **Brand / Active** | `bg-brand-50 text-brand-700 border-brand-200` | `dark:bg-brand-900/30 dark:text-brand-300 dark:border-brand-700` | Active channels, Pro plan, Verified |
| **Success / Synced** | `bg-emerald-50 text-emerald-700 border-emerald-200` | `dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-700` | Delivered, Resolved, Connected, Paid |
| **Warning / Pending** | `bg-amber-50 text-amber-700 border-amber-200` | `dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-700` | Snoozed, Quota Warning, Pending OAuth |
| **Danger / Failed** | `bg-coral-50 text-coral-800 border-coral-200` | `dark:bg-coral-950/40 dark:text-coral-300 dark:border-coral-800` | Failed delivery, Expired token, Canceled |
| **Neutral / Default** | `bg-neutral-100 text-neutral-700 border-neutral-200` | `dark:bg-neutral-800 dark:text-neutral-300 dark:border-neutral-700` | Draft, Archived, Unassigned |

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

Conversation video resources use a responsive 16:9 dark media surface, a centered WisperBot Orange play control, a one-line title, and an always-visible “Open video” fallback. Third-party players are created only after user interaction. Cards must remain keyboard accessible and usable at a minimum 200px player viewport.

## Guided Knowledge Base workflow

The default client surface uses plain-language five-step progress: Define, Add sources, Review quality, Test answers, Publish. Ready uses success green, review uses amber, and blockers/failures use danger red; color is always paired with text and an icon. Technical vector/embedding details remain in advanced diagnostics. An empty Sources step presents three direct choices—Entire website, Specific web page, and Upload files—and hides management-only search, filtering, duplicate add buttons, and unavailable forward actions until a source exists. File guidance prioritizes PDF, DOCX, TXT, and Markdown and uses a bordered brand callout to explain that supported video URLs may become playable customer-chat guidance. Source dialogs explain appropriate usage and limits before selection, display extracted passages and stable findings, and keep factual corrections subject to explicit client approval. Dialogs must trap focus and support Escape/backdrop dismissal when migrated to the shared `Modal` primitive.

## Social Media Automation workspace

Social publishing uses one standard page with a compact connected-account strip above a scan-friendly posts list. Provider cards show no more than two identities until expanded; account reconnect/disconnect and post lifecycle actions use keyboard-accessible Headless UI menus. The page has one orange primary action, **Schedule Post**, while AI planning is secondary. When there are no accounts or posts, use compact explanatory rows rather than tall illustrated panels, avoid duplicate calls to action, and hide tabs and filters that have nothing to operate on. Post status tabs default to Upcoming once posts exist, and List/Calendar use a segmented view control rather than separate navigation destinations. Desktop post rows become compact mobile cards below the table breakpoint. New translation keys must include readable English fallbacks at critical navigation and page-heading boundaries, and locale-file changes must invalidate the server dictionary cache automatically.

## AI credit visibility

Client pages show a compact header control formatted as **remaining / monthly total** (for example, `88 / 100`). Its popover exposes used, processing, remaining, and reset date without internal action or provider-mode keys. The Subscription page uses a segmented usage bar and a scan-friendly action table generated from the authoritative backend credit catalog. Warning and exhausted states pair semantic color with text, retain the numeric counter on narrow screens, and provide visible keyboard focus.

The client header prioritizes operational controls and does not include global Search or a language selector. Locale selection remains available on public/authentication surfaces where language choice is part of entry into the product.
