# Smart Bot 2.0 — complete plan

- **Owner:** WisperBot product (approved 2026-10-02)
- **Applies to:** the Smart Bot that answers on the website widget, customer SDK, WhatsApp, Messenger, Instagram, Telegram and email; the playground; the public AI API (`/ai/chatbots/{id}/chat`); and automation AI steps.
- **Builds on:** Cerqle's Smart Bot 2.0, a sister codebase. Phases 0–1 are live there and accepted on its own bot (eval run #8: 97.3% of answerable questions answered, 100% of unanswerable ones declined, 0 invented figures, p50 3.4 s). Phase 2 (live data) is in progress there. This plan ports what is proven and keeps WisperBot's own strengths.
- **Authoritative spec:** [`KNOWLEDGE_BASE_SMART_BOT.md`](KNOWLEDGE_BASE_SMART_BOT.md) describes how the bot works today; this document is the plan.
- **This document is the single plan.** Update the status table and checklists in the same commit as the work, together with the spec, [`CHANGELOG.md`](CHANGELOG.md) and [`HANDOFF.md`](HANDOFF.md), as `AGENTS.md` requires.

---

## 1. Status at a glance

| Phase | What it delivers | Effort | Status |
|---|---|---|---|
| **0** Measure and quick fixes | Every turn records why it ended; `ai:smart-bot-report`; reply budget and cut-off retry; reasoning kept low; all system messages kept; guidance retry; figure check; no silent turns | 3–5 days | ◐ **Code done** 2026-10-02 in PR #181 (`dev` → `main`, awaiting owner approval to merge and deploy). Then: 7-day baseline, threshold decision, 30-question check |
| **1** Smarter answer engine (engine v2) | Answer ladder, Strict/Balanced/Flexible, company brief, self-check, unanswered questions, 👍/👎, quality test set, native channel buttons | 3–4 weeks | ◐ **In progress.** Increment 1 (engine v2 core: metered turns, planner, prompt, reply contract, validator, support check, second look, `ai:engine`) live in v1.3.103, off by default. Test set (1.6) live in v1.3.104. Answer controls (1.4) live in v1.3.105. Company brief (1.3) live in v1.3.106. Unanswered questions and feedback (1.5) live in v1.3.107. Native channel buttons (1.7) live in v1.3.110, full-context mode in v1.3.111. Burst batching built 2026-10-03. Next: the v1-vs-v2 canary |
| **2** Live data (tools) | Store products, price sheets, the client's own API, opening hours; "as of" times; 1 or 2 credits per answer | 3–4 weeks | ☐ Not started |
| **3** Knowledge ingestion and retrieval | Clean page extraction (tables, JS sites), scheduled refresh, keyword + meaning search | 4–6 weeks | ☐ Not started |
| **4** Procedures and memory | Plain-language playbooks, customer memory, handover summaries | 3–4 weeks | ☐ Not started |
| **5** Quality at scale | AI performance page, nightly quality gate, alerts | continuous | ☐ Not started; builds on Phase 1's test set |

Next: the Phase 0 baseline (about 2026-10-09) and the engine v2 canary on WisperBot's own bot. Phases 0–2 (about 7–9 weeks) fix what clients notice; Phases 3–5 make it scale.

---

## 2. Why

**Problem.**
- The bot falls back ("I could not find verified information…") far too often.
- It cannot reason about the company beyond a literal match in the knowledge base.
- It cannot give real-time prices.

