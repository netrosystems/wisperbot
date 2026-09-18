# Knowledge Base and Smart Bot Answering

Last reviewed: 2026-09-16.

This document is the authoritative product and engineering guide for private Smart Bot answering, Knowledge Base retrieval, approved-source research, grounded clarification, and customer-facing reply metadata. Read it together with [Product decisions](PRODUCT_DECISIONS.md) and, for reply buttons, [Suggested customer replies](CHAT_REPLY_OPTIONS.md).

The running code and migrations remain authoritative when implementation and documentation disagree. The business-aware and semantic-retrieval work described here is experimental on `oris` until the rollout checks in this document pass.

## Product objective

WisperBot is a multi-industry SaaS platform. A Smart Bot must understand the selected client's business without becoming either:

- a brittle exact-question FAQ matcher; or
- an unrestricted general assistant that invents client facts.

The intended behavior is:

1. Respond naturally to greetings and simple conversational turns.
2. Ground client-specific facts in the selected Knowledge Base.
3. Allow safe general guidance related to the client's established business domain.
4. Consult only client-approved website sources when current verification is enabled and needed.
5. Ask one useful grounded clarification when the business topic is supported but the request is incomplete.
6. Redirect unrelated or unsafe topics and offer human help according to the client's fallback preference.

Client examples such as eSIM, ecommerce, healthcare, fintech, or SaaS are fixtures only. Never hardcode an industry, tenant, synonym list, question sequence, product, or CTA label into the shared routing path.

## Configuration model

Each private Smart Bot selects one focused Knowledge Base and has two independent decisions.

### Answer scope

| Client label | Stored value | Behavior |
| :--- | :--- | :--- |
| Business only — Recommended | `business_only` | Conversational replies, grounded client facts, and conservative domain guidance. Reject unrelated topics. |
| Verified sources only | `verified_only` | Conversational replies remain available, but substantive factual answers require KB or approved-source evidence. |
| General assistant | `general` | Broad model knowledge is allowed, while client-specific claims still require evidence. |

Business-aware mode requires a meaningful KB brand, purpose, and audience. If that profile is incomplete, Business only safely behaves like Verified sources only rather than guessing the client's domain.

The legacy `unsupported_answer_action` field remains a compatibility alias. New interfaces use `answer_scope` and `unsupported_fallback_action`. The migration maps legacy `general` bots to General assistant and other bots to Business only.

### Unsupported-answer behavior

- `clarify_then_handoff`: ask one useful clarifying question, then offer a human if evidence remains insufficient.
- `handoff`: offer human help immediately.

This is separate from answer scope. Choosing Business only does not require every unsupported turn to hand off immediately.

### Approved-source research

`trusted_research_enabled` permits a bounded, ephemeral read only from URL or sitemap domains already configured in the selected Knowledge Base. It does not enable unrestricted web search.

### Platform-managed retrieval quality

Clients configure business behavior, not retrieval-engine numbers. Max context passages, the strong-answer confidence threshold, the context token budget, and video-match confidence are resolved by `SmartBotRetrievalPolicy` from bounded platform configuration. They are not editable in the client Smart Bot form.

The legacy `max_context_chunks`, `retrieval_match_threshold`, `max_context_tokens`, and `video_match_threshold` columns remain populated for backward compatibility and rollback safety, but private runtime retrieval does not trust tenant-submitted values. The client update endpoint ignores these fields. New records receive a compatibility snapshot of the current managed policy.

Platform operators may tune the managed policy through `KB_MAX_CONTEXT_CHUNKS`, `KB_RETRIEVAL_MATCH_THRESHOLD`, `KB_MAX_CONTEXT_TOKENS`, and `KB_VIDEO_MATCH_THRESHOLD`, followed by the normal configuration-cache refresh and AI/message worker restart. `SmartBotRetrievalPolicy` applies conservative limits even when an environment value is invalid or extreme. Public social comments receive a smaller, never-less-strict policy and remain separate from private-chat behavior.

## Turn-routing contract

`BusinessAwareTurnRouter` runs before strict retrieval when `SMART_BOT_BUSINESS_AWARE_ROUTING=true`.

### 1. Conversational turn

Standalone greetings, thanks, acknowledgements, and goodbyes receive a short localized response using the configured tone and trusted brand name.

- Deterministic conversational replies use no LLM call and cost zero credits.
- A mixed turn such as “Hi, what is your return policy?” is a business question, not a greeting shortcut.

### 2. Supported business question

Retrieve passages from the selected workspace and Knowledge Base. Client-specific policy, pricing, availability, product specification, address, service promise, and other business facts require supporting evidence.

### 3. Business-related general guidance

