<?php

namespace Tests\Unit;

use App\Services\CustomerWifiService;
use App\Services\MikrotikRestClient;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class CustomerWifiServiceTest extends TestCase
{
    public function test_ssid_write_uses_routeros_set_command_with_literal_record_id(): void
    {
        config()->set('mikrotik.base_url', 'http://router.test/rest');
        config()->set('mikrotik.password', 'test-only');
        Http::fake(['*/rest/interface/wireless/set' => Http::response([], 200)]);

        app(MikrotikRestClient::class)->setCustomerWifiSsid('legacy', '*1', 'New Customer Wi-Fi');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/interface/wireless/set')
            && $request['.id'] === '*1'
            && $request['ssid'] === 'New Customer Wi-Fi');
    }

    public function test_only_customer_hotspot_access_points_are_offered_and_updated(): void
    {
        config()->set('mikrotik.hotspot_server', 'hotspot1');
        $client = Mockery::mock(MikrotikRestClient::class);
        $ssid = 'Customer Wi-Fi';
        $client->shouldReceive('hotspotServers')->andReturn([['name' => 'hotspot1', 'interface' => 'bridge-lan']]);
        $client->shouldReceive('menu')->andReturnUsing(function ($path) use (&$ssid) {
            return match ($path) {
                'interface/bridge/port' => [
                    ['bridge' => 'bridge-lan', 'interface' => 'wlan1'],
                    ['bridge' => 'bridge-other', 'interface' => 'wlan2'],
                ],
                'interface/wireless' => [
                    ['.id' => '*1', 'name' => 'wlan1', 'mode' => 'ap-bridge', 'ssid' => $ssid],
                    ['.id' => '*2', 'name' => 'wlan2', 'mode' => 'ap-bridge', 'ssid' => 'Other network'],
                    ['.id' => '*3', 'name' => 'wlan3', 'mode' => 'station', 'ssid' => 'Provider network'],
                ],
                'interface/wifi' => [],
                default => [],
            };
        });
        $client->shouldReceive('setCustomerWifiSsid')->once()->with('legacy', '*1', 'New Customer Wi-Fi')
            ->andReturnUsing(function () use (&$ssid) { $ssid = 'New Customer Wi-Fi'; return []; });

        $service = new CustomerWifiService($client);
        $this->assertSame([['interface' => 'wlan1', 'driver' => 'legacy', 'ssid' => 'Customer Wi-Fi']], $service->options());
        $this->assertSame('New Customer Wi-Fi', $service->rename('wlan1', 'legacy', 'New Customer Wi-Fi')['ssid']);
    }

    public function test_provider_or_unmapped_radio_cannot_be_renamed(): void
    {
        config()->set('mikrotik.hotspot_server', 'hotspot1');
        $client = Mockery::mock(MikrotikRestClient::class);
        $client->shouldReceive('hotspotServers')->andReturn([['name' => 'hotspot1', 'interface' => 'bridge-lan']]);
        $client->shouldReceive('menu')->andReturnUsing(fn ($path) => match ($path) {
            'interface/bridge/port' => [['bridge' => 'bridge-lan', 'interface' => 'wlan1']],
            'interface/wireless' => [['.id' => '*2', 'name' => 'wlan2', 'mode' => 'station', 'ssid' => 'Provider']],
            'interface/wifi' => [],
            default => [],
        });
        $client->shouldNotReceive('setCustomerWifiSsid');

        $this->expectException(ValidationException::class);
        (new CustomerWifiService($client))->rename('wlan2', 'legacy', 'Wrong target');
    }

    public function test_modern_wifi_direct_ssid_is_supported(): void
    {
        config()->set('mikrotik.hotspot_server', 'hotspot1');
        $client = Mockery::mock(MikrotikRestClient::class);
        $ssid = 'Guest';
        $client->shouldReceive('hotspotServers')->andReturn([['name' => 'hotspot1', 'interface' => 'bridge-lan']]);
        $client->shouldReceive('menu')->andReturnUsing(fn ($path) => match ($path) {
            'interface/bridge/port' => [['bridge' => 'bridge-lan', 'interface' => 'wifi1']],
            'interface/wireless' => [],
            'interface/wifi' => [['.id' => '*A', 'name' => 'wifi1', 'configuration.mode' => 'ap', 'configuration.ssid' => $ssid]],
            default => [],
        });
        $this->assertSame('Guest', (new CustomerWifiService($client))->options()[0]['ssid']);
    }
}
