# eBay Seller Messaging setup

WisperBot supports encrypted Super Admin eBay credentials, per-workspace seller OAuth, seller-account isolation, manual and scheduled message sync, and text replies from the Omni Channel Inbox.

## 1. Create the eBay developer application

1. Register at <https://developer.ebay.com/>.
2. Open **Application Keys** and create a Sandbox keyset.
3. Copy the Sandbox **App ID (Client ID)** and **Cert ID (Client Secret)**.
4. Open **User Tokens**, create an OAuth-enabled redirect URI, and set its accepted URL to:

   `https://YOUR-DOMAIN/app/inbox/setup/ebay/callback`

5. Save the generated **RuName**. eBay expects this RuName—not the URL—in OAuth `redirect_uri`.
6. Ensure the application can request:

   - `https://api.ebay.com/oauth/api_scope`
   - `https://api.ebay.com/oauth/api_scope/commerce.message`

## 2. Configure Super Admin

Open **Super Admin → Integrations → eBay Seller Messaging (OAuth)** and enter:

- Client ID
- Client Secret
- OAuth Redirect URI Name (RuName)
- Environment: `sandbox`
- Default Marketplace ID, such as `EBAY_GB`

Save, enable, and run **Test connection**. This validates the application keyset. A seller OAuth connection is still required to validate user messaging access.

## 3. Connect a client seller account

The client opens **Inbox Channel Setup → Connect eBay**, signs into eBay, and grants access. WisperBot:

- validates a short-lived OAuth state value;
- exchanges the authorization code server-side;
- encrypts the access and refresh tokens;
- binds the seller identity to one workspace only;
- runs the first inbox synchronization.

Use **Sync eBay messages** on the connected account for an immediate refresh. The scheduler also dispatches a sync every five minutes to the `social` queue, so the production queue worker must include `social`.

## 4. Production activation checklist

Before changing Environment to `production`:

1. Obtain/enable a Production keyset and Production RuName.
2. Register the same production callback URL with that Production RuName.
3. Complete eBay Marketplace Account Deletion/Closure notification subscription or its official opt-out process. eBay requires this before a new application makes its first production API call.
4. Complete any eBay review required for Commerce Message API access.
5. Replace Sandbox credentials with Production credentials, set Environment to `production`, test, then reconnect each seller account.

The current integration uses safe scheduled polling for inbound messages. eBay Notification API delivery can replace or supplement polling after production notification credentials and signature validation are configured.
