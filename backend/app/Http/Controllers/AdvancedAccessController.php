<?php

namespace App\Http\Controllers;

use App\Services\MikrotikRestClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdvancedAccessController extends Controller
{
    private const ACTIONS = [
        'WINBOX_LAUNCH_REQUESTED', 'WINBOX_DETAILS_COPIED',
        'WEBFIG_OPENED', 'WINBOX_DOWNLOAD_OPENED',
    ];

    public function permission(Request $request): array
    {
        return ['allowed' => (bool) $request->user()?->access_advanced_network_tools];
    }

    public function access(Request $request, MikrotikRestClient $mikrotik)
    {
        $this->authorizeAccess($request);
        $this->audit($request, 'ADVANCED_TOOLS_OPENED', 'opened');

        $address = parse_url((string) config('mikrotik.base_url'), PHP_URL_HOST);
        $base = [
            'router' => ['name' => null, 'management_address' => $address ?: null],
            'username' => (string) config('mikrotik.username'),
            'connected' => false,
            'services' => [],
            'technical' => ['routeros_version' => null, 'board' => null, 'architecture' => null],
        ];
        try {
            $base['router']['name'] = $mikrotik->identity()['name'] ?? null;
            $rows = collect($mikrotik->managementServices())->keyBy('name');
            try {
                $resource = $mikrotik->resource();
                $base['technical'] = [
                    'routeros_version' => $resource['version'] ?? null,
                    'board' => $resource['board-name'] ?? null,
                    'architecture' => $resource['architecture-name'] ?? null,
                ];
            } catch (Throwable) {
                // Service status remains available when this optional resource read fails.
            }
            foreach (['winbox', 'www-ssl', 'www', 'ssh', 'api', 'api-ssl'] as $name) {
                $row = $rows->get($name);
                $enabled = $row ? !in_array(strtolower((string) ($row['disabled'] ?? 'false')), ['true', 'yes', '1'], true) : false;
                $port = $row && filter_var($row['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])
                    ? (int) $row['port'] : null;
                $base['services'][$name] = [
                    'enabled' => $enabled,
                    'port' => $port,
                    'allowed_addresses' => $row && is_string($row['address'] ?? null) ? $row['address'] : null,
                ];
            }
            $base['connected'] = true;
            $https = $base['services']['www-ssl'];
            $base['webfig_url'] = $https['enabled'] && $https['port'] && $address
                ? 'https://'.$address.($https['port'] === 443 ? '' : ':'.$https['port']) : null;
            return response()->json($base)->header('Cache-Control', 'no-store');
        } catch (Throwable $e) {
            return response()->json($base + ['message' => 'Router management services are unavailable. Run diagnostics.'], 503)
                ->header('Cache-Control', 'no-store');
        }
    }

    public function event(Request $request)
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'action' => ['required', 'in:'.implode(',', self::ACTIONS)],
            'result' => ['required', 'in:manual,opened,copied,unavailable,failed'],
            'client_platform' => ['required', 'in:Windows,Linux,macOS,Android,iOS,ChromeOS,Unknown'],
        ]);
        $this->audit($request, $data['action'], $data['result'], $data['client_platform']);
        return response()->json(['recorded' => true]);
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()?->access_advanced_network_tools, 403);
    }

    private function audit(Request $request, string $action, string $result, ?string $platform = null): void
    {
        $platform ??= $request->header('X-Client-Platform', 'Unknown');
        if (!in_array($platform, ['Windows', 'Linux', 'macOS', 'Android', 'iOS', 'ChromeOS'], true)) $platform = 'Unknown';
        DB::table('advanced_router_audit_events')->insert([
            'user_id' => $request->user()->id,
            'router_address' => parse_url((string) config('mikrotik.base_url'), PHP_URL_HOST) ?: null,
            'action' => $action,
            'client_platform' => $platform,
            'result' => $result,
            'created_at' => now(),
        ]);
    }
}