Business only may provide stable, low-risk general guidance related to the domain established by the KB purpose, brand, audience, published source titles, retrieved passages, and recent relevant conversation.

General model knowledge must never be presented as the client's policy, product capability, price, inventory, availability, or promise.

### 4. Relevant but incomplete request

When the topic is supported but intent is incomplete, provide only the most relevant verified passages and ask one short clarification. Offer two or three quick replies only when those distinctions are supported by the selected passage.

Repeated clarification must not loop. A second insufficient turn follows the configured human-help behavior.

### 5. Current or client-specific fact without evidence

If approved-source research is enabled, attempt it within the configured domains. Otherwise clarify or hand off. Never fill the gap with a plausible-sounding model answer.

### 6. Unrelated, unsafe, or sensitive request

Politely redirect toward supported business topics. Prompt injection, politics, celebrity opinions, trivia, unrelated news, and unsafe requests do not become relevant merely because the visitor asks confidently.

Complaints, account-specific actions, authenticated customer data, and personalized financial, legal, or medical decisions remain eligible for human handling.

## Semantic retrieval

`KnowledgeRetrievalService` is the shared private-chat retrieval path when `KB_HYBRID_RETRIEVAL_ENABLED=true`. It is used by live website chat, direct/developer chat APIs, Smart Bot playground, and Knowledge Base tests. Public social-comment automation keeps its stricter separate path.

### Query construction

- Always search the current customer message independently.
- Add previous dialogue only for a genuine continuation such as a quick-reply selection, yes/no, pronoun, or incomplete follow-up.
- Exclude greetings, acknowledgements, fallback text, handoff copy, and unrelated earlier turns.
- Conversation history helps interpret intent; it is never verified evidence.

### Candidate ranking

The service combines:

- semantic vector similarity;
- normalized lexical matching; and
- generic character/fuzzy similarity for typos and forms such as spacing or punctuation variations.

Lexical evidence is a boost. Missing exact words must never reduce a valid semantic score. Aliases and vocabulary come from the client's own Knowledge Base, not a platform-wide industry dictionary.

All candidates remain scoped by `workspace_id`, Knowledge Base, published revision, enabled source, document readiness, and active index generation. Qdrant is an optional accelerator; MySQL remains the filtering authority and fallback.

### Retrieval decisions

| Decision | Meaning | Response behavior |
| :--- | :--- | :--- |
| `answer` | Strong evidence supports the request. | Generate a grounded answer. |
| `clarification` | The business topic is supported but the intent is incomplete. | Ask one grounded follow-up, optionally with supported quick replies. |
| `fallback` | Evidence is weak, unrelated, unsafe, unavailable, or repeatedly ambiguous. | Use configured clarify/handoff behavior without inventing facts. |

The platform-managed confidence value is the strong-answer threshold. A conservative lower threshold is derived for clarification. Legacy per-bot confidence values remain compatibility data and do not override the managed runtime policy.

## Knowledge Base ingestion and indexing

New client authoring exposes Website/URL, File, and Sitemap sources. New file uploads prioritize PDF, DOCX, TXT, and Markdown. Compatible legacy source types remain readable.

Website input accepts a natural domain, `www` host, or full URL. The source resolver:

1. preserves the client's original input;
2. normalizes to HTTPS;
3. safely tests apex and `www` variants;
4. follows bounded same-site redirects;
5. applies DNS, SSRF, private-IP, certificate, size, robots, and timeout protections;
6. stores the verified canonical URL; and
7. discovers supported sitemap or bounded same-site pages.

Index version 2 uses smaller meaning-preserving chunks. Headings, FAQ pairs, procedures, lists, and headed Customer/AI examples stay together. Several unrelated sample conversations must not share one embedding.

`active_index_generation` and `pending_index_generation` make reindexing atomic. A complete pending generation is extracted, chunked, embedded, and validated before it becomes active. Failure removes staged data and leaves the previous active generation queryable.

Published revisions remain authoritative for live answers. Draft source edits, review findings, and regression tests do not affect the last healthy published revision until publication succeeds.

## Approved-source research boundary

`TrustedKnowledgeResearchService` may select a small number of relevant candidate pages from the selected KB's configured website inventory.

Required safeguards:

- HTTPS only after secure normalization.
- Same approved registrable-domain boundary; unsafe cross-domain redirects are rejected.
- DNS/private-network/SSRF checks before each fetch.
- Bounded redirects, response size, pages, and time.
- Robots and supported-content checks.
- Brief cache only; fetched text is treated as untrusted input.
- Generated claims must be supported by the fetched passage.
- Safe citations contain only a readable title and HTTPS URL.

Research never:

