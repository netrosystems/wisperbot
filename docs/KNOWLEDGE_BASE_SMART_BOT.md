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

## Starter questions (2026-09-19, moved to the widget 2026-09-26)

Starter questions are no longer part of the Smart Bot. `chat_widgets.starter_questions_enabled` and `chat_widgets.starter_questions` (migration `2026_09_26_000100_move_starter_questions_to_chat_widgets`, which copies each widget's linked bot's questions) hold up to five client-written `{id, question, answer}` items, edited in Widget Setup → Starter questions. Questions are at most 80 characters, labels only (no markup, links or control characters), distinct after normalising, and never a human-handover phrase; answers are at most 1,000 characters of plain text. Rules and checks live in `StarterQuestions::rules()`/`assertUsable()`.

- **Answered before the bot, with or without AI.** For webchat messages (website widget and customer SDK), `AutoReplyListener` checks `StarterQuestions::match($widget, $body)` right after the human-handled early return and before keyword rules. A message that equals a saved question after NFC, lowercasing and collapsing everything except letters, combining marks and digits gets the saved answer word for word, sent within the customer's request, with `answer_origin=starter_question`, zero tokens and zero credits. Combining marks are kept, so Bengali words that differ only by a vowel sign never match each other; an empty normalised message never matches.
- **The Smart Bot does not answer them.** `ChatbotRunner` no longer has a starter step, so the playground, developer API and other channels (WhatsApp, Messenger, Instagram) treat an identical question like any other message. The old `ai_chatbots.starter_questions*` columns are kept but unused.
- **Human handling wins.** Paused, assigned, joined or handed-over chats get no starter answer.
- **Follow-ups.** The starter exchange is ordinary conversation history, so a follow-up question retrieves and answers normally.

