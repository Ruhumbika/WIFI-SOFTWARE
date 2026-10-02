<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\MikrotikRestClient;
use App\Services\RouterEnvironment;
use App\Services\CustomerWifiService;
use App\Services\RouterUplinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class RouterSetupController extends Controller
{
    public function customerWifi(Request $request, CustomerWifiService $wifi)
    {
        $this->authorizeCustomization($request);
        try { return ['interfaces' => $wifi->options()]; }
        catch (Throwable) { return response()->json(['message' => 'Customer Wi-Fi settings could not be read from the router.'], 503); }
    }

    public function renameCustomerWifi(Request $request, CustomerWifiService $wifi)
    {
        $this->authorizeCustomization($request);
        $data = $request->validate([
            'interface' => ['required', 'string', 'max:64', 'not_regex:/[\r\n]/'],
            'driver' => ['required', 'in:legacy,wifi'],
            'ssid' => ['required', 'string', 'max:32', 'not_regex:/[\r\n]/', function ($attribute, $value, $fail) {
                if (strlen($value) > 32) $fail('Wi-Fi name must be at most 32 bytes.');
            }],
        ]);
        try {
            $result = $wifi->rename($data['interface'], $data['driver'], $data['ssid']);
            Log::info('Customer Wi-Fi name changed', ['admin_id' => $request->user()->id, 'interface' => $data['interface']]);
            return ['message' => 'Customer Wi-Fi name updated.', 'interface' => $result];
        } catch (ValidationException $e) { throw $e; }
        catch (Throwable) {
            return response()->json(['message' => 'The Wi-Fi name could not be confirmed. Check the router before retrying.'], 503);
        }
    }

    public function naming(Request $request): array
    {
        $this->authorizeCustomization($request);
        return [
            'profile_prefix' => config('mikrotik.profile_prefix'),
            'voucher_prefix' => config('mikrotik.voucher_prefix'),
        ];
    }

    public function saveNaming(Request $request, RouterEnvironment $environment)
    {
        $this->authorizeCustomization($request);
        $data = $request->validate([
            'profile_prefix' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_-]{1,15}$/'],
            'voucher_prefix' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_-]{1,15}$/'],
        ]);
        if ($data['profile_prefix'] === config('mikrotik.profile_prefix')
            && $data['voucher_prefix'] === config('mikrotik.voucher_prefix')) {
            return ['message' => 'Naming prefixes are unchanged.', ...$data];
        }
        try {
            $environment->write([
                'MIKROTIK_PROFILE_PREFIX' => $data['profile_prefix'],
                'MIKROTIK_VOUCHER_PREFIX' => $data['voucher_prefix'],
            ]);
            Log::info('Router naming prefixes changed', ['admin_id' => $request->user()->id]);
        } catch (Throwable) {
            return response()->json(['message' => 'Naming prefixes could not be saved.'], 500);
        }
        try {
            if (Artisan::call('config:clear') !== 0 || Artisan::call('queue:restart') !== 0) {
                throw new \RuntimeException('Configuration refresh failed.');
            }
        } catch (Throwable) {
            return response()->json(['message' => 'Prefixes were saved, but application workers must be restarted before new vouchers are issued.'], 202);
        }
        return ['message' => 'Prefixes saved for new profiles and vouchers.', ...$data];
    }

    private function authorizeCustomization(Request $request): void
    {
        abort_unless($request->user()?->access_advanced_network_tools, 403);
    }

    public function uplinkStatus(RouterUplinkService $uplink, MikrotikRestClient $mikrotik)
    {
        try {
            $status = $uplink->status();
            try { $status['internet_reachable'] = $mikrotik->pingInternet(); }
            catch (Throwable) { $status['internet_reachable'] = null; }
            return $status;
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Could not read internet connection settings from the router.'], 503);
        }
    }

    public function configureUplink(Request $request, RouterUplinkService $uplink, MikrotikRestClient $mikrotik)
    {
        $data = $request->validate([
            'interface' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'kind' => ['required', 'in:cable,wifi'],
            'ssid' => ['required_if:kind,wifi', 'nullable', 'string', 'max:32', 'not_regex:/[\r\n]/'],
            'password' => ['required_if:kind,wifi', 'nullable', 'string', 'min:8', 'max:63', 'not_regex:/[\r\n]/'],
        ]);
        try {
            $result = $uplink->configure($data['interface'], $data['kind'], $data['ssid'] ?? null, $data['password'] ?? null);
            try { $result['uplink']['internet_reachable'] = $mikrotik->pingInternet(); }
            catch (Throwable) { $result['uplink']['internet_reachable'] = null; }
            return $result;
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Internet setup could not be completed. The router was restored where possible; check its settings if it is unreachable.'], 503);
        }
    }

    public function downloadHotspotLogin(Request $request)
    {
        $data = $request->validate(['portal_url' => ['required', 'url', 'max:255']]);
        $url = rtrim($data['portal_url'], '/');
        if ($url !== 'https://wifi.95-111-248-145.sslip.io') {
            throw ValidationException::withMessages(['portal_url' => 'Use the trusted RJAY public portal origin.']);
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)
            || !empty($parts['user']) || !empty($parts['pass'])
            || !empty($parts['query']) || !empty($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw ValidationException::withMessages(['portal_url' => 'Enter the portal origin reachable from customer phones, without a path or query.']);
        }

        $template = file_get_contents(base_path('../router/hotspot/login.html'));
        if ($template === false) return response()->json(['message' => 'Router login template is unavailable.'], 503);
        $html = str_replace('__PORTAL_URL__', htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $template);
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="login.html"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function show()
    {
        return [
            'base_url' => config('mikrotik.base_url'),
            'username' => config('mikrotik.username'),
            'password_set' => (string) config('mikrotik.password') !== '',
            'verify_tls' => (bool) config('mikrotik.verify_tls'),
            'hotspot_server' => config('mikrotik.hotspot_server'),
            'address_pool' => config('mikrotik.address_pool'),
            'active_packages' => Plan::where('active', true)->count(),
        ];
    }

    public function test(Request $request, MikrotikRestClient $mikrotik)
    {
        $settings = $this->validatedSettings($request, false);
        return $this->probe($settings, $mikrotik);
    }

    public function save(Request $request, MikrotikRestClient $mikrotik, RouterEnvironment $environment)
    {
        $settings = $this->validatedSettings($request, false);
        $result = $this->probe($settings, $mikrotik);
        if ($result instanceof \Illuminate\Http\JsonResponse) return $result;

        if (count($result['servers']) === 1 && $settings['hotspot_server'] === '') {
            $settings['hotspot_server'] = $result['servers'][0];
        }
        if (!in_array($settings['hotspot_server'], $result['servers'], true)) {
            throw ValidationException::withMessages([
                'hotspot_server' => count($result['servers']) === 0
                    ? 'No HotSpot server was found. Ask the installer to prepare the router.'
                    : 'Choose the HotSpot server used for customer Wi-Fi.',
            ]);
        }
        if (($settings['address_pool'] ?? '') !== '' && !in_array($settings['address_pool'], $result['address_pools'], true)) {
            throw ValidationException::withMessages([
                'address_pool' => 'Choose an address pool returned by the router test.',
            ]);
        }

        $values = [
            'MIKROTIK_BASE_URL' => $settings['base_url'],
            'MIKROTIK_USERNAME' => $settings['username'],
            'MIKROTIK_VERIFY_TLS' => $settings['verify_tls'] ? 'true' : 'false',
            'MIKROTIK_HOTSPOT_SERVER' => $settings['hotspot_server'],
            'MIKROTIK_ADDRESS_POOL' => $settings['address_pool'] ?? '',
        ];
        if ($request->filled('password')) $values['MIKROTIK_PASSWORD'] = $settings['password'];
        try {
            $environment->write($values);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Router settings could not be saved. Contact the system administrator.'], 500);
        }
        try {
            if (Artisan::call('config:clear') !== 0) {
                throw new \RuntimeException('Laravel config cache could not be cleared.');
            }
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Router settings were saved, but Laravel must be restarted to use them.'], 202);
        }
        try {
            if (Artisan::call('queue:restart') !== 0) {
                throw new \RuntimeException('Queue workers could not be restarted.');
            }
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Router settings were saved. Restart queue workers before issuing vouchers.'], 202);
        }

        return ['message' => 'Router connection saved and verified.', 'router_name' => $result['router_name']];
    }

    public function prepareProfiles()
    {
        try {
            $exitCode = Artisan::call('rjay:bootstrap-router');
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Package profiles could not be prepared. Check router diagnostics.'], 503);
        }
        if ($exitCode !== 0) {
            return response()->json(['message' => 'Package profiles could not be prepared. Check router diagnostics.'], 503);
        }
        return ['message' => 'Active package profiles are ready on the router.'];
    }

    public function rename(Request $request, MikrotikRestClient $mikrotik)
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'not_regex:/[\r\n]/'],
        ]);
        try {
            $identity = $mikrotik->setIdentity($data['name']);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Router name could not be changed. Check router diagnostics.'], 503);
        }
        return ['message' => 'Router name updated.', 'router_name' => $identity['name'] ?? null];
    }

    private function validatedSettings(Request $request, bool $requireHotspot): array
    {
        $data = $request->validate([
            'base_url' => ['required', 'url', 'max:255'],
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{1,64}$/'],
            'password' => ['nullable', 'string', 'min:8', 'max:255', 'not_regex:/[\r\n]/', 'not_regex:/\$\{/'],
            'verify_tls' => ['required', 'boolean'],
            'hotspot_server' => [$requireHotspot ? 'required' : 'nullable', 'string', 'max:64', 'not_regex:/[\r\n]/'],
            'address_pool' => ['nullable', 'string', 'max:64', 'not_regex:/[\r\n]/'],
        ]);

        $data['base_url'] = rtrim($data['base_url'], '/');
        $data['hotspot_server'] = $data['hotspot_server'] ?? '';
        $parts = parse_url($data['base_url']);
        $host = $parts['host'] ?? '';
        $octets = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? array_map('intval', explode('.', $host)) : [];
        $private = count($octets) === 4 && (
            $octets[0] === 10 ||
            ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31) ||
            ($octets[0] === 192 && $octets[1] === 168)
        );
        if (!$private || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ($parts['path'] ?? '') !== '/rest'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages([
                'base_url' => 'Use a private LAN router address ending in /rest, without a username or query.',
            ]);
        }
        if (($data['password'] ?? '') === '' && (string) config('mikrotik.password') === '') {
            throw ValidationException::withMessages(['password' => 'Enter the RouterOS API password.']);
        }
        $data['password'] = $data['password'] ?: (string) config('mikrotik.password');
        return $data;
    }

    private function probe(array $settings, MikrotikRestClient $mikrotik): array|\Illuminate\Http\JsonResponse
    {
        $previous = [];
        foreach (['base_url', 'username', 'password', 'verify_tls', 'hotspot_server', 'address_pool'] as $key) {
            $previous[$key] = config('mikrotik.'.$key);
            config()->set('mikrotik.'.$key, $settings[$key] ?? null);
        }
        try {
            $resource = $mikrotik->resource();
            $servers = collect($mikrotik->hotspotServers())->pluck('name')->filter()->values()->all();
            $pools = collect($mikrotik->addressPools())->pluck('name')->filter()->values()->all();
            try { $name = $mikrotik->identity()['name'] ?? null; } catch (Throwable) { $name = null; }
            return ['connected' => true, 'router_name' => $name, 'routeros' => $resource['version'] ?? null, 'servers' => $servers, 'address_pools' => $pools];
        } catch (Throwable $e) {
            report($e);
            $message = match (true) {
                str_contains($e->getMessage(), 'HTTP 401') => 'The router rejected the API username or password.',
                str_contains($e->getMessage(), 'HTTP 403') => 'The API user needs read, write and REST API permissions on the router.',
                str_contains($e->getMessage(), 'HTTP 404') => 'RouterOS REST returned 404. Enable the REST service and check its port.',
                default => 'The router could not be reached. Check its LAN address, REST port, and API user.',
            };
            return response()->json(['connected' => false, 'message' => $message], 503);
        } finally {
            foreach ($previous as $key => $value) config()->set('mikrotik.'.$key, $value);
        }
    }
}
