# Merchant-aware Snippe hosted checkout

## Scope and architecture

The public portal remains phone-only. `/api/public/orders` assigns the business from server-side `WIFI_BUSINESS_CODE` (default `RJAY_WIFI`); a supplied business ID is ignored. Each installation currently selects one business. Serving different businesses on different hosts in a single deployment requires an explicit trusted host mapping; no frontend-selected merchant is accepted.

`POST /api/public/orders/{uuid}/pay` requires the existing order access token, resolves the order's active Snippe account, persists a local payment intent, and calls `POST /api/v1/sessions`. It sends the phone, fixed amount, TZS, mobile_money, profile ID, return URL and merchant webhook URL. Business/order/payment UUIDs are metadata; no credentials enter metadata.

After session creation, the backend persists its non-empty provider reference (including PAY-prefixed references), checkout URL and a durable submission marker, then submits exactly once to the validated HTTPS `snippe.me/checkout/{token}/pay` endpoint. It sends only `payment_method=mobile_money` and `customer_phone`, without merchant Authorization, name or email. Redirects, other hosts, query strings and unexpected paths are rejected.

The portal stays on its status page when the response contains a pending attempt with a non-empty payment token (the documented indication that the push was sent). Only then does it show the PIN instruction. Attempt IDs, payment tokens and submission results remain in hidden server-side provider_payload; the frontend receives only checkout_state. Hosted sessions have no synthetic countdown. A provider-supplied expiry ends the PIN prompt when polling sees it has expired.

Timeouts, malformed responses and failures retain the same session and offer Continue to payment. No automatic retry of the checkout POST occurs. Repeated Pay verifies the existing session without another submission. Previously recovered sessions are read-only and continue through their existing hosted URL. Session-creation ambiguity retains the local intent for explicit recovery rather than creating a second payment.

The provider session reference and actual payment reference are distinct. A successful create response only stores checkout information and leaves the order unpaid. Returning to the portal also leaves the order unpaid. The existing order-token session storage and HotSpot context survive the same-tab redirect.

`POST /api/webhooks/snippe/{gatewayUuid}` verifies that merchant's signature on the raw body and a five-minute timestamp window. It matches gateway, business, order, session/payment references, amount, currency and supplied metadata. Payment/order locks, merchant-scoped event identities and paid-state checks prevent duplicate provisioning dispatch for repeated webhook events. Session-attempt failure does not expire the whole session. Completion dispatches the existing `ProvisionPaidOrder` after the transaction commits. Existing MikroTik first-login binding and validity rules remain unchanged.

Gateway secrets use Laravel encrypted casts and hidden attributes. **Keep the existing APP_KEY and back it up securely**; changing it without a key-rotation plan makes stored credentials unreadable. Public payment payloads hide provider_payload. Provider HTTP errors are converted to safe messages without logging raw responses or credential-bearing requests.

Admin endpoints are protected by the installation's existing `admin.token` middleware. These are central installation administrators, not separate tenant-admin roles.

## Official contracts

- https://docs.snippe.sh/docs/2026-01-25/sessions
- https://docs.snippe.sh/docs/2026-01-25/sessions/profiles
- https://docs.snippe.sh/docs/2026-01-25/webhooks

The session request's customer object is optional; this integration sends only customer.phone. `collect_email`, locale and merchant branding come from the Payment Profile, not request-body overrides. Creation is rejected locally if the session response requires email or allows methods other than mobile_money. Session creation idempotency is not expressly guaranteed by the Sessions documentation: we send Idempotency-Key but do not rely on it to retry an ambiguous creation.

## Deployment

No deployment/commit/push is performed by this implementation. First publish the reviewed code through your normal release process. Run the following from the production repository root after taking a database backup and recording the current release. Pause incoming traffic and drain/pause your existing queue supervisor before migration; process/service names depend on your deployment.

