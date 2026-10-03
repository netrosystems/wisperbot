# Map — which document answers which question

Read the authoritative document for your task domain before writing code, and update it in the same commit if your changes affect that domain.

## 🧭 Domain specification & documentation impact matrix

| Task / Change domain | Authoritative document | What it guarantees & when to update |
| :--- | :--- | :--- |
| **UI, Styling, Colors, Layouts & Components** | [`DESIGNSYSTEM.md`](../../DESIGNSYSTEM.md) | Pixel-perfect UI using Space Grotesk & Fraunces, exact WisperBot Orange (`#FF762E`) & Amber (`#FFBF00`) tokens, `.page` vs `.viewport-table-page` (InboxLayout 100vh) layouts, XYFlow canvas, and accessible Radix/Headless UI components. |
| **Backend, APIs, Modules, Queues & Tenancy** | [`ARCHITECTURE.md`](../../ARCHITECTURE.md) and [`docs/ARCHITECTURE.md`](../ARCHITECTURE.md) | Correct modular monolith patterns (`app/Modules/*`), strict `workspace_id` query scoping, queue assignments (`whatsapp`, `ai`, `social`, `automation`, `broadcast`), Reverb/Pusher WebSockets, and health probes. |
| **Features, Workflows, State Machines & Roadmap** | [`PLAN.md`](../../PLAN.md) | Complete business logic compliance across all 10 modules (Omni-Channel Inbox, Master Email Inbox, Widget with HMAC, WhatsApp Cloud API, AI Knowledge Bases, XYFlow Automations, Social Publishing, SMS Campaigns, Billing). |
| **Smart Bot questions, CTA choices, Widget & SDK parity** | [`docs/PRODUCT_DECISIONS.md`](../PRODUCT_DECISIONS.md) and [`docs/CHAT_REPLY_OPTIONS.md`](../CHAT_REPLY_OPTIONS.md) | Industry-independent conversational choices, KB-grounded follow-ups, safe shared payloads, generic rendering, and separate SDK release ownership. Read both before changing or diagnosing this existing feature. |
| **Knowledge Base indexing, retrieval, answer scope, research & grounding** | [`docs/KNOWLEDGE_BASE_SMART_BOT.md`](../KNOWLEDGE_BASE_SMART_BOT.md) | Business-aware routing, semantic retrieval, approved-source boundaries, atomic index generations, evidence requirements, credits, diagnostics, feature flags, and rollout gates. |
| **Smart Bot 2.0 roadmap, status & owner decisions** | [`docs/SMART_BOT_2_PLAN.md`](../SMART_BOT_2_PLAN.md) | Phases, what is live, owner decisions (Balanced default, credits, BYOK, release flow) and next steps. Update its status table and checklists in the same commit as Smart Bot work; behaviour itself is specified in `KNOWLEDGE_BASE_SMART_BOT.md`. |
| **External Integrations & OAuth Credentials** | [`docs/INTEGRATIONS.md`](../INTEGRATIONS.md) and provider guides | Correct Meta OAuth scopes, Instagram limitations, Google/Microsoft OAuth, Telegram, SMS Gateways, SP-API / eBay, and Qdrant vectors. |
| **Operations, Workers, Deployment & Crons** | [`docs/OPERATIONS.md`](../OPERATIONS.md) and/or [`DEPLOYMENT.md`](../../DEPLOYMENT.md) | Scheduler setup, worker commands, deployment checklist, Vite production bundle builds, and log diagnostics. Task-by-task routes: [OPERATIONS.md map](OPERATIONS.md). |
| **Security, Secrets & Threat Boundaries** | [`docs/SECURITY.md`](../SECURITY.md) | `Crypt::encryptString` secret storage, Sanctum bearer tokens, CSRF, webhook signatures, and widget HMAC secrets. |
| **Product Decisions & Terminology** | [`docs/PRODUCT_DECISIONS.md`](../PRODUCT_DECISIONS.md) | Enforces intentional product choices (Omni-Channel vs Master Email Inbox separation, social deletion capabilities). |
| **Known Issues & Technical Debt** | [`docs/KNOWN_ISSUES.md`](../KNOWN_ISSUES.md) | Active queue name quirks, external platform review states, and historical workarounds. |
| **Current Repository Status & Next Steps** | [`docs/HANDOFF.md`](../HANDOFF.md) | Baseline commit status, active development focus, and immediate checklist. |
| **User-Visible Releases & Milestones** | [`docs/CHANGELOG.md`](../CHANGELOG.md) | Documentation-level release notes for user-visible or operationally significant changes. |
| **Public website content, routes & motion** | [`docs/DESIGN.md`](../DESIGN.md) and [`docs/MARKETING_VISUAL_AUDIT.md`](../MARKETING_VISUAL_AUDIT.md) | Public-site content architecture, product/solution/channel pages, navigation, motion and imagery provenance. |
| **Social comments web/mobile API** | [`docs/SOCIAL_COMMENTS.md`](../SOCIAL_COMMENTS.md) | Comments adapters, API contract and review checklist. |

`docs/HANDOFF.md` and `docs/CHANGELOG.md` are dated snapshots and history: check them against the code before trusting them. The other documents describe durable behaviour.
