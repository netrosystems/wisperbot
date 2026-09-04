# Known issues and limitations

Status snapshot: 2026-09-03. Remove an item only when the fix is verified and recorded in `CHANGELOG.md`.

## Website indexing boundaries

Website discovery handles safe redirects, declared/common/compressed sitemaps, and a capped same-host link fallback. It cannot index authenticated pages or override a site's WAF, rate limits, DNS/TLS failures, or crawler restrictions. Fully client-rendered sites that return no useful HTML need a future opt-in browser-rendering service; clients should currently expose server-rendered help content or upload reviewed files. The UI reports actionable failures and never bypasses the target site's access controls.

## Queue name mismatch

`App\Http\Controllers\Api\V1\AutomationApiController` dispatches an automation run to `automations` (plural), while the documented/production queue is `automation` (singular). Jobs from that API path may wait indefinitely unless a worker consumes the plural queue. Normalize this in code with a regression test.

## Meta review and evolving permissions

Meta permission names/products and App Review state are external dependencies. The system may contain working admin/test flows while general users remain blocked until permission approval. Re-verify scopes against current official Meta documentation before each review submission.

## Meta app-owner WABA onboarding

Meta disables the Meta-app owner's own Business Portfolio in the app's customer Embedded Signup asset selector. That WABA cannot be used to validate the normal customer Coexistence selector. Connect it through the approved platform system user and explicit WABA/phone asset assignment, or test customer onboarding with a separately owned Business Portfolio. This is provider-side and must not be bypassed by sharing a platform token with clients.

## Meta inbound queue naming

Messenger and Instagram inbound webhook jobs run on the `whatsapp` queue. This is functional but non-obvious; omitting the WhatsApp worker also stops those channels. A future migration may rename this to a neutral inbound queue, but must preserve zero-downtime deployment.

## Amazon messaging scope

Amazon SP-API does not expose a general inbound Buyer–Seller Messaging inbox. The integration is limited to Amazon-approved, order-specific messaging actions. Do not describe it as full inbox synchronization.

## eBay production readiness

Production use depends on eBay production keys, Commerce Message API access, and marketplace account deletion/closure compliance. Polling is currently the safe inbound mechanism until production notification subscriptions are completed.

## Shared-hosting frontend builds

cPanel Node builds can be killed by memory limits. A backend Git pull does not update `public/build`. Build locally from the exact main revision and upload the bundle when the host cannot compile it.

## PHP runtime differences

CLI and web PHP may load different INI files/extensions. IMAP and upload-limit checks must be performed in the actual web SAPI; CLI success alone can be misleading.

## Live visitor accuracy

Live Users is heartbeat/expiry based, not a replacement for analytics. Browser suspension, network loss, privacy tooling, and delayed cleanup can create short-lived discrepancies. UI copy should communicate “recently active” semantics where exactness matters.

## Provider-side published post mutations

Remote edit/delete support depends on provider, media/post type, permissions, stored remote ID, and token state. Local deletion must not imply provider deletion succeeded. The UI is capability-driven; retain explicit error details for failed remote actions.

## Runtime files in production checkout

Past deployments have produced untracked logs, lock files, build zips/backups, and macOS metadata inside the repository. These can block fast-forward pulls or obscure the deployed state. Move operational artifacts outside the checkout and keep production `git status --short` clean.

## Managed AI rollout state

The managed-credit system ships in shadow mode unless `AI_CREDITS_ENFORCE=true`. Production must configure the managed OpenAI integration, finite plan allowances, scheduler, privacy/subprocessor disclosures, and reconcile at least two weeks of ledger/provider-cost data before hard enforcement. Super Admin reporting and audited adjustments are available at `/admin/ai-credits/report`; cost and margin figures are estimates and must not be treated as provider invoices.

## Guarded Knowledge Base rollout state

Guarded revisions ship disabled unless `KB_GUARDED_PUBLISHING=true`. OCR, audio transcription, authenticated crawling, arbitrary archives, and multi-KB chatbot composition remain deferred. Automated correction suggestions improve structure only and still require explicit client acceptance; deterministic conflict detection intentionally requests human authority selection rather than choosing facts. Native production answer-quality comparison and semantic-cache telemetry must be validated during staged rollout before removing legacy immediate publishing.
