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
        $host = parse_url((string) config('mikrotik.base_url'), PHP_URL_HOST);
        $trusted = $loginUrl && $host && strcasecmp((string) parse_url($loginUrl, PHP_URL_HOST), (string) $host) === 0
            && in_array(parse_url($loginUrl, PHP_URL_SCHEME), ['http', 'https'], true)
            && in_array(parse_url($loginUrl, PHP_URL_PORT), [null, 80, 443], true)
            && parse_url($loginUrl, PHP_URL_PATH) === '/login'
            && ! parse_url($loginUrl, PHP_URL_USER) && ! parse_url($loginUrl, PHP_URL_QUERY) && ! parse_url($loginUrl, PHP_URL_FRAGMENT);

        return ['state' => $v->activated_at ? 'active' : 'ready', 'voucher_status' => $v->status, 'login_url' => $trusted ? $loginUrl : null];
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
