# RJAY Hotspot Upgrade Patch

## Router 404 fix
The original Laravel command called `/rest/ip/hotspot/user/profile` immediately. The upgraded client now:

1. Adds `php artisan rjay:router-doctor` to test `/rest/system/resource` first.
2. Emits status-specific errors for 401/403/404.
3. Supports documented REST CRUD methods and POST `/print`, `/add`, `/set` fallbacks.
4. Uses local REST port `8081` instead of port 80 to avoid the HotSpot captive-portal HTTP path on the same router.
5. Documents that the RouterOS `rjay-api` password must match `MIKROTIK_PASSWORD` exactly.

## Frontend self-audit
Audited stack:
- Vue 3.5
- TypeScript
- Composition API with `<script setup>`
- Vue Router 4
- Bootstrap 5.3 + Bootstrap Icons
- Local component state; no Pinia

Build audit found pre-existing TypeScript parse failures in four admin views. They were rewritten cleanly and the final frontend passes `npm run build`.

## Premium voucher UI
Added:
- `frontend/src/components/vouchers/VoucherCard.vue`
- mobile-first tear-off ticket treatment
- dominant package/reward display
- 48px+ touch targets
- one-tap copy + animated `Copied!` state
- keyboard focus support
- reduced-motion support
- smooth terms/details transition
- one-device and first-login validity messaging

Integrated into:
- `frontend/src/views/PortalView.vue`
- `frontend/src/views/VouchersView.vue`

## Files modified
- `backend/app/Services/MikrotikRestClient.php`
- `backend/app/Console/Commands/BootstrapMikrotikProfiles.php`
- `backend/app/Console/Commands/RouterDoctor.php` (new)
- `backend/config/mikrotik.php`
- `backend/.env.rjay.example`
- `backend-overlay/.env.rjay.example`
- `frontend/src/components/vouchers/VoucherCard.vue` (new)
- `frontend/src/views/PortalView.vue`
- `frontend/src/views/VouchersView.vue`
- `frontend/src/views/DashboardView.vue`
- `frontend/src/views/PaymentsView.vue`
- `frontend/src/views/PlansView.vue`
- `frontend/src/views/SessionsView.vue`
- `frontend/src/style.css`
- `router/bootstrap-router.rsc`
- `setup.sh`
- `README.md`

## Validation performed
- Frontend: `npm run build` passed (`vue-tsc` + Vite production build).
- Backend: modified PHP files passed `php -l` syntax checks.
- Laravel application booted and `php artisan route:list` returned all API routes.
- `rjay:router-doctor` and `rjay:bootstrap-router` are registered Artisan commands.
- PHPUnit and migration execution could not run in the sandbox PHP runtime because its CLI lacks `dom`, `mbstring`, `xmlwriter`, and `pdo_sqlite`; this is an environment limitation, not a reported application assertion failure.
