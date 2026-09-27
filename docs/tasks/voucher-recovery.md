# RJAY WiFi voucher access and recovery

Implemented in the existing WIFI-SOFTWARE checkout. The customer layout follows the requested compact mobile reference: voucher entry and My Vouchers share a raised card, with real plan cards immediately below and payment phone entry in a centered modal with a dimmed backdrop. Blue/cyan eye icons and moving signal paths complement neutral raised buttons; reduced-motion preferences stop the animation.

## Behavior and security

- Purchases retain the existing ClickPesa/Snippe flow and encrypted X-Order-Token. Payment success is independent of router availability.
- Purchase MAC remains order context. New vouchers provision without permanent MAC binding. The session synchronizer binds the first observed successful login and initializes missing activation/expiry dates. Reconnects and controlled transfers preserve those dates.
- Redemption compares the supplied PIN against the existing encrypted voucher secret. Its short-lived access token permits voucher display and connection, but not transfer or compromise reports.
- Phone lookup returns paginated, masked summaries. Recovery requires a selected voucher UUID, its registered phone and its own recovery PIN. One voucher never unlocks another voucher, even with the same phone.
- Recovery tokens expire after 15 minutes, include voucher ID, phone, purpose and version, and are kept in sessionStorage. Order-token ownership remains accepted for its own voucher. A browser-supplied MAC is never authentication.
- Recovery PINs are generated once under a row lock, differ from the login PIN, and are stored only as hashes. The issuance response is not cacheable. If the response is lost, it cannot be replayed; an administrator must explicitly reset recovery access. Old vouchers are not silently assigned PINs.
- Named endpoint limits separate lookup, verification, connection polling and support requests. Verification also has a ten-minute phone/client limit and a phone-wide limit. Configure a persistent shared Laravel cache in multi-worker deployments.
- Anonymous vouchers remain supported. A credential-authorized claim links an unregistered voucher once. Registered manual creation returns recovery PINs once. Existing manual generation still does not fabricate orders or cash transactions.
- Transfers require administrator approval. Credential rotation replaces the existing router user's PIN, preserves code/record/uptime/data limits and invalidates earlier voucher access tokens. No customers table or arbitrary router command endpoint is introduced.
- Router release/rotation uses a durable operation record and idempotency key. It disables the user, confirms disable, disconnects sessions, removes cookies, updates binding or PIN, checks the result, then restores the appropriate enabled state. Local completion follows router confirmation. On uncertainty the operation stays pending_reconciliation. Retry from Voucher Details; do not generate a new voucher or reset payment/validity.
- Pending rotation targets are encrypted login secrets, hidden from API serialization, and cleared after completion. Recovery PIN plaintext is never persisted.
- Security events use allowlisted types and store no credentials or payment payloads. Repeated mismatch reports are deduplicated for five minutes.

## Migration

`backend/database/migrations/2026_09_27_000001_add_voucher_recovery_and_events.php`

Adds recovery hash/timestamps/version, transfer_count, compromised_at, claimed_at and a phone index to vouchers. Creates voucher_events, voucher_device_transfer_requests and voucher_device_operations. Existing migration files are unchanged. Rollback was tested only in an isolated in-memory database; production rollback should retain recovery and audit data.

## Backend files

Changed:

- app/Console/Commands/SyncHotspotSessions.php
- app/Http/Controllers/AdminController.php
- app/Http/Controllers/PublicPortalController.php
- app/Jobs/ProvisionPaidOrder.php
- app/Models/Voucher.php
- app/Providers/AppServiceProvider.php
- app/Services/MikrotikRestClient.php
- app/Services/VoucherProvisioner.php
- bootstrap/app.php
- routes/api.php

Added:

- app/Http/Controllers/PublicVoucherController.php
- app/Models/VoucherDeviceOperation.php
- app/Services/PhoneNormalizer.php
- app/Services/VoucherAccessService.php
- app/Services/VoucherConnectionService.php
- app/Services/VoucherDeviceService.php
- app/Services/VoucherEvents.php
- app/Services/VoucherRecoveryService.php
- tests/Feature/VoucherRecoveryTest.php
- tests/Feature/VoucherRouterOperationsTest.php
- The additive migration listed above.

## Frontend files

Changed: src/api.ts, src/views/PortalView.vue, src/views/VoucherDetailView.vue, src/views/VouchersView.vue.

Added: src/components/portal/VoucherAccessPanel.vue, src/components/portal/SignalEye.vue, src/utils/hotspotLogin.ts.

Existing VoucherCard.vue is reused without replacement. No UI dependencies or global styles were added. Tracked frontend/dist files are regenerated by the production build.

## API additions

Public, under `/api/public/vouchers`:

