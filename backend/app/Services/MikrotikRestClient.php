<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Voucher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
            ->asJson()
            ->withBasicAuth((string) config('mikrotik.username'), $password)
            ->connectTimeout(5)
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

    private function decoded(Response $response, string $operation): array
    {
        if ($response->successful()) {
            $json = $response->json();
            return is_array($json) ? $json : [];
        }

        $body = trim((string) $response->body());
        $body = preg_replace('/\s+/', ' ', strip_tags($body)) ?: '';
        $body = mb_substr($body, 0, 280);

        $hint = match ($response->status()) {
            401 => 'Authentication failed. Make sure the RouterOS API user and password match the configured connection.',
            403 => 'RouterOS accepted the login but denied the operation. The account needs read,write,rest-api policies.',
            404 => 'The REST resource was not found. First verify GET /rest/system/resource with curl; if that also returns 404, the problem is RouterOS REST access/base URL rather than the HotSpot profile path.',
            default => 'Check RouterOS REST access and the response detail.',
        };

        throw new RuntimeException(sprintf(
            '%s failed: HTTP %d. %s Response: %s',
            $operation,
            $response->status(),
            $hint,
            $body !== '' ? $body : '[empty]'
        ));
    }

    private function readMenu(string $path): array
    {
        $response = $this->http()->get($this->url($path));

        // POST /print is a documented universal REST command and provides
        // compatibility with RouterOS builds where direct CRUD routing is picky.
        if ($response->status() === 404) {
            $response = $this->http()->post($this->url($path.'/print'), []);
        }

        return $this->decoded($response, 'Read '.$path);
    }

    private function createMenu(string $path, array $payload): array
    {
        $response = $this->http()->put($this->url($path), $payload);

        if ($response->status() === 404) {
            $response = $this->http()->post($this->url($path.'/add'), $payload);
        }

        return $this->decoded($response, 'Create '.$path);
    }

    private function updateMenu(string $path, string $id, array $payload): array
    {
        $response = $this->http()->patch(
            $this->url($path.'/'.rawurlencode($id)),
            $payload
        );

        if ($response->status() === 404) {
            $response = $this->http()->post(
                $this->url($path.'/set'),
                array_merge(['.id' => $id], $payload)
            );
        }

        return $this->decoded($response, 'Update '.$path.' '.$id);
    }

    public function menu(string $path): array
    {
        return $this->readMenu($path);
    }

    public function managementServices(): array
    {
        return $this->readMenu('ip/service');
    }

    public function addMenu(string $path, array $payload): array
    {
        return $this->createMenu($path, $payload);
    }

    public function setMenu(string $path, string $id, array $payload): array
    {
        return $this->updateMenu($path, $id, $payload);
    }

    public function setCustomerWifiSsid(string $driver, string $id, string $ssid): void
    {
        [$path, $field] = match ($driver) {
            'legacy' => ['interface/wireless', 'ssid'],
            'wifi' => ['interface/wifi', 'configuration.ssid'],
            default => throw new \InvalidArgumentException('Unsupported Wi-Fi driver.'),
        };
        $this->decoded(
            $this->http()->post($this->url($path.'/set'), ['.id' => $id, $field => $ssid]),
            'Set customer Wi-Fi name'
        );
    }

    public function removeMenu(string $path, string $id): void
    {
        $response = $this->http()->delete($this->url($path.'/'.rawurlencode($id)));
        if ($response->status() === 404) {
            $response = $this->http()->post($this->url($path.'/remove'), ['.id' => $id]);
        }
        $this->decoded($response, 'Remove '.$path.' '.$id);
    }

    public function pingInternet(): bool
    {
        $result = $this->decoded(
            $this->http()->post($this->url('ping'), ['address' => '1.1.1.1', 'count' => '2']),
            'Test internet connectivity'
        );
        return collect($result)->contains(fn ($row) => is_array($row) && (int) ($row['received'] ?? 0) > 0);
    }

    public function resource(): array
    {
        return $this->decoded(
            $this->http()->get($this->url('system/resource')),
            'Read system/resource'
        );
    }

    public function activeSessions(): array
    {
        return $this->readMenu('ip/hotspot/active');
    }

    public function identity(): array
    {
        $result = $this->readMenu('system/identity');
        return isset($result['name']) ? $result : ($result[0] ?? []);
    }

    public function setIdentity(string $name): array
    {
        $this->decoded(
            $this->http()->post($this->url('system/identity/set'), ['name' => $name]),
            'Set system/identity'
        );
        return $this->identity();
    }

    public function hotspotServers(): array
    {
        return $this->readMenu('ip/hotspot');
    }

    public function addressPools(): array
    {
        return $this->readMenu('ip/pool');
    }

    public function hotspotUsers(): array
    {
        return $this->readMenu('ip/hotspot/user');
    }

    public function hotspotCookies(): array
    {
        return $this->readMenu('ip/hotspot/cookie');
    }

    public function removeVoucherCookies(Voucher $voucher): void
    {
        foreach ($this->hotspotCookies() as $cookie) {
            if (($cookie['user'] ?? null) !== $voucher->code || !isset($cookie['.id'])) continue;
            $response = $this->http()->delete($this->url('ip/hotspot/cookie/'.rawurlencode($cookie['.id'])));
            if (in_array($response->status(), [404, 500], true)) {
                $response = $this->http()->post($this->url('ip/hotspot/cookie/remove'), ['.id' => $cookie['.id']]);
            }
            $this->decoded($response, 'Remove HotSpot cookie');
        }
    }

    public function profiles(): array
    {
        return $this->readMenu('ip/hotspot/user/profile');
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
            // RouterOS rejects percent-encoded profile IDs on this REST path; use the console set command.
            $this->decoded(
                $this->http()->post($this->url('ip/hotspot/user/profile/set'), array_merge(['.id' => $existing['.id']], $payload)),
                'Update HotSpot profile'
            );
            $confirmed = collect($this->profiles())->firstWhere('name', $name);
            if (!$confirmed || ($confirmed['.id'] ?? null) !== $existing['.id']) {
                throw new RuntimeException('Router did not confirm the updated HotSpot profile.');
            }
            return $confirmed;
        }

        $created = $this->createMenu('ip/hotspot/user/profile', $payload);

        // Some POST /add responses are empty. Resolve the new record by name so
        // callers still receive its RouterOS .id when possible.
        if ($created === [] || !isset($created['.id'])) {
            return collect($this->profiles())->firstWhere('name', $name) ?? $created;
        }

        return $created;
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

        // Idempotent provisioning: a retry updates the same HotSpot username.
        $existing = collect($this->hotspotUsers())->firstWhere('name', $voucher->code);
        if ($existing && isset($existing['.id'])) {
            $this->updateMenu('ip/hotspot/user', $existing['.id'], $payload);
            return array_merge($existing, ['.id' => $existing['.id']]);
        }

        $created = $this->createMenu('ip/hotspot/user', $payload);
        if ($created === [] || !isset($created['.id'])) {
            return collect($this->hotspotUsers())->firstWhere('name', $voucher->code) ?? $created;
        }

        return $created;
    }

    public function bindMac(Voucher $voucher, string $mac): void
    {
        if (!$voucher->mikrotik_id) {
            return;
        }

        $this->decoded(
            $this->http()->post($this->url('ip/hotspot/user/set'), [
                '.id' => $voucher->mikrotik_id,
                'mac-address' => strtoupper($mac),
            ]),
            'Bind HotSpot user MAC'
        );
    }

    public function disableVoucher(Voucher $voucher): void
    {
        if (!$voucher->mikrotik_id) {
            return;
        }

        $response = $this->http()->patch(
            $this->url('ip/hotspot/user/'.rawurlencode($voucher->mikrotik_id)),
            ['disabled' => 'yes']
        );
        if (in_array($response->status(), [404, 500], true)) {
            $response = $this->http()->post(
                $this->url('ip/hotspot/user/set'),
                ['.id' => $voucher->mikrotik_id, 'disabled' => 'yes']
            );
        }
        $this->decoded($response, 'Disable HotSpot voucher');
    }

    public function disconnect(string $activeId): void
    {
        $response = $this->http()->delete(
            $this->url('ip/hotspot/active/'.rawurlencode($activeId))
        );
        if (in_array($response->status(), [404, 500], true)) {
            $response = $this->http()->post(
                $this->url('ip/hotspot/active/remove'),
                ['.id' => $activeId]
            );
        }
        $this->decoded($response, 'Disconnect HotSpot session');
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
