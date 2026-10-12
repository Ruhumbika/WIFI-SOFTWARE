# Central payment API for consuming applications

Existing businesses and encrypted payment_gateway_accounts remain authoritative. This change adds application identities and reuses payments/payment_events. External payments have no WiFi order or voucher; WiFi reporting continues to include its order-backed payments only.

Each payment_consumer has a hashed application token, fixed business_id, installation code, and approved HTTPS return origin. The caller supplies X-Business-Code matching that token's business. Merchant keys, webhook secrets and profile selection are never accepted or returned by the integration API.

## Deployment after merging this branch

From the existing Laravel repository, deploy through GitHub and keep the existing APP_KEY. Gateway credentials and customer phones use that key for encrypted database storage.

```bash
cd /var/www/WIFI-SOFTWARE
git pull --ff-only origin main
cd backend
php artisan migrate --force
php artisan optimize:clear
php artisan payments:register-consumer RJAY_BARBERSHOP barbershop https://rjaybarber.shop
php artisan snippe:configure RJAY_BARBERSHOP
```

The first command prompts privately for an application service token. Generate it on the Barbershop server and copy it into that hidden prompt. It is separate from merchant configuration. The second command privately prompts for the centrally stored Snippe credentials and the Barbershop profile. Use the existing gateway UUID with --gateway if one is already configured. Leave WiFi's gateway/profile untouched.

Set the Barbershop Snippe profile collect_email=false, mobile_money only. GatewayConfiguration generates its own /api/webhooks/snippe/{gatewayUuid} URL from the central service's existing HTTPS webhook base. Confirm that exact URL is reachable in Snippe before enabling Barbershop payments.

## API contract

All endpoints are under /api/integrations/payments and require Authorization: Bearer <application token> and X-Business-Code. Configuration returns readiness flags only. GET /{paymentUuid} is restricted to that installation's payments.

POST fields: source_reference, attempt (starts at 1), amount (integer TZS, at least 500), currency=TZS, phone, kind=push|checkout, return_path. Return paths must be relative to the centrally approved origin; they are owned by the calling application. Profile, gateway and business selection fields are rejected.

A repeated source_reference+attempt returns the same durable intent. Changed retry data returns 409. Unknown network outcomes never submit another checkout or push automatically. A retry after a signed failure first verifies/closes the old hosted session; active or completed previous sessions block a new charge.

Hosted QR uses kind=checkout. It encodes a profile-backed checkout URL, rather than a bank/TIPS QR. The customer supplies the mobile number on that hosted checkout. kind=push submits the application-supplied phone server-side once. A provider payment_token proves the prompt was sent; it is discarded, never returned.

The gateway webhook checks HMAC/timestamp, gateway, amount, currency and signed source metadata. Payment completion is monotonic and webhook events are deduplicated. This API does not confirm bookings, settle invoices or issue vouchers for external applications.

## Recovery and verification

After an ambiguous session-creation timeout, find the session in the correct merchant dashboard using payment UUID/source reference. Link it without a new charge:

```bash
php artisan payments:recover-session PAYMENT_UUID SESSION_REFERENCE
```

Replay the provider's signed completion webhook if necessary. Neither redirects nor the recovery command mark a payment paid.

Run the new feature tests together with existing Snippe, admin reporting/permissions and resend tests. The repository lockfile requires PHP >=8.4.1; use its actual dependencies in CI. Local validation also runs on PHP 8.3 with an isolated compatible dependency resolution, without changing the application lockfile.

```bash
php artisan test --filter='ConsumerPaymentTest|SnippeLivePaymentTest|AdminAnalyticsSupportTest|AdminPermissionsTest|ResendPaymentPushTest'
```

Only enable live collection after testing the Barbershop profile with one authorized payment and checking: one phone prompt, the correct merchant, a verified central payment, and one confirmed Barbershop booking. A configured flag alone does not prove live provider success.
