# RJAY MikroTik Hotspot Billing & Voucher MVP

This is the mobile-first MVP for the supplied MikroTik hAP ac lite / RouterOS 7.20.4 setup.

## Stack audited
- Frontend: Vue 3.5 + TypeScript + Composition API (`<script setup>`)
- Routing: Vue Router 4
- UI: Bootstrap 5.3 + Bootstrap Icons + project CSS
- State: component-local reactive state; admin auth token in `localStorage` (no Pinia yet)
- Backend: Laravel 12
- Payment: Snippe Mobile Money API with signed webhooks
- Router: RouterOS REST API

## Implemented
- Mobile-first customer portal.
- Premium reusable voucher ticket with one-tap copy, copied animation, accessible 48px+ targets, tear-off styling and collapsible terms.
- Responsive admin voucher cards on mobile; desktop table preserved.
- Admin login/dashboard, packages, vouchers, payments and sessions.
- Manual batch vouchers.
- MikroTik RouterOS REST client with CRUD + documented POST command fallbacks.
- `rjay:router-doctor` preflight command.
- Application-owned `RJAY_` profiles; legacy router profiles are not overwritten.
- One-device enforcement using `shared-users=1` plus first-device MAC binding.
- Validity starts at first successful HotSpot login; app records wall-clock expiry.
- Snippe idempotency + signed webhook verification.

## 1. Existing install: apply updated source
Install PHP/JS dependencies only if they are not already present:

```bash
cd backend
composer install
cd ../frontend
npm install
```

## 2. Fix MikroTik REST before provisioning
The admin **Router** page guides a customer through this setup: enable RouterOS REST once in WinBox, enter the LAN REST URL and dedicated API credentials, test the connection, save the verified settings, then prepare package profiles. The dashboard does not display the saved password. The commands below remain available for a network technician.

Your HotSpot is on `10.10.1.1` and normal HTTP port 80 is part of the captive-portal path. RJAY uses **port 8081** for the temporary local REST/WebFig service to avoid that collision. Your HotSpot profile already uses `8080` for its proxy, so do not use 8080 for REST.

On RouterOS, set the existing API user's password explicitly. The password must exactly match `MIKROTIK_PASSWORD` in Laravel:

```routeros
/user group set [find where name="rjay-api-group"] policy=read,write,rest-api
/user set [find where name="rjay-api"] group=rjay-api-group address=10.10.1.0/24 password="YOUR_STRONG_PASSWORD" disabled=no
/ip service set www disabled=no address=10.10.1.0/24 port=8081
```

From Ubuntu, test RouterOS itself before testing Laravel:

```bash
curl -i -u 'rjay-api:YOUR_STRONG_PASSWORD' \
  http://10.10.1.1:8081/rest/system/resource
```

Expected result: `HTTP/1.1 200 OK` and JSON containing RouterOS version/board information.

Then test the exact HotSpot resource:

```bash
curl -i -u 'rjay-api:YOUR_STRONG_PASSWORD' \
  http://10.10.1.1:8081/rest/ip/hotspot/user/profile
```

Edit `backend/.env`:

```env
MIKROTIK_BASE_URL=http://10.10.1.1:8081/rest
MIKROTIK_USERNAME=rjay-api
MIKROTIK_PASSWORD=YOUR_STRONG_PASSWORD
MIKROTIK_VERIFY_TLS=false
MIKROTIK_HOTSPOT_SERVER=hotspot1
MIKROTIK_ADDRESS_POOL=dhcp-pool
```

Clear cached Laravel config after editing `.env`:

```bash
cd backend
php artisan optimize:clear
```

Run the diagnostic first:

```bash
php artisan rjay:router-doctor
```

Only after all checks pass:

```bash
php artisan rjay:bootstrap-router
```

## 3. Start the MVP
Start the backend, queue worker, and frontend in separate terminals:

```bash
cd backend && php artisan serve --host=0.0.0.0 --port=8000
```

```bash
cd backend && php artisan queue:work
```

```bash
cd frontend && npm run dev
```

Session recording needs the Laravel scheduler. On Linux, install its persistent user service from the project root:

```bash
systemctl --user link "$PWD/deploy/rjay-hotspot-scheduler.service"
systemctl --user enable --now rjay-hotspot-scheduler.service
loginctl enable-linger "$USER"
systemctl --user status rjay-hotspot-scheduler.service
```

The service file contains this checkout's absolute path. Adjust its `WorkingDirectory` if the project moves. For a temporary development session, run `cd backend && php artisan schedule:work` in another terminal instead. The admin Errors page shows only error summaries; Laravel stores detailed errors in daily logs for 14 days.

Admin URL: `http://127.0.0.1:5174/admin/login`

Set `ADMIN_EMAIL` and a unique `ADMIN_PASSWORD` in `backend/.env` before the first setup. Existing admin passwords are not reset when setup is run again.

## 4. Seed packages
| Package | Price | Validity | Rate |
|---|---:|---:|---:|
| Chap Chap | 500 TZS | 1 hour | 2M/2M |
| 9 Hours | 1,000 TZS | 9 hours | 4M/4M |
| Boom 1 Day | 2,000 TZS | 24 hours | 4M/4M |
| Boom 3 Days | 4,000 TZS | 3 days | 4M/4M |
| Boom 7 Days | 7,000 TZS | 7 days | 4M/4M |

## 5. Snippe payments
Configure the backend with an active Snippe account and a public HTTPS webhook URL before accepting purchases:

```env
SNIPPE_API_KEY=...
SNIPPE_WEBHOOK_SECRET=...
SNIPPE_WEBHOOK_URL=https://YOUR-PUBLIC-DOMAIN/api/webhooks/snippe
```

Customers enter their mobile number, full name and email. Snippe sends a Mobile Money prompt; a signed `payment.completed` webhook confirms the amount before Laravel provisions a voucher. Missing configuration prevents payment requests. Never place Snippe or MikroTik secrets in Vue or Git.

## 6. Voucher UI integration
Reusable component:

```text
frontend/src/components/vouchers/VoucherCard.vue
```

Used by:

```text
frontend/src/views/PortalView.vue
frontend/src/views/VouchersView.vue
```

The customer sees the ticket immediately after successful provisioning. Admin users get the same card layout on mobile, while the desktop table remains available.

## 7. Production hardening
- Move RouterOS REST from temporary LAN HTTP to `www-ssl`/HTTPS or a protected management tunnel.
- Put Laravel behind Nginx/HTTPS.
- Switch SQLite to MySQL/PostgreSQL.
- Set `APP_DEBUG=false`.
- Use a public HTTPS Snippe webhook.
- Rotate default admin credentials.
- Add backups, monitoring and audit retention.