The customer contract is in [Suggested customer replies](CHAT_REPLY_OPTIONS.md#starter-questions-2026-09-19).

## Turn-routing contract

`BusinessAwareTurnRouter` runs before strict retrieval when `SMART_BOT_BUSINESS_AWARE_ROUTING=true`.

### 1. Conversational turn

Standalone greetings, thanks, acknowledgements, and goodbyes receive a short localized response using the configured tone and trusted brand name.

- Deterministic conversational replies use no LLM call and cost zero credits.
- A mixed turn such as “Hi, what is your return policy?” is a business question, not a greeting shortcut.
- Replies to the assistant's own “anything else?” offer are conversation, handled by `BusinessAwareTurnRouter::offerReplyResult()` before any retrieval and regardless of `SMART_BOT_BUSINESS_AWARE_ROUTING` (2026-09-19). A decline (“No”, “nope”, “না”) gets a branded goodbye, and acceptance (“Yes”, “sure”, “হ্যাঁ”) gets “What else can I help you with?”; both are zero-credit, with no model call and no handoff. Clear closings (“No thanks”, “That's all”, “I'm good”, “lagbe na”, “লাগবে না”) work without a prior offer. A bare “No/Yes” after any other question (for example a troubleshooting question) continues through normal answering. Previously the decline reached strict grounding, the model's goodbye was rejected as ungrounded, and the chat was handed off.

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

Keyword-only candidates (found by lexical search but outside the vector window) are scored on meaning from their stored MySQL embedding via `EmbeddingStore::similarity()`; they no longer enter at semantic score 0. Near-duplicate passages (word-set overlap ≥ 0.75, for example the same file uploaded twice or a revised re-upload) are collapsed before the answer/clarify decision in both retrieval paths, keeping the copy ordered first, which favours the stronger source settings. On the Telzen Knowledge Base, copies overlapped 0.80–1.00 and distinct passages at most 0.44. This stops an older copy without a video link from taking one of clarification mode's two evidence slots.

### Source authority (2026-09-19)

A client's Knowledge Base usually mixes sources that disagree: an operations document, marketing website pages, legal pages, older uploads. The client's **Authoritative source** checkbox and **Priority** (Low 25 / Normal 50 / High 75 / Critical 100) settle those conflicts in both the hybrid and legacy retrieval paths:

- **Relevance decides whether to answer.** Thresholds, `answer`/`clarification`/`fallback`, `best_score`, and video matching all use the unchanged relevance `rank_score`. Authority can never make the bot answer from weak evidence.
- **Authority decides which evidence leads.** `AiKbDocument::retrievalWeight()` (authoritative +0.06; priority ±0.04 around Normal) is added to a separate `order_score` used only for ordering.
- When relevant evidence from an authoritative source exists within 0.12 of the best relevance, one slot is kept for it, and authoritative passages are presented first, labelled `[Authoritative source: …]` (`AiKbDocument::passageLabel()`).
- The Knowledge Base business profile (`brand`, `purpose`, `audience`) leads the verified evidence in every Knowledge Base answer, marked as written by the business. Empty fields are omitted.
- The prompt tells the model to follow the profile and authoritative sources when sources disagree, and to describe what the business is, offers, or how it works from them. The former "prefer the highest-ranked passage" rule, which made marketing copy win conflicts, is removed.

Availability questions ("do you sell/have/offer…", "is there…", "can you…", Roman Bangla `ache`/`pabo`, Bangla আছে/পাবো) count as explicit requests, so a clear keyword match to a direct answer is answered rather than forced into a clarifying question. The platform default private-chat window is 5 passages / 1,600 context tokens (`KB_MAX_CONTEXT_CHUNKS`, `KB_MAX_CONTEXT_TOKENS`); public comments stay capped at 3. Replies may use up to 4 short sentences / 70 words, answering first and then guiding the next step, with a 320-token output budget.

Known limit: Roman Bangla questions with little keyword overlap (for example "kivabe kaj kore") still score too low on meaning with the current embedding model and fall back.

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
- Safe citations contain only a readable title and HTTPS URL. They are kept for staff and the API, never shown to customers (no "Sources:" text, empty public `citations`).

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

### Video resources

A matched Knowledge Base video (`resources`, at most one per reply) belongs to the turn that delivers its solution, not to the follow-up questions that lead there. `ChatbotRunner::selectVideoResource()` still decides which video matches the passage; `ChatbotRunner::resourcesForReply()` then decides whether this reply shows it:

- Never on clarification-mode replies, replies that consist only of a question, empty replies, or fallback/provider-failure replies.
- A video is **offered** whenever a passage given to the model as evidence links it, in answer or clarification mode (clarification may answer when the passages clearly do). The prompt names the video's title and quotes every set of Knowledge Base steps the link follows in the passages given to the model (up to three), so the model can recognise any of them. It is told to decline when it asks a question, sends the customer to browse the app or website, or answers another topic.
- It is **shown** when the model returns `"show_video": true`, pastes the video's link, or mentions the video in its reply in any common language (video, tutorial, ভিডিও, видео, فيديو, 视频, …). An explicit `false` without a mention hides it. It is never shown on a reply that offers choices and continues after its question (“Is it supported? If yes, …”), because the customer is still being qualified; a solution that ends with a closing offer keeps its video. The prompt also tells the model to ask the flow's question alone and give the steps after the answer.
- If a provider omits the flag, the video is shown only when its passage is the top evidence of a Knowledge Base answer and ranks at or above `KB_VIDEO_MATCH_THRESHOLD` (0.72). OpenAI replies always carry the flag (see structured outputs below).
- Only a passage actually given to the model can supply a video (`passage_chunk_ids` from both retrieval paths). Candidates that were ranked but not included, and research-only answers without a Knowledge Base passage carrying the link, never attach one.
- The Knowledge Base decides where a video belongs: a link placed after a set of steps makes that video eligible for replies that give those steps. Reusing one video link after unrelated steps makes it eligible there too, so clients should link each video only where it demonstrates the steps.

The rule is language- and industry-independent (Bengali `।` and multi-line steps count as statements before a closing question). WhatsApp/Messenger/email "Watch video" text fallbacks use the same `resources`, so they follow it too.

Discovered videos no longer use their source document's name as a title. Indexing fills an untitled YouTube/Vimeo link with the provider's public oEmbed title (4-second timeout; only successful lookups are cached, for 7 days), otherwise the title is omitted and clients show the provider name. Documents indexed before 2026-09-19 have their document-name title suppressed at answer time, and receive the real title on their next reindex. Dedicated `source_type=video` records keep their authored title.

The website widget shows the reply text first and then a “▶ See Tutorial →” chip below the reply text, styled like the reply-choice buttons, that opens the video's own page (YouTube/Vimeo watch page, or the MP4 file) in a new tab with `rel="noopener noreferrer"`. Its accessible name includes the video title and the provider (“opens YouTube in a new tab”). Nothing is embedded. The model is told the platform adds this link, so it never pastes the URL itself. The web Inbox keeps a 16:9 click-to-play card with title and link for agents.

### Reliability and languages (2026-09-19)

- **Website chat replies run on the `ai` queue** (`ProcessWebchatAiReplyJob`, 120-second timeout, one attempt), like WhatsApp/Messenger/email. Previously they ran inside the visitor's send request, and a slow model or approved-source fetch could exceed PHP's 30-second limit and lose the reply. The widget receives the reply by poll or realtime as before.
- **Structured outputs.** OpenAI chat calls send the strict `smart_bot_reply` JSON schema (`reply`, `quick_replies`, `grounded`, `response_type`, `show_video`, all required), so no decision key can be omitted. Models that reject structured outputs (HTTP 400) are retried once in plain JSON mode. Other providers keep JSON mode.
- **One retry.** When a reply fails validation but was a genuine attempt (non-empty reply), the gateway draws one more sample under the same credit reservation, so it is charged once. A deliberate empty “cannot answer” is not retried.
- **Cross-language retrieval.** When a message is probably not English (non-Latin script, or no English function words, as in romanized Bengali), `KnowledgeRetrievalService::englishSearchQuery()` rewrites it into a short English search query (0-credit `kb_search_translation` action, cached per workspace and message). The query is **added** to the search, never substituted, so a Knowledge Base in the customer's own language still matches directly; the legacy path keeps whichever search scores better. Replies stay in the customer's language and script: romanized input is answered in Latin letters.
- **Rate limits.** Zero-credit internal steps no longer count toward the per-minute managed request budget (10/min on plans up to 100 credits, otherwise 30/min), because they belong to a customer action that is already counted. The concurrency cap still applies.
- **Roman Bangla social phrases** (for example “kemon acho”, “assalamualaikum”, “dhonnobad”, “thik ache”, “allah hafez”) are handled as greetings, thanks, acknowledgements, or goodbyes when business-aware routing is on.

Measured on the Telzen Knowledge Base: before this change, “Kivabe esim pabo?”, “data kaj korche na”, and “ইসিম কিভাবে ইনস্টল করব” all fell back (meaning scores 0.21–0.25). With the English search query they reach `answer`/`clarification` from the client's document (0.42–0.69).

### Reply style: facts strict, wording free (2026-09-19)

Product decision: Smart Bots answer like a support agent rather than repeating Knowledge Base text.

- **Facts stay exact and grounded.** Prices, sizes, durations, menu paths, policies, and countries must come from the verified context, the business profile, or the customer's own message. Conversation history is never evidence.
- **Wording is the bot's own by default.** The bot leads with what the customer asked, keeps only what helps, and gives steps in the order the customer performs them. Passages written as scripted conversations (“Customer: … AI: …”) show the intended facts and flow, not text to copy: the bot follows the flow (for example, asking the script's question first) in natural wording. Temperature is 0.4.
- **`ai_chatbots.kb_exact_wording`** (migration `2026_09_19_000200_add_kb_exact_wording_to_ai_chatbots`, default off) is the client's “Use Knowledge Base wording exactly” switch for regulated or scripted businesses. When on, the bot uses the approved wording as written, changing only what is needed to fit the question and language, at temperature 0.2. It is exposed read-only on the AI chatbot API list.
- **Never shown to customers:** editing notes and script markers (“[Shows two CTAs]”, “***”, “AI:”/“Customer:” labels), video links (`ChatbotRunner::withoutVideoLinks()` removes YouTube/Vimeo/MP4 links from the text; pasting the matched video's link counts as choosing to show it as the player), and sources.

Grounding checks (`ChatbotRunner::validChatResponse()`):

- A reply that only asks the customer one short question is a conversation move and states no business facts, so it is accepted even when the model marks it `grounded: false`. This stops legitimate follow-ups (“Which country are you travelling to?”) from becoming handoffs.
- In clarification mode, the model may answer instead of asking when the passages clearly answer the request. The answer is accepted only with `grounded: true` and is then treated as `response_mode=answer`.
- The model's `grounded: true` alone is not trusted. `hasUnsupportedFigures()` rejects any reply or choice stating a figure (price, currency amount, data size, percentage, duration, or any number of two or more digits) that does not appear in the evidence. It normalises Bengali digits, `1,000`, and `1.20`/`1.2`, and ignores single-digit step numbers. Data sizes and percentages must match their unit; currency and time may match a bare number (for example “৳128” against “128tk”). This check also applies to a follow-up question's choices.
- General-guidance replies (no Knowledge Base evidence) must not suggest specific plans, package sizes, data amounts, prices, or products, in text or choices.
- Empty or ungrounded factual statements are still rejected, and a knowledge-only bot then follows its configured unsupported-answer behavior.

Known limit: choice labels that name an unsupported product type without a figure (for example “Unlimited plan”) are discouraged by the prompt but cannot be caught deterministically.

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
- Starter question answer: zero credits, no model call.
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
