<?php

namespace App\Services;

use App\Models\Voucher;
use App\Models\VoucherDeviceOperation;

final class VoucherConnectionService
{
    public function __construct(private VoucherProvisioner $provisioner, private MikrotikRestClient $router) {}

    public function state(Voucher $v, ?string $mac): ?string
    {
        if ($v->expires_at?->isPast() || $v->status === 'expired') {
            return 'expired';
        }
        if (in_array($v->status, ['disabled', 'revoked'], true)) {
            return 'unavailable';
        }
        if (VoucherDeviceOperation::where('voucher_id', $v->id)->where('state', '!=', 'completed')->exists()) {
            return 'preparing';
        }
        if ($mac && $v->device_mac && strtoupper($mac) !== strtoupper($v->device_mac)) {
            VoucherEvents::record($v, 'device_mismatch', strtoupper($mac));

            return 'device_mismatch';
        }

        return null;
    }

    public function prepare(Voucher $v, ?string $mac, ?string $loginUrl): array
    {
        if ($state = $this->state($v, $mac)) {
            return ['state' => $state];
        }
        if ($v->status === 'provision_pending') {
            $v = $this->provisioner->provision($v);
        }
        try {
            $this->router->resource();
        } catch (\Throwable) {
            return ['state' => 'router_unavailable'];
        }
        if ($v->status === 'provision_pending') {
            return ['state' => 'preparing'];
        }
        // Customer authentication targets are independent of the management REST address.
        $candidate = $loginUrl ?: config('mikrotik.hotspot_login_url');
        $parts = parse_url((string) $candidate);
        $allowed = array_map('strtolower', config('mikrotik.hotspot_allowed_hosts', []));
        $trusted = $parts && in_array(strtolower($parts['host'] ?? ''), $allowed, true)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && in_array($parts['port'] ?? null, [null, 80, 443], true)
            && ($parts['path'] ?? '') === '/login'
            && !array_key_exists('user', $parts) && !array_key_exists('pass', $parts)
            && !array_key_exists('query', $parts) && !array_key_exists('fragment', $parts);

        return ['state' => $v->activated_at ? 'active' : 'ready', 'voucher_status' => $v->status, 'login_url' => $trusted ? $candidate : null];
    }

    public function connection(Voucher $v, ?string $mac): array
    {
        if ($state = $this->state($v, $mac)) {
            return ['state' => $state];
        }
        try {
            $rows = collect($this->router->activeSessions())->filter(fn ($row) => ($row['user'] ?? null) === $v->code);
            $online = $mac && $rows->contains(fn ($row) => strtoupper($row['mac-address'] ?? '') === strtoupper($mac));

            return ['state' => $online ? 'online' : ($rows->isNotEmpty() ? ($mac ? 'active_other_device' : 'unknown') : 'offline')];
        } catch (\Throwable) {
            return ['state' => 'router_unavailable'];
        }
    }
}
