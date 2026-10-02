<?php

namespace App\Http\Controllers;

use App\Models\HotspotSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Voucher;
use App\Services\MikrotikRestClient;
use App\Services\VoucherProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminController extends Controller
{
    public function dashboard()
    {
        $analytics = app(\App\Services\PaymentReportingService::class)->analytics('today');
        $tzs = collect($analytics['totals'])->firstWhere('currency','TZS');
        $health = app(\App\Services\HotspotSyncHealth::class)->snapshot();
        return [
            'financial_totals'=>$analytics['totals'],
            'gross_today'=>$tzs['gross'] ?? 0,
            'fees_today'=>$tzs['fees'] ?? null,
            'net_revenue_today'=>$tzs['net'] ?? null,
            'missing_settlement'=>$tzs['missing_settlement'] ?? 0,
            'open_support_requests'=>\App\Models\SupportRequest::whereIn('status',['open','contacted'])->count(),
            'sync_health'=>$health,
            'online' => HotspotSession::whereNull('ended_at')->where('last_seen_at', '>=', now()->subMinutes(2))->count(),
            'sales_today' => array_sum(array_column($analytics['totals'],'sales')),
            'revenue_today' => $tzs['gross'] ?? 0,
            'active_vouchers' => Voucher::where('status', 'active')
                ->where(fn($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count(),
            'pending_provision' => Voucher::where('status', 'provision_pending')->count(),
            'failed_payments' => $analytics['statuses']['failed'] ?? 0,
            'pending_payments' => Payment::where('status', 'pending')->count(),
            'last_sync' => $health['last_successful_sync'],
            'recent_payments' => Payment::with('order.plan')->latest()->limit(10)->get(),
        ];
    }

    public function plans()
    {
        return Plan::withExists(['vouchers', 'orders'])->orderBy('price')->get();
    }

    public function storePlan(Request $request)
    {
        $data = $this->planData($request);
        $data['uuid'] = (string)Str::uuid();
        $data['mikrotik_profile_name'] = config('mikrotik.profile_prefix') . Str::upper($data['code']);
        if (Plan::where('mikrotik_profile_name', $data['mikrotik_profile_name'])->exists()) {
            throw ValidationException::withMessages(['code' => 'The router profile name generated from this code already exists.']);
        }
        return response()->json(Plan::create($data), 201);
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $data = $this->planData($request, $plan->id);
        $hasHistory = $plan->vouchers()->exists() || $plan->orders()->exists();
        if ($hasHistory) {
            foreach (['code', 'duration_seconds', 'rate_limit', 'data_limit_bytes'] as $field) {
                if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $plan->{$field}) {
                    return response()->json(['message' => 'This package has orders or vouchers. Its code, duration, speed and data limit cannot be changed.'], 422);
                }
            }
        }
        $data['mikrotik_profile_name'] = ($hasHistory || $data['code'] === $plan->code)
            ? $plan->mikrotik_profile_name : config('mikrotik.profile_prefix') . Str::upper($data['code']);
        if (Plan::where('mikrotik_profile_name', $data['mikrotik_profile_name'])->where('id', '!=', $plan->id)->exists()) {
            throw ValidationException::withMessages(['code' => 'The router profile name generated from this code already exists.']);
        }
        $plan->update($data);
        return $plan->fresh();
    }

    public function logs()
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];
        $files = array_values(array_filter($files, 'is_file'));
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        if (!$files) return ['entries' => []];

        $path = $files[0];
        $size = filesize($path);
        $contents = file_get_contents($path, false, null, max(0, $size - 262144), min($size, 262144));
        if ($contents === false) return response()->json(['message' => 'Application logs could not be read.'], 503);

        $entries = [];
        foreach (explode("\n", $contents) as $line) {
            if (!preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] [\w-]+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/', $line, $match)) continue;
            $event = 'Application event. See server log for details.';
            if (str_contains($match[3], 'Blocked voucher was active on the router.')) {
                $event = 'Blocked voucher activity detected on the router. Check Sessions and the affected voucher.';
            } elseif (preg_match('/Disable HotSpot voucher failed: HTTP (\d{3})/', $match[3], $detail)) {
                $event = 'Router rejected voucher disable (HTTP ' . $detail[1] . ').';
            } elseif (preg_match('~Read ([a-z/]+) failed: HTTP (\d{3})~', $match[3], $detail)) {
                $event = 'Router REST read ' . $detail[1] . ' failed (HTTP ' . $detail[2] . ').';
            } elseif (str_contains($match[3], 'MikroTik') || str_contains($match[3], 'RouterOS')) {
                $event = 'Router connection error. See router diagnostics.';
            }
            $entries[] = ['time' => $match[1], 'level' => $match[2], 'message' => $event];
        }

        return ['entries' => array_slice($entries, -50)];
    }

    public function vouchers(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:ready,active,expired,failed,disabled,pending'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $query = Voucher::with(['plan', 'order'])->latest();
        if (!empty($filters['search'])) {
            $term = str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']);
            $query->where(function ($q) use ($term) {
                $q->where('code', 'like', '%' . $term . '%')
                    ->orWhere('customer_phone', 'like', '%' . $term . '%')
                    ->orWhereHas('order.payments', fn($p) => $p->where('reference', 'like', '%' . $term . '%'));
            });
        }
        $now = now();
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'failed') $query->where('status', 'provision_pending')->whereNotNull('provision_error');
            elseif ($filters['status'] === 'pending') $query->where('status', 'provision_pending');
            elseif ($filters['status'] === 'active') $query->where('status', 'active')->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now));
            elseif ($filters['status'] === 'expired') $query->where(fn($q) => $q->where('status', 'expired')->orWhere(fn($active) => $active->where('status', 'active')->where('expires_at', '<=', $now)));
            else $query->where('status', $filters['status']);
        }
        if (!empty($filters['plan_id'])) $query->where('plan_id', $filters['plan_id']);
        if (!empty($filters['date'])) $query->whereDate('created_at', $filters['date']);
        $page = $query->paginate(50)->withQueryString();
        $page->getCollection()->transform(function (Voucher $voucher) use ($now) {
            $row = $voucher->toArray();
            if ($voucher->status === 'active' && $voucher->expires_at && $voucher->expires_at->lte($now)) $row['status'] = 'expired';
            $row['password'] = $voucher->secret;
            return $row;
        });
        $summary = Voucher::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $elapsedCount = Voucher::where('status', 'active')->where('expires_at', '<=', $now)->count();
        $summary['active'] = (int) ($summary['active'] ?? 0) - $elapsedCount;
        $summary['expired'] = (int) ($summary['expired'] ?? 0) + $elapsedCount;
        return response()->json(array_merge($page->toArray(), [
            'summary' => $summary,
        ]));
    }

    public function showVoucher(Voucher $voucher, MikrotikRestClient $mikrotik)
    {
        $voucher->load(['plan', 'order.payments', 'sessions' => fn($q) => $q->latest('started_at')->limit(20)]);
        $routerUptime = null;
        $routerCheckedAt = null;
        if ($voucher->mikrotik_id) {
            try {
                $routerUser = collect($mikrotik->hotspotUsers())->firstWhere('name', $voucher->code);
                $routerUptime = $routerUser['uptime'] ?? null;
                $routerCheckedAt = now()->toIso8601String();
            } catch (Throwable $e) {
                report($e);
            }
        }
        $details = $voucher->toArray();
        if ($voucher->status === 'active' && $voucher->expires_at?->isPast()) $details['status'] = 'expired';
        return array_merge($details, [
            'password' => $voucher->secret,
            'router_total_uptime' => $routerUptime,
            'router_checked_at' => $routerCheckedAt,
        ]);
    }

    public function generateVouchers(Request $request, VoucherProvisioner $provisioner)
    {
        $data = $request->validate(['plan_id' => ['required', 'exists:plans,id'], 'quantity' => ['required', 'integer', 'min:1', 'max:100'], 'phone'=>['nullable','string','max:24']]);
        $phone = empty($data['phone']) ? null : \App\Services\PhoneNormalizer::normalize($data['phone']);
        $created = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $phone, $provisioner, $request) {
            $items = [];
            for ($i = 0; $i < $data['quantity']; $i++) {
                $voucher = $provisioner->createVoucher(['plan_id' => $data['plan_id'], 'customer_phone' => $phone]);
                \App\Services\VoucherEvents::record($voucher, 'manual_voucher_created', null, $request->user()->id);
                $pin = $phone ? app(\App\Services\VoucherRecoveryService::class)->issue($voucher, false, $request->user()->id) : null;
                $items[] = ['voucher' => $voucher, 'recovery_pin' => $pin];
            }
            return $items;
        });
        $rows = [];
        foreach ($created as $item) {
            $voucher = $provisioner->provision($item['voucher']);
            $rows[] = array_merge($voucher->toArray(), ['password' => $voucher->secret, 'recovery_pin' => $item['recovery_pin']]);
        }
        return response()->json($rows,201)->header('Cache-Control','no-store');
    }

    public function retryVoucher(Voucher $voucher, VoucherProvisioner $provisioner)
    {
        abort_unless($voucher->status === 'provision_pending', 409, 'Only pending vouchers can be reprovisioned.');
        return $provisioner->provision($voucher);
    }

    public function disableVoucher(Voucher $voucher, MikrotikRestClient $mikrotik)
    {
        abort_if(in_array($voucher->status, ['revoked', 'expired'], true), 409, 'Voucher is already unavailable.');
        return \Illuminate\Support\Facades\DB::transaction(function () use ($voucher,$mikrotik) {
            $locked=Voucher::lockForUpdate()->findOrFail($voucher->id);
            abort_if(\App\Models\VoucherDeviceOperation::where('voucher_id',$locked->id)->where('state','!=','completed')->exists(),409,'Resolve the pending router operation first.');
            return $this->blockVoucher($locked,$mikrotik);
        });
    }

    private function blockVoucher(Voucher $voucher, MikrotikRestClient $mikrotik)
    {
        try {
            $mikrotik->disableVoucher($voucher);
            $routerUser = collect($mikrotik->hotspotUsers())->firstWhere('name', $voucher->code);
            if ($routerUser && !in_array(strtolower((string) ($routerUser['disabled'] ?? '')), ['true', 'yes', '1'], true)) {
                return response()->json(['message' => 'Router did not confirm that the voucher was disabled. Check the router before retrying.'], 503);
            }
            $voucher->forceFill(['status' => 'disabled', 'recovery_token_version'=>$voucher->recovery_token_version+1])->save();
            \App\Services\VoucherEvents::record($voucher,'voucher_disabled',null,request()->user()?->id);
            foreach ($mikrotik->activeSessions() as $active) {
                if (($active['user'] ?? null) === $voucher->code && isset($active['.id'])) $mikrotik->disconnect($active['.id']);
            }
            $mikrotik->removeVoucherCookies($voucher);
            if (collect($mikrotik->activeSessions())->contains(fn($active) => ($active['user'] ?? null) === $voucher->code)) {
                return response()->json(['message' => 'Voucher disabled, but a session is still online. Check the router and retry.'], 503);
            }
            if (collect($mikrotik->hotspotCookies())->contains(fn($cookie) => ($cookie['user'] ?? null) === $voucher->code)) {
                return response()->json(['message' => 'Voucher disabled, but its login cookie remains on the router. Check the router and retry.'], 503);
            }
            $voucher->sessions()->whereNull('ended_at')->update(['ended_at' => now()]);
            return ['message' => 'Voucher disabled and all its sessions disconnected. It cannot be used again.'];
        } catch (Throwable $e) {
            report($e);
            $disableFailure = preg_match('/Disable HotSpot voucher failed: HTTP (\d{3})/', $e->getMessage(), $detail);
            return response()->json(['message' => $voucher->fresh()->status === 'disabled'
                ? 'Voucher disabled, but router logout was not confirmed. Check Sessions and retry.'
                : ($disableFailure
                    ? 'Router rejected voucher disable (HTTP ' . $detail[1] . '). No successful block was recorded.'
                    : 'Router could not confirm that the voucher was disabled. No successful block was recorded.')], 503);
        }
    }

    public function voucherEvents(Voucher $voucher) {
        return [
            'events'=>\Illuminate\Support\Facades\DB::table('voucher_events')->where('voucher_id',$voucher->id)->latest('id')->limit(50)->get(),
            'transfer_requests'=>\Illuminate\Support\Facades\DB::table('voucher_device_transfer_requests')->where('voucher_id',$voucher->id)->latest('id')->limit(20)->get(),
            'operations'=>\App\Models\VoucherDeviceOperation::where('voucher_id',$voucher->id)->latest('id')->limit(10)->get(),
        ];
    }
    private function deviceOperation(Request $request, Voucher $voucher, string $action, ?int $transfer = null) {
        $data=$request->validate(['request_key'=>'required|uuid']);
        $result=app(\App\Services\VoucherDeviceService::class)->operate($voucher,$action,$data['request_key'],$request->user()->id,$transfer);
        return response()->json($result,$result['state']==='completed' ? 200:202);
    }
    public function releaseDevice(Request $request, Voucher $voucher) { return $this->deviceOperation($request,$voucher,'release'); }
    public function rotateCredentials(Request $request, Voucher $voucher) { return $this->deviceOperation($request,$voucher,'rotate'); }
    public function approveTransfer(Request $request, Voucher $voucher) {
        $data=$request->validate(['transfer_request_id'=>'required|integer']);
        return $this->deviceOperation($request,$voucher,'release',$data['transfer_request_id']);
    }
    public function rejectTransfer(Request $request, Voucher $voucher) {
        $data=$request->validate(['transfer_request_id'=>'required|integer']);
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request,$voucher,$data) {
            $v=Voucher::lockForUpdate()->findOrFail($voucher->id);
            abort_if(\App\Models\VoucherDeviceOperation::where('voucher_id',$v->id)->where('state','!=','completed')->exists(),409,'Resolve the pending router operation first.');
            $updated=\Illuminate\Support\Facades\DB::table('voucher_device_transfer_requests')->where('id',$data['transfer_request_id'])->where('voucher_id',$v->id)->where('status','pending')->update(['status'=>'rejected','resolved_at'=>now(),'resolved_by'=>$request->user()->id,'updated_at'=>now()]);
            abort_unless($updated,409,'No pending transfer.');
            \App\Services\VoucherEvents::record($v,'device_transfer_rejected',null,$request->user()->id);
            return ['state'=>'completed'];
        });
    }
    public function issueRecovery(Request $request, Voucher $voucher) {
        $data=$request->validate(['reset'=>'sometimes|boolean']);
        return response()->json(['recovery_pin'=>app(\App\Services\VoucherRecoveryService::class)->issue($voucher,$data['reset']??false,$request->user()->id)])->header('Cache-Control','no-store');
    }

    public function payments(Request $request)
    {
        return app(\App\Services\PaymentReportingService::class)->ledger($request);
    }
    public function analytics(Request $request)
    {
        $data = $request->validate(['period'=>['nullable',\Illuminate\Validation\Rule::in(['today','7d','30d'])]]);
        return app(\App\Services\PaymentReportingService::class)->analytics($data['period'] ?? '7d');
    }
    public function orders()
    {
        return Order::with(['plan', 'payments', 'voucher'])->latest()->paginate(50);
    }
    public function sessions(Request $request)
    {
        $search = $request->validate(['search'=>['nullable','string','max:100']])['search'] ?? '';
        $term = '%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
        return HotspotSession::with('voucher.plan')->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->whereRaw("mac_address LIKE ? ESCAPE '!'",[$term])->orWhereRaw("ip_address LIKE ? ESCAPE '!'",[$term])->orWhereHas('voucher',fn ($v) => $v->whereRaw("code LIKE ? ESCAPE '!'",[$term]))))->latest('last_seen_at')->paginate(100)->withQueryString();
    }

    public function disconnectSession(HotspotSession $session, MikrotikRestClient $mikrotik)
    {
        if ($session->ended_at) return response()->json(['message' => 'This session has already ended.'], 409);
        return $this->blockVoucher($session->voucher, $mikrotik);
    }

    public function routerHealth(MikrotikRestClient $mikrotik)
    {
        $base = [
            'configured_hotspot' => config('mikrotik.hotspot_server'),
            'last_sync' => Cache::get('rjay:hotspot:last-successful-sync'),
        ];
        try {
            $resource = $mikrotik->resource();
            $identity = null;
            $server = null;
            $activeUsers = null;
            try {
                $identity = $mikrotik->identity()['name'] ?? null;
            } catch (Throwable $e) {
                report($e);
            }
            try {
                $server = collect($mikrotik->hotspotServers())->firstWhere('name', config('mikrotik.hotspot_server'));
            } catch (Throwable $e) {
                report($e);
            }
            try {
                $activeUsers = count($mikrotik->activeSessions());
            } catch (Throwable $e) {
                report($e);
            }
            return array_merge($base, [
                'connected' => true,
                'hotspot' => $server !== null && ($server['disabled'] ?? 'no') !== 'yes',
                'router_name' => $identity,
                'hotspot_server' => $server ? [
                    'name' => $server['name'] ?? null,
                    'disabled' => $server['disabled'] ?? null,
                    'interface' => $server['interface'] ?? null,
                ] : null,
                'active_users' => $activeUsers,
                'resource' => $resource,
            ]);
        } catch (Throwable $e) {
            report($e);
            $message = match (true) {
                str_contains($e->getMessage(), 'MIKROTIK_PASSWORD is not configured') => 'Router API password is missing. Complete Router Setup below.',
                str_contains($e->getMessage(), 'HTTP 404') => 'RouterOS REST returned 404 at the configured address. Check the REST service port and URL on the router.',
                default => 'RouterOS REST is unavailable. Check the router REST address and run diagnostics.',
            };
            return response()->json(array_merge($base, [
                'connected' => false,
                'message' => $message,
            ]), 503);
        }
    }

    public function routerSync()
    {
        try {
            $exitCode = Artisan::call('rjay:sync-hotspot');
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Router sync could not complete. Check the router connection.'], 503);
        }
        if ($exitCode !== 0) {
            return response()->json(['message' => 'Router sync could not complete. Check the router connection.'], 503);
        }
        return ['message' => 'HotSpot sessions synchronized.', 'last_sync' => Cache::get('rjay:hotspot:last-successful-sync')];
    }

    public function routerDiagnostics(MikrotikRestClient $mikrotik)
    {
        $checks = [];
        foreach (['resource' => 'resource', 'identity' => 'identity', 'HotSpot servers' => 'hotspotServers', 'profiles' => 'profiles', 'HotSpot users' => 'hotspotUsers', 'HotSpot sessions' => 'activeSessions'] as $name => $method) {
            try {
                $mikrotik->{$method}();
                $checks[$name] = ['message' => 'Responding'];
            } catch (Throwable $e) {
                report($e);
                $checks[$name] = ['message' => 'Unavailable'];
            }
        }
        return ['checks' => $checks];
    }

    private function planData(Request $request, ?int $ignoreId = null): array
    {
        if ($ignoreId && !$request->exists('original_price')) {
            $request->merge(['original_price' => Plan::findOrFail($ignoreId)->original_price]);
        }
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'alpha_dash', 'max:30', 'unique:plans,code' . ($ignoreId ? ',' . $ignoreId : '')],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'integer', 'min:500'],
            'original_price' => ['nullable', 'integer', 'gt:price'],
            'currency' => ['sometimes', 'in:TZS'],
            'duration_seconds' => ['required', 'integer', 'min:60'],
            'rate_limit' => ['required', 'string', 'max:30'],
            'data_limit_bytes' => ['nullable', 'integer', 'min:1'],
            'active' => ['required', 'boolean'],
            'recommended' => ['sometimes', 'boolean'],
        ]);
    }
}
