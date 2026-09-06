# Suggested customer replies

Implemented locally on 2026-09-06; not deployed or published to the Flutter package registry.

## Behavior

Smart Bots may offer two or three short choices when clarification or a next topic is useful. These are suggested **customer messages**, not executable actions. Selecting “iOS app” sends exactly that text through the existing visitor message endpoint. The next answer uses the normal conversation and Knowledge Base pipeline. Customers may always type instead.

The web widget and updated Flutter prebuilt chat render compact wrapping buttons. Only the latest support message has active choices; a customer response, pending send, or human handoff disables them. Historical choices remain readable. The agent inbox renders noninteractive choice labels, so agents cannot accidentally submit a customer choice. Smart Bot Playground also supports choices and now forwards bounded history so follow-up selections have context.

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

The `wisperbot_chat` repository adds `WisperBotMessage.quickReplies`, `displayBody`, `WisperBotQuickReply`, `controller.canSelectQuickReply(message)`, and `controller.selectQuickReply(message, choice)`. Prebuilt screens render choices automatically. Custom `messageBuilder`/headless integrations must render them and call the controller method. Old SDKs still display the numbered body. Existing installed apps do **not** gain native buttons from a backend deployment: release the SDK, update the application's dependency, rebuild, and distribute the app. Flutter web builds also require rebuilding/redeployment.

External WhatsApp/Messenger/Instagram messages retain the numbered text fallback in this release; native provider interactive-button support is not claimed. Selecting a choice does not trigger payment, booking, cancellation, or human handoff.

## Rollout

Deploy matching backend, `public/widget/wisperbot-chat-widget.js`, and a locally built dashboard bundle. Restart `ai`/message workers and refresh config caches. No database migration is required. `CHATBOT_QUICK_REPLIES_ENABLED=false` disables requesting choices for new generations; existing history remains compatible. Keep the package release and host-app rollout separate from the server deployment. Never upload a broad dirty-worktree build without reviewing the unrelated pending features.