**Goal.** One agentic answer engine that:
1. answers from the client's knowledge;
2. when the knowledge does not cover a question, gives **helpful, company-aware guidance** without inventing facts;
3. **looks up live data** (store products, a price sheet, the client's own API, opening hours) when the question needs it;
4. **checks itself** before sending, and **measures every turn**.

**How:**
- **One agent, not a swarm.** Cheap gates first, a model only when needed, tools only when the question needs live data.
- **Port Cerqle's engine instead of inventing a new one.** Most components copy over with renamed namespaces and config keys.

### 2.1 What we found (code review, 2026-10-02)

The causes below are ranked by how many fallbacks they probably cause. They come from reading the code, not from measurements. Phase 0 measures them before anything else changes.

**Note on production flags:**
- `.env.example` and the docs keep these off: `SMART_BOT_BUSINESS_AWARE_ROUTING`, `KB_HYBRID_RETRIEVAL_ENABLED`, `KB_GUARDED_PUBLISHING` and `KB_LIVE_PRODUCT_FACTS_ENABLED`.
- If production matches, every answer goes through the oldest path, `ChatbotRunner::retrieveContext()`.

| # | Cause | Where |
|---|---|---|
| 1 | **The retrieval cut-off is too strict.** A passage needs `0.78·cosine + 0.22·word overlap ≥ 0.60`. With no shared words that means cosine ≥ 0.77, while `text-embedding-3-small` scores truly relevant passages around 0.35–0.6. When nothing passes, the bot sends the fallback **without calling the model**. | `ChatbotRunner::retrieveContext()`, gate in `run()`; `KB_RETRIEVAL_MATCH_THRESHOLD` |
| 2 | **Every bot with a knowledge base is knowledge-only by default.** `answer_scope` defaults to `business_only`, and with business-aware routing off that behaves like Strict. There is no "helpful guidance" step. | `restrictsToKnowledgeBase()`; `ai_chatbots` defaults |
| 3 | **The prompt invites refusals, and refusals become fallbacks.** Strict wording tells the model to return an empty, ungrounded reply when unsure. An empty reply is never retried, so it turns into the fallback. | `systemPrompt()`, `validChatResponse()`, `LlmGateway` `retry_rejected` |
| 4 | **The reply budget is 320 tokens.** Seventy words of Bangla plus suggested replies can exceed it. A cut-off JSON reply fails to parse and becomes a fallback. `gpt-5*` and Gemini thinking tokens use the same budget. `finish_reason` and `reasoning_effort` are never handled. | `ChatbotRunner` LLM call; `OpenAiProvider`, `GeminiProvider` |
| 5 | **Anthropic and Gemini drop the main instructions.** Each keeps only the last system message, and `boundedHistory()` adds a second one, so in longer chats the rules and knowledge are replaced by the history summary. | `AnthropicProvider`, `GeminiProvider`, `boundedHistory()` |
| 6 | **The figure check rejects legitimate answers.** Any number of 2+ digits, or any number with a unit or currency, not written the same way in the evidence fails the reply (for example "24/7", totals, converted units). | `hasUnsupportedFigures()` |
| 7 | **Short follow-ups search with the bot's own fallback text.** The last 2 turns are added to the query, including the fallback sentence. | `retrievalQuestion()` |
| 8 | **Pages index poorly.** Tables are flattened; line breaks and lists are lost when chunks are rejoined; menus and headers stay in; JavaScript-rendered sites extract nothing; there is no scheduled re-crawl. | `KnowledgeSourceExtractor`, `IndexDocumentJob::chunk()` |
| 9 | **Real-time prices are effectively impossible.** Live product facts are off globally and per bot, read only Schema.org product markup, and the store-record path never matches a Shopify custom domain or any WooCommerce store (no currency). The router sends price questions to the fallback when research is off. | `LiveProductAnswerService`, `BusinessAwareTurnRouter` |
| 10 | **Fallbacks are invisible.** On the default path they are not recorded, so the rate cannot be measured. The channel reply job can drop a reply silently on some errors. | `recordDiagnostic()`, `ProcessChannelAiReplyJob` |

**Already in place and worth keeping:**
- **Credits and providers:** `LlmGateway` (credit reserve, settle and refund, idempotency, BYOK fallback), `AiCreditService`, and the providers including Qwen.
- **Retrieval:** `EmbeddingStore` (Qdrant or MySQL, index generations) and `KnowledgeRetrievalService` (multi-query, translation, lexical search).
- **Knowledge workflow:** guarded revisions, the FAQ and answer cache, and the knowledge gaps table.
- **Live data and research:** `LiveProductAnswerService` with its catalog refresher, and `TrustedKnowledgeResearchService`, approved-domain research that Cerqle does not have.
- **Safe fetching:** `KnowledgeUrlGuard` and `KnowledgeSourceUrlResolver` (SSRF-safe fetching).
- **Conversation features:** quick replies (`ChatReplyOptions`), video answers, starter questions, and `orderSummary()`.

Status of each cause:

| # | Fixed in |
|---|---|
| 1 | Phase 0, after the baseline (the cut-off is `KB_RETRIEVAL_MATCH_THRESHOLD`) |
| 2 | Phase 0 in part (guidance retry); Phase 1 (answer controls) |
| 3 | Phase 0 (guidance retry); Phase 1 (answer ladder) |
| 4, 5, 6, 7, 10 | ✅ Phase 0 code (PR #181) |
| 8 | Phase 3 |
| 9 | Phase 2 |

### 2.2 How the best products do it

| Product | What we take |
|---|---|
| **Intercom Fin** | Refine the question → retrieve → rerank → generate → validate. *Procedures* (plain-language SOPs), *data connectors* to client APIs, simulations for testing. |
| **Zendesk AI agents** | Generative procedures and approved API actions. |
| **Zoho Desk Zia** | Answers only from articles, FAQs and pages (our Strict mode). |
| **Crisp Hugo** | Q&A snippets, and tools whose descriptions say when to use them. |
| **Chatbase** | Custom API actions and Shopify actions. |
| **Anthropic, "contextual retrieval"** | Chunk context + keyword search + reranking cut retrieval failures by up to 67%. |
| **The "multi-agent RAG" note** | A planner plus specialist agents helps only when a question needs several sources at once, at about 15× the tokens. A single retriever plus a reranker answers about 90% of questions. **We build one agent** with a planner, good retrieval and tools; specialist agents only if the data shows they are needed. |

---

## 3. Owner decisions

| Date | Topic | Decision |
|---|---|---|
| 2026-10-02 | Plan | **Approved** as proposed; start Phase 0. |
| 2026-10-02 | Default answer freedom | **Balanced** for new and existing bots once engine v2 is on: knowledge first, then helpful company-aware guidance; never invent prices, policies, dates, stock or links. Per bot: Strict / Balanced / Flexible. |
| 2026-10-02 | Credits | **1** credit per answer, **2** with live data; fallbacks, clarifying questions and free gates cost 0; **BYOK free**. One ledger row per turn. |
| 2026-10-02 | First live-data sources | Store products → price sheet → client API → opening hours. Store and sheet need no technical setup; the API is for advanced clients. |
| 2026-10-02 | Account and order questions | Keep the last-3-orders context; **no** order-lookup tool yet; a customer asking about their own order goes to a person. |
| 2026-10-02 | When the AI cannot answer | Provider failure or empty reply → holding reply in the customer's script **and** handover to a person (shipped in Phase 0). |
| 2026-10-02 | Rollout | WisperBot's own bot first, then 3–5 pilot clients, then all bots. |
| 2026-10-02 | Cerqle code | Port with attribution; keep the two codebases' shared parts aligned where practical. |

---

## 4. Principles

1. **One agent, not a swarm.** Gates that cost nothing first, the model only when needed, tools only for live data.
2. **Never invent business facts.** Prices, fees, dates, stock, policies, links and contact details must come from the knowledge, live data, the approved company brief or the conversation, and are checked mechanically.
3. **Helpful beats silent.** When the facts are missing, give useful guidance and say how to get the exact detail, instead of a dead-end fallback.
4. **A client pays for answers, not attempts.** One charge per answered turn; failed turns are refunded with a readable reason.
5. **Measure every turn.** Every answer records why it ended. Every change is compared before and after.
6. **Tenancy is absolute.** Every query, tool call, cache key and vector filter is scoped by `workspace_id`; the model never supplies ids.
7. **Ship safely.** Bug fixes ship directly. Changes that alter answers for every client ship behind a flag, on WisperBot's own bot first, then bot by bot.

---

## 5. Target design

### 5.1 One answer turn

```
Inbound message
 → AutoReplyListener: human-handled skip, starter questions, keyword rules, handover phrases
 → reply job on the `ai` queue (burst batching: a customer's quick messages answered together)
 → Answer engine v2
    1. Free gates: reactions, exact FAQ match, greetings and thanks, "talk to a person", account/order → person
    2. Open one metered turn (one credit reservation for the whole answer)
    3. Planner (only for follow-ups or vague questions): standalone question, search queries, translation
    4. Retrieval (meaning + keyword, fused) — or the whole knowledge when it is small
       ∥ tools when the question needs live data (prices, stock, hours, client API)
    5. Generate along the answer ladder (5.2)
    6. Self-check: figures, links and dates must appear in the evidence; a small "support check" confirms the
       knowledge actually says what the reply claims → one rewrite, otherwise a refunded fallback
    7. Finish (one charge) or abort (refund with reason)
 → format per channel (WhatsApp buttons, Messenger quick replies, length limits) → send
 → diagnostics row: reason, passages and scores, tools, model, finish reason, cost
```

### 5.2 The answer ladder (default: Balanced)

1. The knowledge or live data answers it → **answer**.
2. It answers part of it → answer that part and **say what is not confirmed**.
3. It does not cover it, but the question is about the company or its field → **helpful guidance** from the company brief and general knowledge. No prices, fees, dates, stock, delivery times, policies, guarantees, contact details or links unless they are in the evidence. Say how to get the exact detail.
4. Too vague → **one** short question (never twice in a row).
5. About the customer's own account or order → **a person**.
6. Otherwise → **offer a person**.

How each mode changes this:
- **Strict** (`verified_only`) stops at step 2.
- **Balanced** (`business_only`) is the full ladder.
- **Flexible** (`general`) also answers questions unrelated to the business.

The existing `answer_scope` values map directly, so no client setting changes meaning.

### 5.3 Live data (tools)

The model asks for a lookup inside its reply (`tool_calls`). The server runs at most 3 lookups in one round, then the model answers with the data. Lookups are read-only, scoped to the workspace, cached, rate-limited and audited.

| Tool | Source | Answers |
|---|---|---|
| `store_products` | Synced Shopify / WooCommerce / BigCommerce products (`ecommerce_products`), plus today's product-page facts | Price, sale price, stock, variants, with the time it was checked |
| `price_list` | A spreadsheet the client uploads or a Google Sheet (refreshed hourly) | Prices that live in a sheet: plans, routes, packages, services |
| `data_connector` | The client's own API: a fixed HTTPS address, allow-listed fields, test console | Anything the client exposes: rates, availability, booking slots |
| `business_hours` | Widget working hours and timezone | "Are you open now?", "When do you open?" |
| `company_site_research` | Today's approved-domain research (`TrustedKnowledgeResearchService`) | Facts on the company's own site that are not indexed yet |

Replies that use live data show **"as of HH:MM"**. When a lookup fails, the bot says it could not confirm the detail right now and offers a person; it never falls back to an old price.

### 5.4 Retrieval (Phase 3)

```
search queries (rewrite + translation)
 → meaning: Qdrant, filtered by workspace and knowledge base (MySQL fallback for small knowledge bases)
 → keyword: MySQL FULLTEXT (ngram for CJK)
 → fusion (RRF) → [reranker only if evals show ordering is the problem] → small-to-big expansion
Small knowledge (≤ ~20k tokens): skip search, send it whole (prompt caching keeps the cost low)
```

---

## 6. Phases and tasks

Legend: ☐ to do · ☑ done · ◐ in progress · ↻ port from Cerqle (path in Cerqle's `app/Modules/AI/`) · ✚ WisperBot-specific.

### Phase 0 — measure and quick fixes ◐ (code done 2026-10-02, PR #181; baseline pending)

**Measure first**
- ☑ (2026-10-02) Record a diagnostics row on **every** path, including the old-path fallback (`ai_kb_retrieval_diagnostics`, reason code, passages, scores, model, finish reason).
- ☑ (2026-10-02) `php artisan ai:smart-bot-report [--days=14] [--workspace=] [--json]`: answer, fallback, clarification and handover rates, by reason. Written for WisperBot's tables as `app/Console/Commands/SmartBotReportCommand.php`, after Cerqle's `Console/SmartBotReport.php`.
- ☐ Read the production flag values and take a 7-day baseline before changing behaviour. After PR #181 is deployed: `php artisan ai:smart-bot-report --days=7` inside the production `app` container (the report prints the flag values too).

**Fix the fallback bugs** (each with a test)
- ☐ Recalibrate the retrieval cut-off (for example 0.60 → 0.45, clarification 0.30), confirmed against the baseline. Cerqle lowered its cut-off the same way (0.72 → 0.45), and the four test questions that had fallen back were then answered.
- ☑ (2026-10-02) Reply budget at least 500 tokens (800 for detailed); handle `finish_reason` and retry an empty or cut-off reply once with double budget in the same reservation; `reasoning_effort: minimal` for `gpt-5*`/o-series; thinking budget for Gemini.
- ☑ (2026-10-02) Anthropic and Gemini keep **every** system block (merge them instead of keeping the last).
- ☑ (2026-10-02, Balanced = answer scope Business only until Phase 1 adds the setting) An empty, ungrounded reply on a Balanced bot gets one retry with the guidance step, instead of an instant fallback.
- ☑ (2026-10-02) The figure check accepts the same value written differently (digits in any script, "24/7", currency symbol versus code).
- ☑ (2026-10-02) Follow-up queries never include the bot's own fallback text.
- ☑ (2026-10-02, website chat too) The channel reply job never drops a reply silently: a provider failure sends a holding reply in the customer's language and hands over.

**Shipped 2026-10-02 in PR #181** (not yet merged): migration `2026_10_02_000100_add_turn_reasons_to_ai_kb_retrieval_diagnostics`; new env `SMART_BOT_REPLY_MAX_TOKENS` (600), `SMART_BOT_GUIDANCE_RETRY` (on). Details in [`KNOWLEDGE_BASE_SMART_BOT.md`](KNOWLEDGE_BASE_SMART_BOT.md#fewer-silent-and-needless-fallbacks-2026-10-02).

**Still to do in Phase 0:**
- ☐ 7-day baseline report after deploy.
- ☐ Threshold decision from the baseline.
- ☐ A hand-written list of 30 questions on WisperBot's own bot (answerable and unanswerable), asked before and after.

**Acceptance:** fallback rate measured before and after; no increase in invented figures on the 30 questions (Phase 1 builds the full test set).

### Phase 1 — smarter answer engine ◐ (3–4 weeks; increment 1 built 2026-10-02)

**1.1 Metered turns**
- ☑ (2026-10-02) `LlmTurn` (`Services/Llm/LlmTurn.php`): one reservation per answer; `step()` per model call, each an `ai_runs` row; `finish()` charges once and stores the answer for replay; `abort()` refunds. Built on WisperBot's `AiCreditLedger` through `LlmGateway::beginTurn()` / `runStep()`.
- ✚ Align the provider options (`json_schema` → `response_schema`, add `cache_prefix` for Anthropic) without breaking BYOK or Qwen.

**1.2 Engine v2** (flag `SMART_BOT_ENGINE_V2` + per-bot `ai_chatbots.engine = v1|v2`, switched with `php artisan ai:engine {bot} v2`)
- ☑ (2026-10-02) `PromptBuilder`: the answer ladder, Strict/Balanced/Flexible, reply length; static system message first, passages per turn. Keeps WisperBot's reply rules (language and script, dynamic choices, scripted flows, exact wording, no video links).
- ☑ (2026-10-02) `ReplyContractV2`: `reply`, `answer_kind` (answer / partial / guidance / clarification / handoff), `used_sources`, `evidence` quotes, `quick_replies`, `show_video`, `language`. The widget, SDK and API `response_mode` values stay as they are.
- ☑ (2026-10-02) `QueryPlanner`: only for follow-ups and after a clarifying question; planned queries skip translation.
- ☑ (2026-10-02) `AnswerValidator` + `SupportCheck`: figures always enforced (`FigureCheck`, shared with v1); links, emails, phones and dates in shadow mode; a "second look" with rewritten queries when the first answer is partial. ☐ Plan and product names (`KnowledgeLexicon`) later.
- ☑ (2026-10-02) Engine v2 is a trait of `ChatbotRunner` (`AnswersWithEngineV2`), like Cerqle's, after all: it needs the runner's retrieval, video, order details, fallbacks and diagnostics. It runs after v1's free gates; greetings are free; account questions without order details go to a person; a bare question back and an offer of a person are refunded.
- ☑ (2026-10-02) `ai_chatbots.engine` + `reply_length`, `php artisan ai:engine {bot} v2 | --list`, flag `SMART_BOT_ENGINE_V2`; diagnostics `engine` and `trace`.
- ☐ Canary on WisperBot's own bot (production), then pilot bots.
- ☑ (2026-10-03) Full-context mode: live knowledge up to `SMART_BOT_FULL_CONTEXT_MAX_TOKENS` (20,000) is sent whole in the cacheable prompt part; no planner or second look; Strict asks the model; videos only from search results.
- ☑ (2026-10-03) ↻ Burst batching, designed on the existing debounced channel reply job: `MessageBurst` folds earlier unanswered text messages (since the last outbound reply, 90 s window, 5 max, small talk left out) into the latest one's question; one reply, one charge. Email and the website chat are not batched.

**1.3 Company brief**
- ☑ (2026-10-03) ✚ Designed for WisperBot: a "Company brief" card beside the existing business profile on the Knowledge Base page (`CompanyBriefService`, `DraftCompanyBriefJob`, `CompanyBriefCard`). Drafted from up to 8 live sources, each sentence with its source; sentences with figures, links or emails not in their source start unticked. 2 credits (`kb_company_brief`), refunded when nothing usable comes back. Only the approved brief is used, and it stays in use while a new draft is reviewed.
- ☑ (2026-10-03) Used by both engines: v1's profile block and business-guidance profile, v2's cacheable prompt and its checks. Brand, customers and purpose stay the required profile; the brief adds to them.

**1.4 Answer controls** (bot page)
- ☑ (2026-10-03) ✚ Designed for WisperBot's bot page: the existing answer scope cards (Business only / Verified sources only / General assistant) are the modes, so no second control; "Staged rollout" shows only when the scope has no effect; a "New answer engine" badge marks v2 bots.
- ☑ (2026-10-03) Reply length (Short ~40 / Standard ~70 / Detailed ~140 words) next to Tone, read by **both** engines; Standard is the old fixed length, so existing bots are unchanged.

**1.5 Unanswered questions and feedback**
- ☑ (2026-10-03) ✚ Unanswered questions on the **Knowledge Base page** (where WisperBot clients write knowledge), most asked first, with Write answer (one "Answers to customer questions" FAQ source per Knowledge Base) and Dismiss. Built on `ai_kb_knowledge_gaps`, now recorded in every publishing mode from the diagnostics reason, scrubbed of personal details, never from the playground or test runs.
- ☑ (2026-10-03) "Why this answer" (staff-only `payload.ai_review`), 👍/👎 (`ai_answer_feedback`, one per reply) and Improve under bot replies in the inbox and the mobile API; feedback in `ai:smart-bot-report`.

**1.6 Quality test set**
- ☑ (2026-10-03) `ai:eval:synthesize {bot}`, `ai:eval {bot} [--judge] [--byok]`, `ai:eval:cases {bot}`, `EvalScorer`: answered %, declined %, invented figures, p50. Platform-billed through `LlmGateway::evaluating()` / `platformChat()`, never charged to the client, and no diagnostics, gaps or cached answers.
- ☑ (2026-10-03) ✚ The Knowledge Base tester's questions (`ai_kb_test_cases`) are part of the set (`source = kb_test`).
- ☐ First runs on WisperBot's own bot: v1 baseline, then v2.

**1.7 Channels**
- ☑ (2026-10-03) ✚ Native WhatsApp reply buttons and Messenger/Instagram quick replies for bot reply choices (`NativeReplyChoices`, used by the existing channel drivers; the stored message stays `text`). Numbered text when they do not fit or are refused. WhatsApp button taps are now answered. WhatsApp lists (4–10 choices) are not needed: replies carry at most 3 choices.
- ☐ Per-channel formatting (Markdown removal for chat channels) beyond today's length limits.

**Acceptance** (50–100 test questions per pilot bot; Cerqle's bar):
- ≥ 85% of answerable questions answered;
- ≥ 90% of unanswerable questions declined correctly;
- **zero** invented figures;
- fallbacks and handover offers −40% against the Phase 0 baseline;
- p50 ≤ 5 s.

### Phase 2 — live data ☐ (3–4 weeks)

**2.1 Tool framework**
- ↻ `AgentTool`, `ToolContext`, `ToolResult`, `ToolRegistry` (max 3 calls), `ToolExecutor`, `ArgsValidator`, `PersonalData`, `TurnTools`; the `ai_tool_calls` audit table (masked arguments, latency, cache hit).

**2.2 Tools**
- ✚ `store_products` on `ecommerce_products`. Fix today's gaps:
  - store variants;
  - store currency for every platform (WooCommerce today has none);
  - match on the store's real domain, so Shopify custom domains work;
  - a scheduled product resync next to the webhooks.
- ✚ `LiveProductAnswerService` becomes this tool's product-page source instead of a separate path. Its fixed replies stay as the zero-credit fast path for a clear single-product price question.
- ↻ `price_list` (`PriceListTool`, `SheetReader`, `ai_price_lists`): CSV/Excel upload or a Google Sheet refreshed hourly (`ai:refresh-price-lists`). ✚ Reuse `GoogleClient::readSheetRange` where it fits.
- ↻ `data_connector` (`DataConnectorTool`, `DataConnectorClient`, `ai_data_connectors`) with a **Test** console and a "Recent lookups" log.
  - Security as in Cerqle: fixed HTTPS host, DNS pinned, no redirects, 256 KB cap, allow-listed fields, encrypted keys in headers only, 60/min per connector and 10/min per conversation, circuit breaker.
  - ✚ Build `PublicHttpClient` on `KnowledgeUrlGuard` / `KnowledgeSourceUrlResolver`. Do **not** copy the unguarded automation webhook client.
- ↻ `business_hours` on `chat_widgets.working_hours_json` and timezone.
- ✚ `company_site_research`: today's approved-domain research as a tool, so the model asks for it instead of a keyword trigger.

**2.3 Setup screen**
- ↻ A "Prices and live data" step on the bot page that asks **where the prices are kept**: a spreadsheet, the online store, the client's own system, or their website.

**2.4 Answers and credits**
- ☐ "As of HH:MM" on replies that used live data.
- ☐ The validator accepts figures only from the evidence or live data.
- ☐ Credits per the owner's decision (§3): reserve 2, settle 1 or 2 on one ledger row; BYOK free.
- ☐ Plan limits on connectors and price sheets.

**Acceptance:**
- ≥ 95% of price and availability questions with a working source answered from live data;
- p50 ≤ 8 s;
- tool error rate < 2%;
- prompt-injection tests pass;
- zero account or order lookups through tools.

### Phase 3 — knowledge ingestion and retrieval ☐ (4–6 weeks)

- ☐ Main-content extraction: remove nav, footer and cookie banners; keep tables as Markdown; title, description, language, canonical URL.
- ☐ Heading- and table-aware chunking that keeps line breaks, with a breadcrumb per chunk (contextual retrieval). Reuse embeddings when content is unchanged.
- ☐ Rendering for JavaScript sites (headless browser, per-workspace quota).
- ☐ Sitemap discovery with include/exclude filters; scheduled `ai:refresh-knowledge` (conditional GET, remove a page after two misses); per-plan crawl budget.
- ☐ Hybrid search: Qdrant filtered by `workspace_id` + MySQL FULLTEXT, fused with RRF; small-to-big expansion; query rewrite and cross-language search. A reranker only if evals show ordering is the bottleneck.
- ☐ Tenancy: `workspace_id` on every chunk and vector; Qdrant data deleted with the workspace.

**Acceptance:**
- recall@10 ≥ 0.9 on real and test questions;
- re-indexing one page touches only that page;
- ≥ 90% of unchanged pages skip re-embedding;
- tenancy tests pass.

### Phase 4 — procedures and memory ☐ (3–4 weeks)

- ☐ **Procedures:** "when to use" plus plain-language steps and allowed tools, chosen by the planner (like Intercom Procedures). Example: "If someone asks for a refund, ask for the order date, then…".
- ☐ **Memory:** an allow-listed contact profile, summaries of past resolved conversations, a rolling summary beyond 20 turns; deleted with the contact.
- ☐ **Handover summary** for agents.
- ☐ Voice-note transcription and image understanding behind a flag.

### Phase 5 — quality at scale ☐ (continuous)

- ☐ **AI Performance page:**
  - answer, guidance, fallback and handover rates;
  - 👍/👎, latency and cost per answer;
  - top unanswered questions;
  - tool success.
- ☐ Widget 👍/👎; scripted multi-turn simulations.
- ☐ Nightly `ai:eval` on canary bots as a release gate for prompt and model changes.
- ☐ Alerts on spikes in fallbacks, provider errors, tool failures and cost.

### Later options (not scheduled)

- MCP connector (Crisp style).
- Write actions with customer confirmation (booking, order changes).
- Open-web search (today's rule is approved domains only).
- Multi-agent fan-out — only if more than 10% of failed test questions need 3+ sources.

---

## 7. Platform needs checklist

| Need | Status |
|---|---|
| Widget/SDK/API keep their `response_mode` values; new answer kinds are additive | ☐ Phase 1 (Phase 0 adds `response_mode=handoff` only on holding replies) |
| SDK changes are the app team's to build and release; server tests never claim SDK delivery | Standing rule (`AGENTS.md`) |
| No silent turns on channels and website chat | ✅ Phase 0 code |
| BYOK providers (OpenAI, Anthropic, Gemini, Qwen, DeepSeek): budgets, finish reasons, all system messages | ✅ Phase 0 code; contract tests per provider in Phase 1 |
| Measurement: every turn's reason; diagnostics pruned after 90 days | ✅ Phase 0 code |
| WhatsApp buttons/lists, Messenger/Instagram quick replies, per-channel length | ☐ Phase 1.7 |
| Handover in any language; "yes" to the bot's offer hands over | ☐ Phase 1 |
| Plan limits on connectors, price sheets, crawl pages | ☐ Phase 2 and 3 |
| Privacy: gaps scrubbed of emails and phone numbers; Qdrant data deleted with the workspace | ☐ Phase 1.5 and 3 |
| Bangla and other Indic scripts in search and figure checks | ◐ Figure check in Phase 0; tokenising in Phase 3 |

---

## 8. Cost, latency and credits

| Path | Model calls | Est. cost per reply | p50 |
|---|---|---|---|
| Free gate (exact FAQ, greeting, handover, fixed product price) | 0 | $0 | < 1 s |
| Knowledge answer | 1–2 (+ support check) | ~$0.002–0.004 | 3–5 s |
| Follow-up with planner | 2–3 | ~$0.003–0.005 | 4–6 s |
| Live-data answer | 2–3 + the source | ~$0.003–0.006 | 5–8 s |
| Worst case (planner, tool, rewrite) | 4–5 | ~$0.008 | 25 s budget |

A credit is worth about $0.01–0.02 depending on the plan, so margins stay positive at **1 credit per answer and 2 with live data**.

---

## 9. Operating notes

**Configuration** (`config/chatbot.php`, `config/knowledge_base.php`, `config/ai_credits.php`):
- `chatbot.reply_max_tokens` (`SMART_BOT_REPLY_MAX_TOKENS`, default 600, kept between 500 and 2000);
- `chatbot.guidance_retry_enabled` (`SMART_BOT_GUIDANCE_RETRY`, default on);
- `knowledge_base.retrieval_match_threshold` (`KB_RETRIEVAL_MATCH_THRESHOLD`, default 0.60; the managed policy allows 0.45–0.85) and `clarification_min_threshold` (`KB_CLARIFICATION_MIN_THRESHOLD`, 0.38);
- managed models: `AI_MANAGED_ROUTINE_MODEL` / `AI_MANAGED_COMPLEX_MODEL` (default `gpt-4o-mini`).

**Flags** (env, default off): `SMART_BOT_BUSINESS_AWARE_ROUTING`, `KB_HYBRID_RETRIEVAL_ENABLED`, `KB_GUARDED_PUBLISHING`, `KB_LIVE_PRODUCT_FACTS_ENABLED`. Planned: `SMART_BOT_ENGINE_V2`, `SMART_BOT_TOOLS`, `SMART_BOT_DATA_CONNECTORS`.

**Measure:** `php artisan ai:smart-bot-report --days=14 [--workspace=ID] [--json]` before and after every phase. Every turn's `reason_code` is in `ai_kb_retrieval_diagnostics`.

**Rollback levers:**
- reply budget, guidance retry and cut-off by environment variable (no redeploy beyond a config refresh);
- engine v2 per bot (Phase 1);
- each tool and connector behind its flag (Phase 2).

---

## 10. Process for every phase

1. **Branch:** feature branch → fast-forward `dev` → PR `dev` → `main`. Merging that PR deploys production (`.github/workflows/deploy-production.yml`), so it needs the owner's approval each time.
2. **Docs in the same commit:** this plan's status table and checklists, `KNOWLEDGE_BASE_SMART_BOT.md`, `CHANGELOG.md`, `HANDOFF.md`.
3. **Gate:** `php artisan test`, `npm test -- --run`, `npm run build`, `./vendor/bin/pint --test`, no new PHPStan findings on changed files.
4. **Canary:** WisperBot's own bot first; re-ask the baseline questions; compare `ai:smart-bot-report` (and `ai:eval` from Phase 1) with the baseline; then pilot clients; then everyone. Every behaviour change ships behind a flag, off by default; bug fixes ship directly.

---

## 11. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Guidance answers invent specifics | Answer ladder, mechanical checks on figures, links and dates, support check, Strict mode |
| Latency grows with more model calls | Free gates, planner only for follow-ups, one tool round, full-context mode for small knowledge |
| Cost grows | One charge per turn with caps, cost per answer on the performance page, alerts |
| Prompt injection through pages or tool output | Evidence and tool data framed as data, never instructions; fixed connector hosts; allow-listed fields |
| Personal data exposure | No order or account tools; personal-data scan on tool arguments; redacted traces; retention limits |
| The port diverges from WisperBot's runner | Separate `AnswerEngineV2` class behind a per-bot switch; v1 untouched until v2 wins on the eval |
| BYOK provider differences | Per-provider budgets and contract tests (OpenAI, Anthropic, Gemini, Qwen) |

---

## 12. Key files

**WisperBot today:**
- `app/Modules/AI/Services/ChatbotRunner.php`
- `LlmGateway.php`, `AiCreditService.php`
- `KnowledgeRetrievalService.php`, `EmbeddingStore.php`
- `BusinessAwareTurnRouter.php`, `TrustedKnowledgeResearchService.php`
- `LiveProductAnswerService.php`, `LiveProductCatalogService.php`
- `app/Modules/AI/Jobs/IndexDocumentJob.php`
- `app/Listeners/AutoReplyListener.php`
- `app/Modules/Inbox/Jobs/ProcessChannelAiReplyJob.php`, `ProcessWebchatAiReplyJob.php`
- `app/Modules/Ecommerce/` (stores and products)

**Ported from Cerqle** (`cerqle-hub/app/Modules/AI/`):
- `Services/Llm/LlmTurn.php`
- `Services/Agent/` (`PromptBuilder`, `ReplyContractV2`, `QueryPlanner`, `AnswerValidator`, `SupportCheck`, `BusinessBriefGenerator`, `KnowledgeLexicon`)
- `Services/Agent/Tools/`
- `Services/PriceLists/`
- `Services/Smart/` (`NativeChoices`, `ChannelFormatter`)
- `Services/Eval/`
- `Console/SmartBotReport.php`
- `app/Services/PublicHttpClient.php`
- UI in `resources/js/Components/AI/`

**Cerqle references:** `docs/smart-bot-2-plan.md` and the decision records `docs/decisions/2026-10-01-smart-bot-*.md` and `2026-10-02-smart-bot-phase-2.md`.