| Method | Path | Access |
| --- | --- | --- |
| POST | /redeem | Code + login PIN; returns voucher-scoped connection access |
| POST | /recovery/lookup | Phone; masked summaries only |
| POST | /recovery/verify | Phone + voucher_uuid + recovery_pin |
| GET | /mine?voucher_uuid=... | Recovery token or that voucher's order token; returns one voucher |
| GET | /{uuid} | Voucher access or order token |
| POST | /{uuid}/recovery-pin | Owning X-Order-Token; once only |
| POST | /{uuid}/prepare-connection | Voucher access or order token |
| GET | /{uuid}/connection | Voucher access or order token |
| POST | /{uuid}/device-transfer-request | Recovery or owning order token |
| POST | /{uuid}/report-compromised | Recovery or owning order token |
| POST | /claim | Code + login PIN + phone; only unregistered vouchers |

Voucher tokens use `X-Voucher-Recovery-Token`. They are not query parameters. All previous public order routes remain registered.

Admin, under existing `admin.token` middleware and `/api/admin/vouchers/{id}`:

- GET /device-events
- POST /release-device
- POST /transfer/approve
- POST /transfer/reject
- POST /rotate-credentials
- POST /recovery-pin (explicit reset flag for resetting an issued PIN)

Release/approve/rotate take `request_key` as a UUID. Approve/reject take `transfer_request_id`. Operation responses distinguish completed from pending_reconciliation. Existing generate accepts an optional phone; omitted phone preserves anonymous batches.

## Verification

- PHP 8.5.4: `php artisan test --compact`: 88 tests, 449 assertions passed.
- `npm run build`: Vue TypeScript checking and Vite production build passed.
- Public voucher routes inspected with route:list.
- Chrome mobile review used the actual local public plans API: five real plans. At 320, 390 and 768 pixel widths there was no horizontal overflow. At 844 pixel height, all five plan cards fit below voucher entry. Recovery tab kept plans visible. The revised payment popup was verified as a native modal, centered on mobile and at 933px desktop width, with phone-input focus and Escape dismissal. Reduced motion disabled signal animation. No JavaScript exceptions were observed.
- Router operations were verified with HTTP fakes, not real router mutations. Existing payment regression tests use provider fakes. No real payment, live transfer/rotation, MySQL concurrency test, production migration or deployment was performed.

## Deployment

Run from the actual deployed checkout during a coordinated release. Back up the database and retain the existing APP_KEY: stored voucher secrets and order/recovery tokens depend on it. Keep the existing environment credentials and services.

```sh
cd backend
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan optimize:clear
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan schedule:interrupt
cd ../frontend
npm ci
npm run build
```

Run the additive migration before exposing the new PHP/frontend code. Avoid old and new workers running concurrently across the cutover. `queue:restart` requests a graceful restart; the deployed process manager must restart workers. `schedule:interrupt` handles scheduler runs started through schedule:run.

This checkout supplies a user-level schedule:work unit, `deploy/rjay-hotspot-scheduler.service`. Its WorkingDirectory currently points to this checkout; adjust it for a different deployment. If this unit manages the deployed scheduler:

```sh
systemctl --user restart rjay-hotspot-scheduler.service
systemctl --user status rjay-hotspot-scheduler.service --no-pager
```

There is no versioned Nginx/PHP-FPM/Supervisor production configuration here. Only if these service names match the actual host:

```sh
sudo systemctl reload php8.5-fpm
sudo systemctl reload nginx
```

Do not assume a `rjay-worker:*` Supervisor group exists. Use the deployed worker manager; queue:restart above is framework-supported. Serve the generated frontend/dist through the existing web-server configuration.

## MikroTik and operational checks

No router bootstrap, profile recreation or manual RouterOS configuration change was executed or is required by this code change. Keep the existing REST connection, shared-users=1 and the captive portal mac/link-login-only/link-orig context. The REST account needs the existing read/write capabilities for HotSpot users, active sessions and cookies. Rotation confirmation needs the REST response to include the updated user password; if RouterOS withholds it, the operation remains pending rather than claiming success.

Keep the scheduler running: first-login observation/binding and expiry enforcement depend on it. Existing premature bindings are not bulk-cleared; an operator can review and release an affected voucher through the controlled action.

Before production acceptance, verify a real payment on phone A with Wi-Fi device B, a manual voucher login, first-login binding, same-device reconnect, rejected second-device use, owner recovery, approved transfer, PIN rotation and a router outage/retry. Confirm activated_at/expires_at, payment history and remaining router usage do not reset.

Application rollback: restore the prior application build using the normal release process while retaining additive tables and columns. Never migrate:fresh or drop recovery/audit data as a rollback shortcut. Finish or deliberately reconcile pending router operations before reverting the code that understands them.
