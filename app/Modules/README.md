# app/Modules/ — feature modules

Each module registers its own service provider, routes (`routes/web.php`, mounted with the client middleware stack in `bootstrap/app.php`), jobs, models and migrations (`database/migrations/`). Tests live under `tests/Feature/<Area>` and `tests/Unit`. Every query, job, webhook and broadcast must stay scoped to its workspace.

| Folder | What lives there | Read before changing |
|---|---|---|
| `Inbox/` | Omni Channel Inbox, website chat widget (`routes/widget.php`), labels, internal notes, email accounts (Email MasterBox), Telegram Business, eBay and Amazon setup; inbound processing and webchat/channel AI reply jobs | [PRODUCT_DECISIONS](../../docs/PRODUCT_DECISIONS.md), [CHAT_REPLY_OPTIONS](../../docs/CHAT_REPLY_OPTIONS.md) |
| `Whatsapp/` | WhatsApp Cloud API and Coexistence: embedded signup, setup, templates, auto-replies, webhook, connection health jobs | [INTEGRATIONS](../../docs/INTEGRATIONS.md) |
| `AI/` | AI providers (managed/BYOK), Knowledge Bases and indexing, Smart Bots (`AiChatbot`); answer engine v2 in `Services/Agent/`, answer-quality test set in `Services/Eval/` | [KNOWLEDGE_BASE_SMART_BOT](../../docs/KNOWLEDGE_BASE_SMART_BOT.md) |
| `Social/` | Social Media Automation: connected accounts, scheduled posts and publishing, comments, token refresh | [SOCIAL_COMMENTS](../../docs/SOCIAL_COMMENTS.md), [INTEGRATIONS § Meta](../../docs/INTEGRATIONS.md#meta) |
| `Automation/` | XYFlow automation builder and run execution | [PLAN.md](../../PLAN.md) |
| `Broadcasting/` | SMS and email campaigns, SMS providers and delivery webhooks, email servers, email tracking, email AI | [PLAN.md](../../PLAN.md) |
| `Ecommerce/` | Shopify / WooCommerce / BigCommerce stores: OAuth, products, orders, order context for chats, webhooks, abandoned carts | [INTEGRATIONS](../../docs/INTEGRATIONS.md) |
| `Integrations/` | Platform integration configuration (Super Admin credentials, stored encrypted) | [SECURITY](../../docs/SECURITY.md) |
| `Leads/` | Lead scraping job (no routes or controllers) | — |
| `Shared/` | Contacts and segments used across modules | [ARCHITECTURE.md](../../ARCHITECTURE.md) |

Queue names each module uses are listed in [ARCHITECTURE.md](../../ARCHITECTURE.md) and [docs/OPERATIONS.md § Queue workers](../../docs/OPERATIONS.md#queue-workers). A new module gets a row here in the same change.
