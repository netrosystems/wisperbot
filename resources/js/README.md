# resources/js/ — Inertia + React frontend

Pages are resolved by name from `Pages/` in `app.jsx`; controllers render them with `Inertia::render('<Folder>/<Page>')`. Frontend tests (Vitest) live in `__tests__/`. Build output goes to `public/build`, which is not in Git.

| Folder or file | What lives there | Read before changing |
|---|---|---|
| `Pages/Admin/` | Super Admin screens (clients, plans, gateways, integrations, settings) | [DESIGNSYSTEM.md](../../DESIGNSYSTEM.md) |
| `Pages/client/` | Client portal: dashboard, billing, subscription, pricing, team, settings, workspaces, API, webhooks, reports | [PLAN.md](../../PLAN.md) |
| `Pages/Inbox/`, `Pages/Chat/`, `Pages/Whatsapp/`, `Pages/AI/`, `Pages/Social/`, `Pages/Automation/`, `Pages/Broadcasting/`, `Pages/Ecommerce/`, `Pages/Contacts/` | Screens for the matching feature module | [app/Modules/README.md](../../app/Modules/README.md) |
| `Pages/marketing/`, `Components/marketing/` | Public website (home, pillar pages, pricing, blog, FAQ, contact); styles in `resources/css/marketing.css` | [docs/DESIGN.md](../../docs/DESIGN.md) |
| `Pages/Auth/`, `Pages/Install/`, `Pages/Profile/` | Sign-in and registration, installer wizard, profile | [SECURITY](../../docs/SECURITY.md) |
| `Layouts/` | `AdminLayout`, `ClientLayout`, `InboxLayout` (100vh), `LandingLayout`, `AuthLayout`, `InstallLayout` | [DESIGNSYSTEM.md](../../DESIGNSYSTEM.md) |
| `Components/ui/` | Shared primitives (Button, Input, Modal, Dropdown…); reuse before adding new ones | [DESIGNSYSTEM.md](../../DESIGNSYSTEM.md) |
| `Components/` (other) | Inbox, dashboard, charts, social, email editor and app-wide pieces (sidebar, topbar, command palette) | [DESIGNSYSTEM.md](../../DESIGNSYSTEM.md) |
| `locales/*.json`, `i18n.js` | UI translations. `TranslationSeeder` regenerates these files; commit them only when the change is intended. | — |
| `echo.js`, `push.js`, `bootstrap.js` | Realtime (Reverb/Pusher), web push, Axios setup | [ARCHITECTURE.md](../../ARCHITECTURE.md) |
| `hooks/`, `context/`, `lib/`, `Utils/` | Small shared hooks, theme context and helpers | — |

UI rule for this area: use the brand tokens, typography and layout archetypes in [DESIGNSYSTEM.md](../../DESIGNSYSTEM.md); do not introduce new colours or fonts.
