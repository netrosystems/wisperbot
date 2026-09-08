# Suggested customer replies

## Status and ownership — 2026-09-08

The original generic server/widget implementation was committed as `aebd7d6` and is present in local main's merged history at `9258682`. The 2026-09-08 question-generation/recovery changes remain local and uncommitted. The observed live missing-button example remains an unresolved end-to-end release check; a passing fixture or a merged commit does not establish production behavior.

An earlier local Flutter prototype was explicitly excluded from the WisperBot commit and was not pushed or published by this work. The app team owns SDK implementation, versioning and host-app releases. Do not describe that prototype's classes as an API guaranteed to exist in their released package.

The product requirement is defined in [Product decisions](PRODUCT_DECISIONS.md#dynamic-smart-bot-questions-and-cta-replies). All industries share the same contract; client screenshots are examples only.

## Behavior

Choice generation is domain-independent, not a predefined Yes/No or eSIM flow. The model returns a contextual question and two or three corresponding labels, including localized labels, through the same `quick_replies` contract. For example, device → installation method → failure stage can each produce different choices. Web/SDK renderers must display received labels generically, never switch on specific labels such as Yes or Supported. The narrow English recovery described below is only a compatibility safety net when structured choices are missing, not the primary choice-generation mechanism.

Smart Bots may offer two or three short choices when clarification or a next topic is useful. These are suggested **customer messages**, not executable actions. Selecting “iOS app” sends exactly that text through the existing visitor message endpoint. The next answer uses the normal conversation and Knowledge Base pipeline. Customers may always type instead.

2026-09-08 local hardening: generation instructions require matching choices whenever the assistant asks a closed-choice question, with one troubleshooting question at a time. When choices are omitted, enabled generation paths conservatively recover explicit English Supported/Not supported or Yes/No prompts and a limited set of direct English customer-state questions. Recovery is not a general natural-language classifier; other languages and arbitrary choices rely on structured model output. Open questions and ordinary answers remain button-free. Recovery adds no provider request or credit cost, preserves the answer text, and does not backfill historical messages. SDK code remains the app team's responsibility.

The web widget renders compact wrapping buttons. The customer SDK must implement equivalent behavior as part of its separate release. Only the latest support message has active choices; a customer response, pending send, or human handoff disables them. Historical choices remain readable. The agent inbox renders noninteractive choice labels, so agents cannot accidentally submit a customer choice. Smart Bot Playground also supports choices and forwards bounded history so follow-up selections have context.

## Additive contract

Outbound message type remains `text`. `body` (or AI API `reply`) retains numbered choices for older clients and external messaging channels:

```json
{
  "body": "Which app do you need?\n\n1. iOS app\n2. Android app",
  "display_body": "Which app do you need?",
  "quick_replies": [
    {"id": "qr_1", "label": "iOS app"},
    {"id": "qr_2", "label": "Android app"}
  ]
}
```

Widget session/history, polling, and realtime use `WidgetPayloadBuilder`, exposing `display_body` and `quick_replies` at message level. Agent conversation APIs expose them under the existing `payload`. AI chat API results add the same fields alongside `reply`, `tokens_used`, and `resources`. Fields are optional for deterministic FAQ/cache/fallback answers and older servers.

`POST /widget/v1/messages` is unchanged: send `{key, message: choice.label}` using the existing visitor token. Choice IDs are local display identifiers, not credentials, capabilities, or workflow commands. No client-supplied hidden value is executed. Existing visitor-session and workspace authorization remain authoritative. Typing the same text manually has identical meaning.

## AI and cost

The existing generation returns JSON with `reply` and optional `quick_replies` within the existing 160-token cap; no second suggestion-generation call is made. The sanitizer bounds options to three, strips untrusted fields, rejects HTML/URL/control-character labels, deduplicates labels, and creates IDs server-side. Malformed/truncated structured output fails validation before credit finalization and uses the existing safe fallback. Plain-text provider responses remain supported. Existing one-credit managed answer charging and BYOK behavior apply; tapping does not cost credits by itself, but generating the next answer normally does. Dialogue-specific choices are not saved in the shared answer cache.

## Flutter integration

The app team must decode optional `display_body` and `quick_replies` on session, polling and realtime message paths; render generic labels; and send the selected label through the existing text-send flow with pending/stale/handoff guards. The earlier uncommitted prototype used `WisperBotMessage.quickReplies`, `displayBody`, `WisperBotQuickReply`, `controller.canSelectQuickReply(message)` and `controller.selectQuickReply(message, choice)` as suggested names, not a confirmed released API. Custom message renderers need the same handling. Old SDKs retain numbered body text. Existing installed apps do **not** gain native buttons from a backend deployment: the app team releases the SDK, updates dependencies, rebuilds and distributes host apps. Flutter web builds also require rebuilding/redeployment.

## Verification and diagnosis

Trace `ChatbotRunner` → `ChatReplyOptions` → `AutoReplyListener` message payload → `WidgetPayloadBuilder` → session/poll/realtime → generic renderer. `POST /widget/v1/messages` acknowledges the visitor's message; the AI message is read from polling or realtime, not assumed to be the send acknowledgement. Agent-mobile APIs put these fields under `payload`, whereas the customer widget API exposes them at message level.

Before claiming completion, verify multiple unrelated client domains and languages, tenant-specific KB facts, open questions without buttons, arbitrary choice labels, several consecutive selections, an unsupported branch, stale options, and an unchanged text composer. Compare raw message JSON with what the widget and released SDK render. Tests using constructed provider responses establish contracts, not model reliability or production rollout. The narrow English recovery cannot satisfy arbitrary missing model choices; investigate generation/configuration/deployment rather than adding client-specific label lists as the primary mechanism.

External WhatsApp/Messenger/Instagram messages retain the numbered text fallback in this release; native provider interactive-button support is not claimed. Selecting a choice does not trigger payment, booking, cancellation, or human handoff.

## Rollout

Deploy matching backend, `public/widget/wisperbot-chat-widget.js`, and a locally built dashboard bundle. Restart `ai`/message workers and refresh config caches. No database migration is required. `CHATBOT_QUICK_REPLIES_ENABLED=false` disables requesting choices for new generations; existing history remains compatible. Keep the package release and host-app rollout separate from the server deployment. Never upload a broad dirty-worktree build without reviewing the unrelated pending features.