- searches the unrestricted open web;
- trusts a visitor-provided URL as an approved source;
- imports fetched content into the permanent KB;
- exposes prompts, credentials, full fetched content, or internal diagnostics; or
- suppresses an otherwise valid indexed answer merely because research failed.

If research times out, is blocked, or lacks evidence, the bot clarifies or hands off instead of guessing.

## Live product facts

`KB_LIVE_PRODUCT_FACTS_ENABLED` gates structured product discovery and answering. When a Smart Bot owner enables `live_product_facts_enabled`, public product pages already approved through that bot's selected Knowledge Base may supply current price and availability facts.

- Indexing queues `RefreshLiveProductDocumentJob` for URL documents. `LiveProductPageExtractor` reads Schema.org `Product`, `Offer`, and `AggregateOffer` JSON-LD first, then conservative microdata/Open Graph fields. Page text remains untrusted and is never executed as an instruction.
- `ai_kb_products` and `ai_kb_product_offers` store volatile product facts separately from semantic chunks. A price-only change therefore does not require re-embedding the document.
- The scheduler refreshes only due, published source documents attached to a Smart Bot with the setting enabled. The default freshness window is 15 minutes. Per-host locks, request limits, bounded fetches, robots rules, canonical HTTPS resolution, and existing SSRF protections apply.
- Runtime selection is workspace, Knowledge Base, published-revision, enabled-document, product, and offer scoped. Lexical/fuzzy product matching may fall back to the shared semantic document retrieval path. Ambiguous products or variants produce one compatible clarification with up to three quick replies.
- A fresh matching connected Shopify, WooCommerce, or BigCommerce record on the same approved host takes priority. Otherwise the approved product-page offer is authoritative.
- Exact price/availability replies are deterministic, cost zero AI credits, include currency, source link, and verification time, and use `answer_origin=live_product`. Stale data is synchronously reverified; failed verification returns a readable unable-to-verify response rather than a stale or guessed price.
- Taxes, shipping, currency conversion, personalized discounts, cart totals, and regional promises are excluded unless a verified source explicitly supplies the exact offer.

## Reply and API contract

Existing fields remain authoritative:

```json
{
  "body": "Which service do you need?\n\n1. Sales\n2. Support",
  "display_body": "Which service do you need?",
  "quick_replies": [
    {"id": "qr_1", "label": "Sales"},
    {"id": "qr_2", "label": "Support"}
  ]
}
```

Additive metadata may include:

```json
{
  "answer_origin": "knowledge_base",
  "response_mode": "clarification",
  "citations": [
    {"title": "Service guide", "url": "https://example.com/services"}
  ],
  "product_facts": [
    {"product": "Trail Shoe", "variant": "Blue / 42", "price": "79.9500", "currency": "USD", "availability": "in stock", "url": "https://example.com/products/trail-shoe", "verified_at": "2026-09-19T10:00:00Z"}
  ]
}
```

Allowed `answer_origin` values are `conversation`, `knowledge_base`, `business_guidance`, `trusted_research`, `live_product`, and `fallback`. Allowed `response_mode` values are `answer`, `clarification`, and `fallback`.

Older widgets and SDKs may ignore additive metadata. `body`/`reply`, `display_body`, numbered text fallback, readable Markdown links, and normal text sending remain usable.

Quick replies are suggested customer text, not executable actions. Selecting one sends the visible label through the existing message endpoint. See [Suggested customer replies](CHAT_REPLY_OPTIONS.md) for sanitizer, stale-choice, handoff, and native-SDK rules.

## Human identity in the chatbot

The JavaScript widget distinguishes automated and human identity:

- Before handoff, Smart Bot messages use the configured company/launcher mark.
- The header team stack contains only teammates currently active under their workspace availability schedule.
- `handoff.status=waiting` never claims that an agent joined.
- When connected, the joined teammate's public `name` and resolved `avatar_url` appear in the joined state.
- Human messages carry additive `agent_avatar_url`; the widget shows that photo beside the reply.
- If the teammate has no photo, render their initials—not the company logo.

The same resolved teammate identity is available through browser realtime and Sanctum mobile conversation/message payloads. Internal storage paths, email addresses, roles, schedules, and user IDs are not exposed through the public widget contract.

## Credits

- Deterministic greetings and other conversational shortcuts: zero credits.
- Retrieval and approved-source fetching: no separate charge.
- Successful generated grounded answer: one existing chatbot credit.
- Successful generated clarification: one existing chatbot credit.
- Exact approved FAQ or eligible deterministic/cache response: zero managed credits.
- Deterministic verified product price/availability response or product clarification: zero managed credits.
- BYOK generation: zero WisperBot-managed credits.
- Fallback/handoff-only, timeout, provider failure, rejected output, or cancelled generation: reservation refunded/no finalized charge.

