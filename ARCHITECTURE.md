# RJAY Hotspot MVP Architecture

## Frozen MVP decisions
- Router: MikroTik hAP ac lite, RouterOS 7.20.4.
- Existing HotSpot remains untouched: `hotspot1` on `bridge-lan`, gateway `10.10.1.1`.
- New application-owned MikroTik profiles use `RJAY_` prefix.
- Voucher is limited to one device: profile `shared-users=1` plus first-device MAC binding.
- Voucher validity begins at the first successful HotSpot login.
- Application records wall-clock `expires_at`; scheduler disables expired users.
- MikroTik `limit-uptime` is a router-side fallback limit.
- Snippe integration supports `mock` now and `live` later through environment variables.
- Production payment provisioning is idempotent: one paid order produces one voucher.

## Core flow
Customer -> MikroTik captive portal -> Vue portal -> Laravel -> Snippe -> webhook -> Laravel -> MikroTik REST -> voucher -> HotSpot login.

## Failure behavior
- Payment succeeds but router is unreachable: order remains `provisioning`, voucher becomes `provision_pending`, money is NOT marked failed.
- Duplicate Snippe webhook: ignored using unique event id.
- Duplicate payment retry: same Snippe Idempotency-Key is reused.
- Second device attempts voucher: MikroTik MAC binding rejects it; active mismatched session is disconnected during sync.

## State machines
Payment: `pending -> completed | failed | voided | expired`

Order: `pending_payment -> paid -> provisioning -> completed`

Voucher: `provision_pending -> ready -> active -> expired`, with `revoked` reserved for admin action.
