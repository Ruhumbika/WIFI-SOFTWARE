# RJAY admin analytics, settlement, support and scheduler

Implementation adds payment filtering/analytics, validated settlement storage and support requests. It preserves charge initiation, signature verification, provisioning, binding, expiry and the HTTPS HotSpot login. No provider backfill command is implemented: the actual authenticated single-payment response containing settlement has not been verified.

## Database / financial semantics

Additive migrations:
- `2026_10_02_000001_add_payment_settlement.php`: nullable unsigned integer `gross_amount`, `fee_amount`, `net_amount`, nullable `settlement_currency`; `(status, completed_at)` and `(status, updated_at)` payment indexes; `(plan_id, id)` order index.
- `2026_10_02_000002_create_support_requests.php`: nullable existing entity relations, phone/device, reported connection state, allowlisted snapshot, lifecycle timestamps/admin, unique nullable open-request key and `(status, created_at)` index.

Only validated Snippe settlement populates settlement columns. Gross must match payment amount; each currency must match; values must be nonnegative integers; fee cannot exceed gross and net must equal gross minus fee. A conflicting later settlement is rejected. Signature, gateway/business checks and event idempotency still precede payment completion/provisioning. Missing settlement remains null. Historical rows are not updated by migrations.

Reporting uses completed payments and `completed_at` for sales/revenue, Tanzania UTC+03 date boundaries against UTC storage, and `updated_at` for other statuses. Financial sums are grouped by currency. `revenue_today` remains gross for existing consumers; `net_revenue_today` is confirmed net and nullable. APIs expose settled/missing counts; partial net is explicitly labelled. No provider payload is exposed.

## APIs

- `GET /api/admin/payments`: `date=all|today|yesterday|7d|30d|custom`, `from`, `to`, `status=all|completed|pending|failed|expired|voided`, `plan_id`, `search`, `page`, `per_page` (1–100). Search is literal substring across reference, external reference, phone and order number. Pagination links retain query parameters. Completed rows with missing completion time are not placed in an invented date bucket.
- `GET /api/admin/dashboard/analytics?period=today|7d|30d`: totals, trend buckets (hourly today, daily otherwise), package aggregates, status counts, Tanzania date range and currency. No browser-side full ledger aggregation. The visible dashboard/analytics refresh with read-only API requests every minute; they never invoke sync.
- `GET /api/admin/dashboard`: existing fields plus settlement coverage, net/fees/gross, active support count and scheduler/sync health.
- `POST /api/public/support`: anonymous phone/MAC/reported connection state only. Caller-provided entity IDs/URLs/payload/secrets are never persisted.
- `POST /api/public/orders/{uuid}/support`: existing order token required.
- `POST /api/public/vouchers/{uuid}/support`: existing voucher or owning order authorization required.
- `GET /api/admin/support?status=open|contacted|resolved|cancelled|all&page=...` and `PATCH /api/admin/support/{uuid}` with `status=contacted|resolved|cancelled`: existing admin authentication.

Open/contacted requests reuse one unique key per verified voucher/order, or anonymous phone/device/IP, within the installation business. Closing clears that key, permitting a later request. No automatic contact is sent. No PIN, token, provider payload or external URL is stored. Public creation is limited to five attempts/minute/IP. Reported device/state is not ownership proof or proof of router connectivity.

## Scheduler behavior

Exactly one scheduled `rjay:sync-hotspot` remains, once a minute with `withoutOverlapping`. Its before/success/failure callbacks track scheduled attempts and successes. Manual sync does not create scheduler heartbeats. A shared cache execution lock also excludes concurrent manual runs. Successful empty sync advances last successful sync. Router or individual operation failures log sanitized errors, return failure and retain the previous success timestamp. Binding/expiry rules are unchanged.

Dashboard separately shows current router REST reachability, last scheduler attempt, last successful session sync and failed/blocked last result. Scheduler is Healthy when an attempt is within three minutes, Delayed when older, and Not recorded without evidence. Healthy means the runner is attempting work; it does not guarantee router success. Shared persistent cache is required; process-local array cache cannot provide production locking/health. Execution locks expire after 24 hours, matching the existing scheduler overlap default. After a hard kill, a stale lock can block retries: first establish that no sync process is running before any operator-approved lock recovery.

Production facts supplied by the operator: backend `/var/www/WIFI-SOFTWARE/backend`, account `rjay`, one schedule definition and no current runner/cron/timer/process. Therefore no runner is installed by this implementation, and the existing development systemd unit must not be enabled on this VPS in parallel with cron.

## Review / deployment commands (NOT executed)