```bash
cd backend
php artisan down --retry=60
cd ..
git pull --ff-only origin main
cd backend
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
cd ../frontend
npm ci
npm run build
cd ../backend
php artisan config:cache
php artisan route:cache
php artisan queue:restart
```

Configure the first gateway below **before** reopening traffic:

```bash
php artisan snippe:configure RJAY_WIFI
php artisan up
```

Resume the existing queue supervisor if paused. Do not run `key:generate` on an existing installation. Do not use `migrate:fresh`, seed replacement records, or delete historical payments.

The new migration creates businesses/payment_gateway_accounts, adds business and gateway foreign keys and session fields, scopes references by gateway, and backfills historical orders/payments to the real current RJAY_WIFI business. It does not change payment providers, amounts, payment state, or voucher dates. No credential is imported from global environment variables. Migration rollback refuses to drop merchant mappings when merchant payments exist; use a reviewed recovery/release rollback plan instead of dropping data. A backup restore after new transactions would lose those transactions and needs explicit reconciliation.

## First merchant setup

1. In the RJAY WiFi Snippe account, create a Payment Profile:
   - merchant_name: RJAY WiFi
   - collect_email: false
   - allowed_methods: mobile_money only
   - locale: sw
   - record its profile ID.
2. Configure these **non-secret** server settings using your production configuration system, then rebuild Laravel config cache:

```dotenv
WIFI_BUSINESS_CODE=RJAY_WIFI
WIFI_PORTAL_URL=https://wifi.95-111-248-145.sslip.io
SNIPPE_WEBHOOK_BASE_URL=https://wifi.95-111-248-145.sslip.io
```

3. Run `php artisan snippe:configure RJAY_WIFI`. Enter API origin `https://api.snippe.sh`, the actual profile ID, and enable the gateway. API key and webhook secret are entered at hidden prompts, never command-line arguments. The command prints the gateway UUID and its exact HTTPS webhook URL. No placeholder key will work.
4. Configure that URL in the same merchant account if dashboard webhook configuration is required. It has the form `https://wifi.95-111-248-145.sslip.io/api/webhooks/snippe/{actual-gateway-uuid}`. Requests also carry this URL.
5. Verify the actual account's key has Sessions API access. API requests are restricted to api.snippe.sh; redirects are restricted to snippe.me. If Snippe changes its official hosts, review and update the server-side allowlists rather than accepting arbitrary hosts.
6. For historical direct Snippe payments, verify which merchant owns them, then run:

```bash
php artisan snippe:configure RJAY_WIFI --gateway=ACTUAL_GATEWAY_UUID --link-legacy
```

Leave new credential prompts blank to retain stored secrets. Explicitly confirm merchant ownership. The old `/api/webhooks/snippe` callback only accepts a historical reference linked to exactly one gateway, verified with that gateway's secret. Unmapped historical payments are preserved but cannot be reconciled until linked. ClickPesa historical webhooks/status reconciliation remain supported; new public `/pay` calls never initiate ClickPesa. Existing payments are not silently converted into a second provider charge.

## Admin API

Use your existing authenticated admin API client; do not paste secrets into URLs or shell command history.

- GET/POST `/api/admin/businesses`
- GET/PUT `/api/admin/businesses/{businessUuid}`
- POST `/api/admin/businesses/{businessUuid}/gateways`
- PUT `/api/admin/businesses/{businessUuid}/gateways/{gatewayUuid}`

Business input: name, code, status (active/inactive). Gateway input: base_url, payment_profile_id, active; api_key and webhook_secret are required on create and optional on update. Omitting secrets retains them. Provider is fixed to snippe and webhook_url is generated server-side. Responses include api_key_configured/webhook_secret_configured, never secret values. Only one gateway per business can be active. Disabling a gateway rejects its subsequent webhooks; resolve pending payments before disabling. Merchant credential changes are blocked while pending payments exist.

## Ambiguous sessions and expiration

