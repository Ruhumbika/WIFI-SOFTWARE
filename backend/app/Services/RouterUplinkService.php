<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class RouterUplinkService
{
    public function __construct(private MikrotikRestClient $router) {}

    public function status(): array
    {
        $hotspot = collect($this->router->hotspotServers())->firstWhere('name', config('mikrotik.hotspot_server'));
        $bridged = collect($this->router->menu('interface/bridge/port'))->pluck('interface')->all();
        $managementIp = parse_url((string) config('mikrotik.base_url'), PHP_URL_HOST);
        $addresses = $this->router->menu('ip/address');
        $management = collect($addresses)
            ->filter(fn ($row) => explode('/', (string) ($row['address'] ?? ''))[0] === $managementIp)
            ->pluck('interface')->all();
        $staticAddresses = collect($addresses)->filter(fn ($row) => ($row['dynamic'] ?? 'false') !== 'true')->pluck('interface')->all();
        $ethernetRows = $this->router->menu('interface/ethernet');
        $ethernet = collect($ethernetRows)->pluck('name')->filter()->values()->all();
        try {
            $radios = $this->router->menu('interface/wireless');
            $wireless = collect($radios)->pluck('name')->filter()->values()->all();
        } catch (Throwable) {
            $radios = [];
            $wireless = [];
        }
        // A physical radio may serve a bridged virtual AP even when the radio itself is not a bridge port.
        $masters = collect($radios)->pluck('master-interface')->filter(fn ($name) => $name !== 'none')->all();
        $protected = array_unique(array_filter(array_merge($bridged, $management, $staticAddresses, $masters, [$hotspot['interface'] ?? null])));
        $clients = $this->router->menu('ip/dhcp-client');
        $routes = $this->router->menu('ip/route');
        $nat = $this->router->menu('ip/firewall/nat');
        $dns = $this->router->menu('ip/dns');
        $dns = isset($dns[0]) ? $dns[0] : $dns;
        $wanMembers = collect($this->router->menu('interface/list/member'))
            ->filter(fn ($row) => ($row['list'] ?? null) === 'WAN')->pluck('interface')->all();

        $natInterfaces = collect(array_merge(
            collect($nat)->filter(fn ($row) => ($row['chain'] ?? null) === 'srcnat' && ($row['action'] ?? null) === 'masquerade' && ($row['disabled'] ?? 'false') !== 'true')
                ->pluck('out-interface')->filter()->all(),
            collect($nat)->contains(fn ($row) => ($row['chain'] ?? null) === 'srcnat' && ($row['action'] ?? null) === 'masquerade'
                && ($row['out-interface-list'] ?? null) === 'WAN' && ($row['disabled'] ?? 'false') !== 'true') ? $wanMembers : []
        ))->unique()->values()->all();
        $interfaces = collect(array_merge($ethernet, $wireless))->unique()->map(function ($name) use ($wireless, $protected, $clients, $routes) {
            $existing = collect($clients)->firstWhere('interface', $name);
            $safe = !in_array($name, $protected, true);
            return [
                'name' => $name,
                'kind' => in_array($name, $wireless, true) ? 'wifi' : 'cable',
                'available' => $safe && !$existing,
                'eligible' => $safe && (!$existing || (($existing['status'] ?? '') === 'bound' && !in_array(strtolower((string) ($existing['disabled'] ?? 'false')), ['true', 'yes', '1'], true))),
                'existing_dhcp' => (bool) $existing,
                'route_ready' => $existing ? $this->hasDefaultRouteFor($routes, $existing) : false,
            ];
        })->values()->all();
        $boundCandidates = collect($interfaces)->filter(fn ($item) => $item['eligible'] && $item['existing_dhcp'])
            ->filter(fn ($item) => collect($clients)->contains(fn ($client) => ($client['interface'] ?? null) === $item['name'] && ($client['status'] ?? null) === 'bound'));
        $recommended = $boundCandidates->count() === 1 ? $boundCandidates->first() : null;
        $linkedCandidates = collect($interfaces)->filter(fn ($item) => $item['available'] && $item['kind'] === 'cable')
            ->filter(fn ($item) => collect($ethernetRows)->contains(fn ($row) => ($row['name'] ?? null) === $item['name']
                && in_array(strtolower((string) ($row['running'] ?? 'false')), ['true', 'yes', '1'], true)));
        $linked = !$recommended && $boundCandidates->isEmpty() && $linkedCandidates->count() === 1 ? $linkedCandidates->first() : null;
        $defaultRoute = collect($routes)->first(fn ($row) => ($row['dst-address'] ?? null) === '0.0.0.0/0' && ($row['active'] ?? 'false') === 'true') !== null;
        $recommendedClient = $recommended ? collect($clients)->firstWhere('interface', $recommended['name']) : null;
        $recommendedRoute = $recommendedClient && $this->hasDefaultRouteFor($routes, $recommendedClient);

        return [
            'interfaces' => $interfaces,
            'recommendation' => $recommended ? [
                'interface' => $recommended['name'],
                'kind' => $recommended['kind'],
                'reason' => 'RouterOS reports an active DHCP lease on this safe interface.',
                'action' => !$recommendedRoute ? 'review' : (in_array($recommended['name'], $natInterfaces, true) ? 'ready' : 'complete'),
            ] : ($linked ? [
                'interface' => $linked['name'],
                'kind' => $linked['kind'],
                'reason' => 'This unused Ethernet port has a physical link. Confirm it leads to the internet provider.',
                'action' => 'setup',
            ] : null),
            'clients' => collect($clients)->map(fn ($client) => [
                'interface' => $client['interface'] ?? null,
                'status' => $client['status'] ?? null,
                'address' => $client['address'] ?? null,
                'gateway' => $client['gateway'] ?? null,
                'disabled' => $client['disabled'] ?? 'false',
            ])->values()->all(),
            'addresses' => collect($addresses)->filter(fn ($row) => in_array($row['interface'] ?? '', array_merge($ethernet, $wireless), true))
                ->map(fn ($row) => ['interface' => $row['interface'], 'address' => $row['address'] ?? null])->values()->all(),
            'default_route' => $defaultRoute,
            'dns' => collect(array_filter(explode(',', trim(($dns['dynamic-servers'] ?? '').','.($dns['servers'] ?? ''), ','))))->values()->all(),
            'nat_interfaces' => $natInterfaces,
        ];
    }

    public function configure(string $interface, string $kind, ?string $ssid = null, ?string $password = null): array
    {
        $snapshot = $this->status();
        $candidate = collect($snapshot['interfaces'])->firstWhere('name', $interface);
        if (!$candidate || !$candidate['eligible'] || $candidate['kind'] !== $kind) {
            throw ValidationException::withMessages(['interface' => 'Choose an available interface outside the customer and management networks.']);
        }
        $existingClient = collect($snapshot['clients'])->firstWhere('interface', $interface);
        if ($existingClient && !$this->hasDefaultRouteFor($this->router->menu('ip/route'), $existingClient)) {
            throw ValidationException::withMessages(['interface' => 'The existing DHCP connection has no matching active default route. Review its route settings before completing setup.']);
        }
        $originalRadio = null;
        $profileId = null;
        $clientId = null;
        $natId = null;
        try {
            if ($kind === 'wifi' && !$existingClient) {
                $radio = collect($this->router->menu('interface/wireless'))->firstWhere('name', $interface);
                if (!$radio || empty($radio['.id'])) {
                    throw ValidationException::withMessages(['interface' => 'This router does not offer a supported wireless uplink interface.']);
                }
                $originalRadio = $radio;
                $profileName = 'rjay-uplink-'.substr(hash('sha256', $interface.$ssid), 0, 10);
                if (collect($this->router->menu('interface/wireless/security-profiles'))->contains('name', $profileName)) {
                    throw ValidationException::withMessages(['interface' => 'A Wi-Fi profile already exists for this network. Ask an installer to review it.']);
                }
                $createdProfile = $this->router->addMenu('interface/wireless/security-profiles', [
                    'name' => $profileName, 'mode' => 'dynamic-keys',
                    'authentication-types' => 'wpa2-psk', 'wpa2-pre-shared-key' => $password,
                ]);
                $profileId = $createdProfile['.id'] ?? collect($this->router->menu('interface/wireless/security-profiles'))->firstWhere('name', $profileName)['.id'] ?? null;
                $this->router->setMenu('interface/wireless', $radio['.id'], [
                    'mode' => 'station', 'ssid' => $ssid, 'security-profile' => $profileName, 'disabled' => 'no',
                ]);
            }

            if (!$existingClient) {
                $createdClient = $this->router->addMenu('ip/dhcp-client', [
                    'interface' => $interface, 'add-default-route' => 'yes', 'use-peer-dns' => 'yes',
                    'disabled' => 'no', 'comment' => 'RJAY internet uplink',
                ]);
                $clientId = $createdClient['.id'] ?? collect($this->router->menu('ip/dhcp-client'))->first(fn ($row) => ($row['interface'] ?? null) === $interface)['.id'] ?? null;
                if (!$clientId) throw new \RuntimeException('DHCP client could not be confirmed on the router.');
            }

            $current = $this->status();
            if (!in_array($interface, $current['nat_interfaces'], true)) {
                $createdNat = $this->router->addMenu('ip/firewall/nat', [
                    'chain' => 'srcnat', 'action' => 'masquerade', 'out-interface' => $interface,
                    'comment' => 'RJAY internet uplink',
                ]);
                $natId = $createdNat['.id'] ?? collect($this->router->menu('ip/firewall/nat'))->first(fn ($row) =>
                    ($row['out-interface'] ?? null) === $interface && ($row['comment'] ?? null) === 'RJAY internet uplink')['.id'] ?? null;
            }
            $current = $this->status();
            $bound = collect($current['clients'])->firstWhere('interface', $interface);
            return [
                'message' => ($bound['status'] ?? '') === 'bound' ? 'Internet uplink configured.' : 'Uplink saved. Waiting for the provider to assign an IP address.',
                'state' => ($bound['status'] ?? '') === 'bound' ? 'bound' : 'waiting',
                'uplink' => $current,
            ];
        } catch (Throwable $e) {
            if ($natId) {
                try { $this->router->removeMenu('ip/firewall/nat', $natId); } catch (Throwable $rollback) { Log::error('Uplink NAT rollback failed', ['error' => $rollback->getMessage()]); }
            }
            if ($clientId) {
                try { $this->router->removeMenu('ip/dhcp-client', $clientId); } catch (Throwable $rollback) { Log::error('Uplink DHCP rollback failed', ['error' => $rollback->getMessage()]); }
            }
            if ($originalRadio) {
                try {
                    $this->router->setMenu('interface/wireless', $originalRadio['.id'], [
                        'mode' => $originalRadio['mode'], 'ssid' => $originalRadio['ssid'] ?? '',
                        'security-profile' => $originalRadio['security-profile'] ?? 'default',
                        'disabled' => $originalRadio['disabled'] ?? 'false',
                    ]);
                } catch (Throwable $rollback) { Log::error('Uplink Wi-Fi rollback failed', ['error' => $rollback->getMessage()]); }
            }
            if ($profileId) {
                try { $this->router->removeMenu('interface/wireless/security-profiles', $profileId); } catch (Throwable $rollback) { Log::error('Uplink profile rollback failed', ['error' => $rollback->getMessage()]); }
            }
            throw $e;
        }
    }

    private function hasDefaultRouteFor(array $routes, array $client): bool
    {
        $gateway = (string) ($client['gateway'] ?? '');
        if ($gateway === '') return false;
        return collect($routes)->contains(fn ($route) => ($route['dst-address'] ?? null) === '0.0.0.0/0'
            && ($route['active'] ?? 'false') === 'true'
            && in_array((string) ($route['gateway'] ?? ''), [$gateway, $gateway.'%'.($client['interface'] ?? '')], true));
    }
}
