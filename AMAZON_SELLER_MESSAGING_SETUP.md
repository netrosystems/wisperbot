# Amazon Seller Messaging (SP-API) setup

WisperBot supports Super Admin Amazon SP-API application settings, per-workspace seller OAuth, encrypted LWA refresh tokens, regional SP-API endpoints, and the foundation for Amazon-approved order-specific buyer messages.

## Platform limitation

Amazon's Messaging API allows a seller application to discover the message actions permitted for a specific Amazon order and send one of those messages. It does not provide an API for reading or mirroring the inbound Buyer–Seller Messaging inbox from Seller Central. WisperBot therefore does not claim to synchronize inbound Amazon message threads.

## 1. Create the Amazon application

1. Register through the Amazon Solution Provider Portal.
2. Create a public Seller SP-API application in Draft.
3. Request the **Buyer and Seller Messaging** role.
4. Set the OAuth Login URI to:

   `https://YOUR-DOMAIN/app/inbox/setup/amazon/login`

5. Set the OAuth Redirect URI to:

   `https://YOUR-DOMAIN/app/inbox/setup/amazon/callback`

6. Copy the LWA Client ID, LWA Client Secret, and SP-API Application ID.

## 2. Configure Super Admin

Open **Super Admin → Integrations → Amazon Seller Messaging (SP-API)** and enter:

- LWA Client ID
- LWA Client Secret
- SP-API Application ID
- Application Stage: `sandbox` while Draft, then `production`
- Selling Region: `na`, `eu`, or `fe`
- Seller Central base URL
- Default marketplace ID

For Amazon UK, use:

- Seller Central: `https://sellercentral.amazon.co.uk`
- Selling Region: `eu`
- Marketplace ID: `A1F83G8C2ARO7P`

## 3. Connect a seller

The client opens **Inbox Channel Setup → Connect Amazon**, grants access in Seller Central, and returns to WisperBot. WisperBot validates OAuth state, exchanges the short-lived authorization code, encrypts the LWA refresh token, and prevents the same seller identity from being connected to multiple workspaces.

Draft applications use Amazon's `version=beta` authorization flow automatically. After Amazon publishes the application, change Application Stage to `production` and reconnect the seller.
