<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CustomerWifiService
{
    public function __construct(private MikrotikRestClient $router) {}

    public function options(): array
    {
        $hotspot = collect($this->router->hotspotServers())->firstWhere('name', config('mikrotik.hotspot_server'));
        $hotspotInterface = $hotspot['interface'] ?? null;
        if (!$hotspotInterface) return [];

        $bridgePorts = $this->router->menu('interface/bridge/port');
        $customerInterfaces = collect($bridgePorts)
            ->filter(fn ($row) => ($row['bridge'] ?? null) === $hotspotInterface)
            ->pluck('interface')->push($hotspotInterface)->unique()->all();
        $options = [];
        foreach ([['legacy', 'interface/wireless', 'ssid'], ['wifi', 'interface/wifi', 'configuration.ssid']] as [$driver, $path, $ssidField]) {
            try { $rows = $this->router->menu($path); }
            catch (Throwable $e) {
                if (str_contains($e->getMessage(), 'HTTP 404')) continue;
                throw $e;
            }
            foreach ($rows as $row) {
                $name = $row['name'] ?? null;
                $mode = $row['configuration.mode'] ?? $row['mode'] ?? null;
                $ssid = $row[$ssidField] ?? null;
                if (!is_string($name) || !in_array($name, $customerInterfaces, true)
                    || !in_array($mode, ['ap', 'ap-bridge'], true)
                    || !is_string($ssid) || $ssid === '' || empty($row['.id'])) continue;
                $options[] = ['interface' => $name, 'driver' => $driver, 'ssid' => $ssid];
            }
        }
        return $options;
    }

    public function rename(string $interface, string $driver, string $ssid): array
    {
        $current = collect($this->options())->first(fn ($row) => $row['interface'] === $interface && $row['driver'] === $driver);
        if (!$current) {
            throw ValidationException::withMessages(['interface' => 'This Wi-Fi interface is not confirmed as part of the customer HotSpot.']);
        }
        if ($current['ssid'] === $ssid) return $current;

        $path = $driver === 'legacy' ? 'interface/wireless' : 'interface/wifi';
        $record = collect($this->router->menu($path))->firstWhere('name', $interface);
        if (!$record || empty($record['.id'])) throw new RuntimeException('Customer Wi-Fi interface is unavailable.');
        $this->router->setCustomerWifiSsid($driver, $record['.id'], $ssid);
        $confirmed = collect($this->options())->first(fn ($row) => $row['interface'] === $interface && $row['driver'] === $driver);
        if (!$confirmed || $confirmed['ssid'] !== $ssid) {
            throw new RuntimeException('Wi-Fi change was sent, but the router did not confirm the new name.');
        }
        return $confirmed;
    }
}
