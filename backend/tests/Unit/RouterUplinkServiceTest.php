<?php

namespace Tests\Unit;

use App\Services\MikrotikRestClient;
use App\Services\RouterUplinkService;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class RouterUplinkServiceTest extends TestCase
{
    private function router(bool $bridgeUplink = false): array
    {
        $client = Mockery::mock(MikrotikRestClient::class);
        $dhcp = [];
        $nat = [];
        $client->shouldReceive('hotspotServers')->andReturn([['name' => 'hotspot1', 'interface' => 'bridge-lan']]);
        $client->shouldReceive('menu')->andReturnUsing(function ($path) use (&$dhcp, &$nat, $bridgeUplink) {
            return match ($path) {
                'interface/bridge/port' => [['interface' => 'wlan1'], ...($bridgeUplink ? [['interface' => 'ether1']] : [])],
                'ip/address' => [['interface' => 'bridge-lan', 'address' => '10.10.1.1/24']],
                'interface/ethernet' => [['name' => 'ether1']],
                'interface/wireless' => [['name' => 'wlan1']],
                'ip/dhcp-client' => $dhcp,
                'ip/route' => [['dst-address' => '0.0.0.0/0', 'active' => 'true', 'gateway' => '192.168.1.1']],
                'ip/firewall/nat' => $nat,
                'ip/dns' => ['dynamic-servers' => '1.1.1.1'],
                'interface/list/member' => [],
                default => [],
            };
        });
        $client->shouldReceive('addMenu')->andReturnUsing(function ($path, $payload) use (&$dhcp, &$nat) {
            if ($path === 'ip/dhcp-client') {
                $dhcp[] = ['.id' => '*A', 'interface' => $payload['interface'], 'status' => 'bound', 'address' => '192.168.100.3/24'];
                return ['.id' => '*A'];
            }
            if ($path === 'ip/firewall/nat') {
                $nat[] = array_merge(['.id' => '*B'], $payload);
                return ['.id' => '*B'];
            }
            return [];
        });
        return [$client];
    }

    public function test_customer_bridge_port_cannot_be_used_as_internet_input(): void
    {
        [$client] = $this->router(true);
        $service = new RouterUplinkService($client);
        $this->assertFalse($service->status()['interfaces'][0]['available']);
        $this->expectException(ValidationException::class);
        $service->configure('ether1', 'cable');
    }

    public function test_cable_setup_creates_dhcp_and_nat_on_selected_safe_port(): void
    {
        [$client] = $this->router();
        $result = (new RouterUplinkService($client))->configure('ether1', 'cable');
        $this->assertSame('bound', $result['state']);
        $this->assertSame('192.168.100.3/24', $result['uplink']['clients'][0]['address']);
        $this->assertContains('ether1', $result['uplink']['nat_interfaces']);
    }

    public function test_bound_existing_dhcp_is_recommended_and_missing_nat_is_added_without_duplicate_client(): void
    {
        $client = Mockery::mock(MikrotikRestClient::class);
        $nat = [];
        $client->shouldReceive('hotspotServers')->andReturn([['name' => 'hotspot1', 'interface' => 'bridge-lan']]);
        $client->shouldReceive('menu')->andReturnUsing(function ($path) use (&$nat) {
            return match ($path) {
                'interface/bridge/port', 'interface/wireless', 'interface/list/member' => [],
                'ip/address' => [
                    ['interface' => 'bridge-lan', 'address' => '10.10.1.1/24'],
                    ['interface' => 'ether1', 'address' => '192.168.1.3/24', 'dynamic' => 'true'],
                ],
                'interface/ethernet' => [['name' => 'ether1']],
                'ip/dhcp-client' => [['.id' => '*D', 'interface' => 'ether1', 'status' => 'bound', 'address' => '192.168.1.3/24', 'gateway' => '192.168.1.1']],
                'ip/route' => [['dst-address' => '0.0.0.0/0', 'active' => 'true', 'gateway' => '192.168.1.1']],
                'ip/firewall/nat' => $nat,
                'ip/dns' => ['dynamic-servers' => '192.168.1.1'],
                default => [],
            };
        });
        $client->shouldReceive('addMenu')->with('ip/firewall/nat', Mockery::on(fn ($data) => $data['out-interface'] === 'ether1'))
            ->once()->andReturnUsing(function ($path, $data) use (&$nat) {
                $nat[] = array_merge(['.id' => '*N'], $data);
                return ['.id' => '*N'];
            });

        $service = new RouterUplinkService($client);
        $this->assertSame('complete', $service->status()['recommendation']['action']);
        $result = $service->configure('ether1', 'cable');
        $this->assertSame('bound', $result['state']);
        $this->assertSame('ready', $result['uplink']['recommendation']['action']);
        $this->assertSame('10.10.1.1/24', $client->menu('ip/address')[0]['address']);
        $client->shouldNotHaveReceived('addMenu', ['ip/dhcp-client', Mockery::any()]);
    }

    public function test_existing_dhcp_without_default_route_is_review_only(): void
    {
        $client = Mockery::mock(MikrotikRestClient::class);
        $client->shouldReceive('hotspotServers')->andReturn([['name' => 'hotspot1', 'interface' => 'bridge-lan']]);
        $client->shouldReceive('menu')->andReturnUsing(fn ($path) => match ($path) {
            'ip/address' => [['interface' => 'bridge-lan', 'address' => '10.10.1.1/24']],
            'interface/ethernet' => [['name' => 'ether1']],
            'ip/dhcp-client' => [['interface' => 'ether1', 'status' => 'bound', 'gateway' => '192.168.0.1']],
            'ip/route' => [['dst-address' => '0.0.0.0/0', 'active' => 'true', 'gateway' => '192.168.1.1']],
            default => [],
        });
        $service = new RouterUplinkService($client);
        $this->assertSame('review', $service->status()['recommendation']['action']);
        $this->expectException(ValidationException::class);
        $service->configure('ether1', 'cable');
    }

    public function test_only_one_safe_linked_ethernet_port_is_suggested_for_new_setup(): void
    {
        $client = Mockery::mock(MikrotikRestClient::class);
        $client->shouldReceive('hotspotServers')->andReturn([['name' => 'hotspot1', 'interface' => 'bridge-lan']]);
        $client->shouldReceive('menu')->andReturnUsing(fn ($path) => match ($path) {
            'interface/bridge/port' => [['interface' => 'ether2']],
            'ip/address' => [['interface' => 'bridge-lan', 'address' => '10.10.1.1/24']],
            'interface/ethernet' => [
                ['name' => 'ether1', 'running' => 'true'],
                ['name' => 'ether2', 'running' => 'true'],
                ['name' => 'ether3', 'running' => 'false'],
            ],
            default => [],
        });

        $status = (new RouterUplinkService($client))->status();
        $this->assertSame('ether1', $status['recommendation']['interface']);
        $this->assertSame('setup', $status['recommendation']['action']);
        $this->assertFalse(collect($status['interfaces'])->firstWhere('name', 'ether2')['eligible']);
    }

    public function test_wifi_password_is_sent_only_to_router_and_never_in_status(): void
    {
        $client = Mockery::mock(MikrotikRestClient::class);
        $dhcp = [];
        $nat = [];
        $profiles = [];
        $client->shouldReceive('hotspotServers')->andReturn([['name' => 'hotspot1', 'interface' => 'bridge-lan']]);
        $client->shouldReceive('menu')->andReturnUsing(function ($path) use (&$dhcp, &$nat, &$profiles) {
            return match ($path) {
                'interface/bridge/port' => [['interface' => 'wlan1']],
                'ip/address' => [['interface' => 'bridge-lan', 'address' => '10.10.1.1/24']],
                'interface/ethernet' => [['name' => 'ether1']],
                'interface/wireless' => [['.id' => '*2', 'name' => 'wlan2', 'mode' => 'ap-bridge', 'ssid' => 'Old', 'security-profile' => 'default', 'disabled' => 'false']],
                'interface/wireless/security-profiles' => $profiles,
                'ip/dhcp-client' => $dhcp,
                'ip/route' => [],
                'ip/firewall/nat' => $nat,
                'ip/dns' => [],
                'interface/list/member' => [],
                default => [],
            };
        });
        $client->shouldReceive('addMenu')->andReturnUsing(function ($path, $payload) use (&$dhcp, &$nat, &$profiles) {
            if ($path === 'interface/wireless/security-profiles') {
                $profiles[] = ['.id' => '*P', 'name' => $payload['name']];
                $this->assertSame('Secret123', $payload['wpa2-pre-shared-key']);
                return ['.id' => '*P'];
            }
            if ($path === 'ip/dhcp-client') {
                $dhcp[] = ['.id' => '*D', 'interface' => 'wlan2', 'status' => 'searching'];
                return ['.id' => '*D'];
            }
            $nat[] = array_merge(['.id' => '*N'], $payload);
            return ['.id' => '*N'];
        });
        $client->shouldReceive('setMenu')->once()->with('interface/wireless', '*2', Mockery::on(fn ($payload) => $payload['mode'] === 'station' && $payload['ssid'] === 'Airtel-Uplink'))->andReturn([]);

        $result = (new RouterUplinkService($client))->configure('wlan2', 'wifi', 'Airtel-Uplink', 'Secret123');
        $this->assertSame('waiting', $result['state']);
        $this->assertStringNotContainsString('Secret123', json_encode($result));
    }

    public function test_failed_nat_step_removes_new_dhcp_client(): void
    {
        $client = Mockery::mock(MikrotikRestClient::class);
        $dhcp = [];
        $client->shouldReceive('hotspotServers')->andReturn([['name' => 'hotspot1', 'interface' => 'bridge-lan']]);
        $client->shouldReceive('menu')->andReturnUsing(function ($path) use (&$dhcp) {
            return match ($path) {
                'interface/bridge/port', 'interface/wireless', 'ip/route', 'ip/firewall/nat', 'interface/list/member' => [],
                'ip/address' => [['interface' => 'bridge-lan', 'address' => '10.10.1.1/24']],
                'interface/ethernet' => [['name' => 'ether1']],
                'ip/dhcp-client' => $dhcp,
                'ip/dns' => [],
                default => [],
            };
        });
        $client->shouldReceive('addMenu')->with('ip/dhcp-client', Mockery::any())->once()->andReturnUsing(function () use (&$dhcp) {
            $dhcp[] = ['.id' => '*D', 'interface' => 'ether1', 'status' => 'bound'];
            return ['.id' => '*D'];
        });
        $client->shouldReceive('addMenu')->with('ip/firewall/nat', Mockery::any())->once()->andThrow(new \RuntimeException('NAT unavailable'));
        $client->shouldReceive('removeMenu')->with('ip/dhcp-client', '*D')->once();

        $this->expectExceptionMessage('NAT unavailable');
        (new RouterUplinkService($client))->configure('ether1', 'cable');
    }
}