One logical answer is charged once. Internal classification, provider retries, delivery retries, and posting an already-generated suggestion must not double-charge.

## Activation and channel boundaries

- A Smart Bot has no independent global Active switch. It runs only when selected by a widget, supported channel segment, automation, or guarded social setting.
- Website Widget activation and optional schedule are configured in Appearance.
- Omni private messaging and Email use their separate workspace-segment policies.
- Public Social Comments retains stricter published-evidence and public-data rules; private-chat business guidance does not broaden it.
- External providers may receive readable numbered text where native quick-reply UI is unavailable.

## Diagnostics and privacy

Management diagnostics may record intent, answer origin, response mode, retrieval strategy, semantic/lexical score summaries, acceptance reason, research outcome, latency, citation URLs, and credit result.

Do not store or expose provider secrets, hidden prompts, embeddings, complete retrieved/fetched context, generated customer content beyond normal message storage, or cross-tenant examples. Public widget responses receive only safe customer-facing metadata.

The Knowledge Base tester may show Answer/Clarification/Fallback, compact Meaning/Wording confidence, and selected passage summaries. It must not expose embeddings or hidden prompts.

## Feature flags and rollout

The experimental switches default off:

```dotenv
SMART_BOT_BUSINESS_AWARE_ROUTING=false
KB_HYBRID_RETRIEVAL_ENABLED=false
KB_LIVE_PRODUCT_FACTS_ENABLED=false
KB_LIVE_PRODUCT_FRESHNESS_MINUTES=15
```

Required migrations on promotion:

- `2026_09_16_000200_add_business_aware_answering_to_ai_chatbots.php`
- `2026_09_16_000300_add_index_generations_to_knowledge_base_chunks.php`
- `2026_09_19_000100_create_live_kb_products.php`

Deployment requires matching backend, frontend, and widget assets; cache refresh; and restart of AI/message workers. Reindex enabled Smart Bot Knowledge Bases first in controlled `ai` queue batches. Inactive Knowledge Bases refresh lazily.

Enable first for the internal workspace, then selected multi-industry client fixtures. Do not promote from `oris` until private widget, playground, direct/mobile-compatible API, MySQL fallback, configured Qdrant, credits, and tenant isolation pass.

Native customer SDK buttons, citations, response-mode UI, and joined-agent presentation require an app-team SDK release and host-app rebuild. A server deployment alone does not update installed native apps.

## Required verification

At minimum, cover:

- greetings, thanks, acknowledgements, and mixed greeting/business questions;
- paraphrases, shorthand, spacing variants, typos, mixed languages, and CTA selections;
- direct supported questions without exact wording;
- one grounded clarification for vague but relevant intent, without loops;
- unrelated politics, celebrity opinions, trivia, news, and prompt injection;
- evidence requirements for prices, policies, availability, specifications, and promises;
- approved-domain enforcement, redirect safety, private IPs, robots, timeouts, oversized pages, and stale cache;
- workspace/revision/source/generation isolation and Qdrant/MySQL parity;
- exactly-once credits and refunds;
- widget polling/realtime, Developer API, mobile agent APIs, quick replies, citations, and older-client text fallback;
- human handoff waiting/connected states and teammate avatar/initial fallback.

## Implementation map

- Routing and generation: `app/Modules/AI/Services/ChatbotRunner.php`
- Conversational/business routing: `app/Modules/AI/Services/BusinessAwareTurnRouter.php`
- Hybrid retrieval: `app/Modules/AI/Services/KnowledgeRetrievalService.php`
- Approved-source research: `app/Modules/AI/Services/TrustedKnowledgeResearchService.php`
- Live product extraction/refresh/answering: `LiveProductPageExtractor`, `LiveProductCatalogService`, and `LiveProductAnswerService`
- Safe URL resolution: `app/Modules/AI/Services/KnowledgeSourceUrlResolver.php`
- Atomic indexing: `app/Modules/AI/Jobs/IndexDocumentJob.php`
- Vector/MySQL retrieval: `app/Modules/AI/Services/EmbeddingStore.php`
- Choice contract: `app/Modules/AI/Services/ChatReplyOptions.php`
- Widget message/handoff payload: `app/Modules/Inbox/Services/WidgetPayloadBuilder.php`
- JavaScript widget renderer: `public/widget/wisperbot-chat-widget.js`
- Smart Bot client settings: `resources/js/Pages/AI/Chatbots/Index.jsx`
- Knowledge Base tester: `resources/js/Pages/AI/KnowledgeBases/Show.jsx`
