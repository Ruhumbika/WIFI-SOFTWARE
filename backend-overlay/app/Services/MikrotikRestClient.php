<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Voucher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MikrotikRestClient
{
    private function http(): PendingRequest
    {
        $password = (string) config('mikrotik.password');
        if ($password === '') {
            throw new RuntimeException('MIKROTIK_PASSWORD is not configured.');
        }

        $client = Http::acceptJson()
            ->withBasicAuth((string) config('mikrotik.username'), $password)
            ->timeout(12);

        if (!config('mikrotik.verify_tls')) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('mikrotik.base_url'), '/').'/'.ltrim($path, '/');
    }

    public function resource(): array
    {
        return $this->http()->get($this->url('system/resource'))->throw()->json();
    }

    public function activeSessions(): array
    {
        return $this->http()->get($this->url('ip/hotspot/active'))->throw()->json() ?? [];
    }

    public function hotspotUsers(): array
    {
        return $this->http()->get($this->url('ip/hotspot/user'))->throw()->json() ?? [];
    }

    public function profiles(): array
    {
        return $this->http()->get($this->url('ip/hotspot/user/profile'))->throw()->json() ?? [];
    }

    public function ensureProfile(Plan $plan): array
    {
        $name = $plan->mikrotik_profile_name;
        $existing = collect($this->profiles())->firstWhere('name', $name);
        $payload = [
            'name' => $name,
            'shared-users' => '1',
            'add-mac-cookie' => 'yes',
            'session-timeout' => $this->routerTime($plan->duration_seconds),
            'mac-cookie-timeout' => $this->routerTime($plan->duration_seconds),
            'rate-limit' => $plan->rate_limit,
        ];

        if (config('mikrotik.address_pool')) {
            $payload['address-pool'] = config('mikrotik.address_pool');
        }

        if ($existing && isset($existing['.id'])) {
            return $this->http()->patch($this->url('ip/hotspot/user/profile/'.rawurlencode($existing['.id'])), $payload)->throw()->json() ?? $existing;
        }

        return $this->http()->put($this->url('ip/hotspot/user/profile'), $payload)->throw()->json() ?? [];
    }

    public function createVoucherUser(Voucher $voucher): array
    {
        $voucher->loadMissing('plan');
        $this->ensureProfile($voucher->plan);

        $payload = [
            'name' => $voucher->code,
            'password' => $voucher->secret,
            'profile' => $voucher->plan->mikrotik_profile_name,
            'server' => (string) config('mikrotik.hotspot_server'),
            'limit-uptime' => $this->routerTime($voucher->plan->duration_seconds),
            'comment' => 'RJAY voucher '.$voucher->uuid,
            'disabled' => 'no',
        ];

        if ($voucher->plan->data_limit_bytes) {
            $payload['limit-bytes-total'] = (string) $voucher->plan->data_limit_bytes;
        }
        if ($voucher->device_mac) {
            $payload['mac-address'] = strtoupper($voucher->device_mac);
        }

        // Provisioning is idempotent: retries update the existing username instead of duplicating it.
        $existing = collect($this->hotspotUsers())->firstWhere('name', $voucher->code);
        if ($existing && isset($existing['.id'])) {
            $this->http()->patch(
                $this->url('ip/hotspot/user/'.rawurlencode($existing['.id'])),
                $payload
            )->throw();
            return array_merge($existing, ['.id' => $existing['.id']]);
        }

        return $this->http()->put($this->url('ip/hotspot/user'), $payload)->throw()->json() ?? [];
    }

    public function bindMac(Voucher $voucher, string $mac): void
    {
        if (!$voucher->mikrotik_id) return;
        $this->http()->patch(
            $this->url('ip/hotspot/user/'.rawurlencode($voucher->mikrotik_id)),
            ['mac-address' => strtoupper($mac)]
        )->throw();
    }

    public function disableVoucher(Voucher $voucher): void
    {
        if (!$voucher->mikrotik_id) return;
        $this->http()->patch(
            $this->url('ip/hotspot/user/'.rawurlencode($voucher->mikrotik_id)),
            ['disabled' => 'yes']
        )->throw();
    }

    public function disconnect(string $activeId): void
    {
        $this->http()->delete($this->url('ip/hotspot/active/'.rawurlencode($activeId)))->throw();
    }

    public function routerTime(int $seconds): string
    {
        if ($seconds % 604800 === 0) return ($seconds / 604800).'w';
        if ($seconds % 86400 === 0) return ($seconds / 86400).'d';
        if ($seconds % 3600 === 0) return ($seconds / 3600).'h';
        if ($seconds % 60 === 0) return ($seconds / 60).'m';
        return $seconds.'s';
    }
}