Use a coordinated release. Back up the production database with the established backup procedure and record the existing release SHA. Preserve APP_KEY and all credentials. Confirm the checkout is clean and on the intended branch; stop otherwise. Publish reviewed code through the normal release process before pulling it.

```bash
cd /var/www/WIFI-SOFTWARE
git status --short
git branch --show-current
git rev-parse HEAD
git pull --ff-only origin main
cd backend
sudo -u rjay /usr/bin/php -v
sudo -u rjay /usr/bin/php artisan migrate:status
```

`/usr/bin/php` must be executable by `rjay`, compatible with Laravel 12 / the composer lock (PHP 8.2 or newer), and have the extensions for the actual production database. Run `composer check-platform-reqs --no-dev` with that PHP version. Confirm production schema/engine/indexes against the migrations before applying them; only local SQLite metadata was inspected during implementation. New indexes can lock large MySQL tables during DDL. Resolve unrelated pending migrations separately.

```bash
sudo -u rjay /usr/bin/php artisan down
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
sudo -u rjay /usr/bin/php artisan migrate --path=database/migrations/2026_10_02_000001_add_payment_settlement.php --force
sudo -u rjay /usr/bin/php artisan migrate --path=database/migrations/2026_10_02_000002_create_support_requests.php --force
sudo -u rjay /usr/bin/php artisan config:cache
sudo -u rjay /usr/bin/php artisan queue:restart
cd ../frontend
npm ci
npm run build
cd ../backend
sudo -u rjay /usr/bin/php artisan up
```

Run build/dependency commands as the deploy account with the existing project ownership. Reload the verified production PHP service through its existing process manager if needed; no service name is assumed here. No `.env` additions are required. Ensure production cache/queue are persistent and configured as before. Serve frontend/dist through the existing web server.

## Install exactly ONE cron runner (NOT executed)

Verify `/usr/bin/php` and the production path before installing. The cron account requires read access to code/config and write access to backend storage/cache. Create the log without truncating an existing file:

```bash
sudo touch /var/log/rjay-scheduler.log
sudo chown rjay /var/log/rjay-scheduler.log
sudo chmod 0640 /var/log/rjay-scheduler.log
sudo -u rjay test -w /var/log/rjay-scheduler.log
sudo -u rjay /usr/bin/php /var/www/WIFI-SOFTWARE/backend/artisan schedule:list
```

The following installation command preserves unrelated crontab entries, refuses another scheduler entry, and is idempotent for this exact marked line. It requires Python 3 and crontab on the VPS. Run after review/deployment, not during implementation:

```bash
sudo -u rjay python3 - <<'PY'
import subprocess
line = '* * * * * cd /var/www/WIFI-SOFTWARE/backend && /usr/bin/php artisan schedule:run >> /var/log/rjay-scheduler.log 2>&1 # RJAY_WIFI_SCHEDULER'
result = subprocess.run(['crontab','-l'], capture_output=True, text=True)
if result.returncode and 'no crontab for' not in result.stderr.lower():
    raise SystemExit('Cannot safely read the existing crontab.')
existing = result.stdout if not result.returncode else ''
entries = [row for row in existing.splitlines() if row.strip() and not row.lstrip().startswith('#')]
if line in entries:
    if sum('artisan' in row and any(task in row for task in ['schedule:run','schedule:work','rjay:sync-hotspot']) for row in entries) != 1:
        raise SystemExit('Multiple scheduler entries require operator review.')
    raise SystemExit('The single intended cron entry is already installed.')
if any('artisan' in row and any(task in row for task in ['schedule:run','schedule:work','rjay:sync-hotspot']) for row in entries):
    raise SystemExit('Another scheduler entry exists; installation refused.')
subprocess.run(['crontab','-'], input=existing.rstrip('\n')+'\n'+line+'\n', text=True, check=True)
PY
```

Final cron entry:

```cron
* * * * * cd /var/www/WIFI-SOFTWARE/backend && /usr/bin/php artisan schedule:run >> /var/log/rjay-scheduler.log 2>&1 # RJAY_WIFI_SCHEDULER
```

Reconfirm no systemd/root/system-cron runner was added since the supplied production audit. Verify the cron daemon is running through the VPS's existing service manager. Arrange normal log rotation for this dedicated log, preserving ownership/write permission; do not leave it growing indefinitely.

Verification, after at least two minute boundaries:

```bash
sudo -u rjay crontab -l | rg 'RJAY_WIFI_SCHEDULER|artisan.*(schedule:run|schedule:work|rjay:sync-hotspot)'
sudo -u rjay /usr/bin/php /var/www/WIFI-SOFTWARE/backend/artisan schedule:list
sudo tail -n 30 /var/log/rjay-scheduler.log
```