After a timeout or invalid provider response, a local pending intent is deliberately retained and `/pay` will not create another session. Find that session in the correct Snippe merchant dashboard using its local payment UUID metadata, then run:

```bash
php artisan snippe:recover-session ACTUAL_LOCAL_PAYMENT_UUID ACTUAL_SNIPPE_SESSION_REFERENCE
```

This fetches the session with its gateway's key and verifies all ownership metadata, amount and currency before linking it. It does not mark payment complete. If already paid, replay the genuine signed webhook from Snippe. If no session can be found, resolve the ambiguous request with Snippe/operator review; do not guess success or delete the intent and charge again. Expired/cancelled sessions do not silently create a new session on the same order.

## Real TZS 500 acceptance check

1. Confirm merchant profile, gateway, worker and webhook configuration; use an actual existing active TZS 500 package (or configure a real package through the existing admin flow).
2. Join the intended MikroTik Wi-Fi. Ensure the captive-portal walled garden permits the portal and Snippe checkout/provider dependencies. The hosted redirect requires access before voucher activation; do not enable unrestricted internet as a shortcut.
3. Open the portal, choose the TZS 500 package, enter only a real payer's phone and press Pay. Confirm no name/email is requested, including on hosted checkout.
4. Confirm the portal stays open and shows the PIN instruction only after initiation is confirmed. Authorize once on the payer's phone. If initiation is unknown or fails, Continue to payment must open the existing hosted session; do not repeat the test if payment status is uncertain.
5. Return to the same browser/tab. Confirm it waits for signed webhook confirmation. Opening the return URL without paying must not unlock a voucher.
6. Confirm the merchant's Snippe dashboard contains the payment; local payment has that gateway/business, session reference and payment reference, status completed; order is paid then provisioning/completed.
7. Confirm only one voucher is created. Replay the same signed provider event via Snippe's supported replay mechanism and confirm no second provisioning dispatch/voucher.
8. Confirm router provisioning and first successful HotSpot login. Activation/expiry must begin at login, not payment. Confirm another device cannot take over the voucher.
9. Repeat with Business B in an isolated configured installation/account to verify funds arrive in B's merchant account. Do not switch a live installation's business merely to test.

Automated provider HTTP fixtures prove code behavior, not actual Snippe settlement, captive-browser return behavior, queue delivery or RouterOS hardware. Real payment and router checks require configured credentials and hardware. Queue operation still needs monitoring: after-commit dispatch avoids dispatch-before-commit but is not a transactional outbox guaranteeing delivery across every process crash.

## Implementation file inventory

Created:
- backend/database/migrations/2026_09_28_000001_add_business_payment_gateways.php
- backend/app/Models/Business.php
- backend/app/Models/PaymentGatewayAccount.php
- backend/app/Services/GatewayConfiguration.php
- backend/app/Services/SnippeSessionService.php
- backend/app/Http/Controllers/BusinessController.php
- backend/app/Console/Commands/ConfigureSnippeGateway.php
- backend/app/Console/Commands/RecoverSnippeSession.php
- docs/payments/snippe-hosted-checkout.md

Modified for this implementation:
- backend/app/Models/Order.php
- backend/app/Models/Payment.php
- backend/app/Services/SnippeClient.php
- backend/app/Http/Controllers/PublicPortalController.php
- backend/app/Http/Controllers/SnippeWebhookController.php
- backend/config/snippe.php
- backend/bootstrap/app.php
- backend/routes/api.php
- backend/tests/Feature/SnippeLivePaymentTest.php
- backend/tests/Feature/ResendPaymentPushTest.php
- backend/tests/Feature/ClickPesaPaymentTest.php
- frontend/src/views/PortalView.vue
- frontend/src/i18n/portalMessages.ts
- frontend/dist/index.html and generated JS/CSS bundles

Existing unrelated customer-layout and .refact changes were preserved. No changes were made to backend-overlay or MikroTik provisioning/session code for this refactor.
