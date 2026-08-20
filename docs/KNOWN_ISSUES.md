# Known issues and limitations

Status snapshot: 2026-08-21. Remove an item only when the fix is verified and recorded in `CHANGELOG.md`.

## Queue name mismatch

`App\Http\Controllers\Api\V1\AutomationApiController` dispatches an automation run to `automations` (plural), while the documented/production queue is `automation` (singular). Jobs from that API path may wait indefinitely unless a worker consumes the plural queue. Normalize this in code with a regression test.

## Meta review and evolving permissions

Meta permission names/products and App Review state are external dependencies. The system may contain working admin/test flows while general users remain blocked until permission approval. Re-verify scopes against current official Meta documentation before each review submission.

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