The crontab output must contain one runner and schedule:list one sync definition. Check the authenticated dashboard: last scheduler attempt advances each minute; an empty successful sync must advance last successful sync. If the router is offline, scheduler attempts still advance but last success stays unchanged and failure is visible. Do not manually sync as evidence that cron works.

## Rollback

Stop/review the release if migration or validation fails. Restore the recorded prior application release in a clean production checkout and rebuild its frontend using the previous commands. Do not reset/overwrite dirty work. Retain additive tables/columns and support/settlement data; do not run blanket `migrate:rollback`. Removing those data-bearing fields requires a separate reviewed backup/data plan.

If the new runner needs removal, this command removes only the marked runner and preserves other cron entries:

```bash
sudo -u rjay python3 - <<'PY'
import subprocess
result = subprocess.run(['crontab','-l'], capture_output=True, text=True)
if result.returncode:
    raise SystemExit('Cannot safely read the existing crontab; nothing changed.')
lines = [row for row in result.stdout.splitlines() if not row.rstrip().endswith('# RJAY_WIFI_SCHEDULER')]
subprocess.run(['crontab','-'], input='\n'.join(lines)+'\n', text=True, check=True)
PY
```

Code rollback (replace the placeholder with the SHA recorded before deployment, only in a clean production checkout):

```bash
cd /var/www/WIFI-SOFTWARE
git status --short
git switch --detach <PRE_DEPLOY_SHA>
cd backend
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
sudo -u rjay /usr/bin/php artisan config:cache
sudo -u rjay /usr/bin/php artisan queue:restart
cd ../frontend
npm ci
npm run build
```

Re-enable the established working scheduler only after the rollback is validated. Removing cron returns production to manual-only synchronization; it is not a long-term healthy state. No production migration, deploy, cron or router change was executed during implementation.

## Verification and changed files

Targeted backend: 69 passed, 773 assertions (`AdminAnalyticsSupportTest`, `SnippeLivePaymentTest`, `VoucherRecoveryTest`, `AdminPackagesAndLogsTest`). Full backend: 131 passed, one pre-existing failure, 1060 assertions. The failure is `RouterSetupTest` line 56: it expects the portal origin and `?mac=` contiguous in downloaded HTML; the pre-existing router redirect now concatenates them in JavaScript. The router file and that test were preserved. Frontend: 25 individual tests passed across three files. Typecheck/production Vite build passed with output isolated under `/tmp`; PHP syntax and `git diff --check` passed. Tests use in-memory SQLite, provider/router fakes, active-handler VM execution and actual Vue SSR templates. Production MySQL migration/queries, real browser responsive behavior, provider settlement delivery and VPS cron operation remain unverified.

Changed/added by this task (existing manual login edits within the portal files/tests were retained):

Backend:
- `app/Console/Commands/SyncHotspotSessions.php`
- `app/Http/Controllers/AdminController.php`
- `app/Http/Controllers/SnippeWebhookController.php`
- `app/Http/Controllers/SupportRequestController.php` (new)
- `app/Models/Payment.php`
- `app/Models/SupportRequest.php` (new)
- `app/Providers/AppServiceProvider.php`
- `app/Services/HotspotSyncHealth.php` (new)
- `app/Services/PaymentReportingService.php` (new)
- `app/Services/PaymentSettlementService.php` (new)
- `routes/api.php`
- `routes/console.php`
- both migrations listed above (new)
- `tests/Feature/AdminAnalyticsSupportTest.php` (new)
- `tests/Feature/VoucherRecoveryTest.php` (existing disconnect-failure assertion now expects sync failure)

Frontend:
- `src/components/AdminShell.vue`
- `src/components/admin/DashboardAnalytics.vue` (new)
- `src/components/portal/SupportAction.vue` (new)
- `src/components/portal/VoucherAccessPanel.vue`
- `src/i18n/portalMessages.ts`
- `src/router.ts`
- `src/utils/paymentReporting.ts` (new)
- `src/views/AdminMoreView.vue`
- `src/views/DashboardView.vue`
- `src/views/PaymentsView.vue`
- `src/views/PortalView.vue`
- `src/views/SupportView.vue` (new)
- `tests/admin-analytics-support.test.mjs` (new)
- `tests/hotspot-login.test.mjs` (support template test stub/context only for this task)

Documentation: this file.

No edits to SnippeClient, HotSpot login helper, router login HTML, credentials or deployment service files were made by this task. No live migration/backfill, production data write, commit/push or deployment was executed.
