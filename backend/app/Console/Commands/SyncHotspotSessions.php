<?php

namespace App\Console\Commands;

use App\Models\HotspotSession;
use App\Models\Voucher;
use App\Services\MikrotikRestClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncHotspotSessions extends Command
{
    protected $signature = 'rjay:sync-hotspot';
    protected $description = 'Synchronize active MikroTik HotSpot sessions, bind first MAC and expire vouchers.';

    public function handle(MikrotikRestClient $mikrotik): int
    {
        try {
            $active = $mikrotik->activeSessions();
        } catch (Throwable $e) {
            $this->error('MikroTik unavailable: '.$e->getMessage());
            return self::FAILURE;
        }

        $seen = [];
        foreach ($active as $row) {
            $code = $row['user'] ?? null;
            if (!$code) continue;
            $voucher = Voucher::with('plan')->where('code', $code)->first();
            if (!$voucher) continue;

            $activeId = $row['.id'] ?? null;
            $mac = strtoupper($row['mac-address'] ?? '');
            if (!$activeId || !$mac) continue;

            try {
                \Illuminate\Support\Facades\DB::transaction(function () use ($voucher, $mikrotik, $activeId, $mac, $row) {
                    $voucher = Voucher::with('plan')->lockForUpdate()->findOrFail($voucher->id);
                    if (\App\Models\VoucherDeviceOperation::where('voucher_id',$voucher->id)->where('state','!=','completed')->exists()) return;
                    if ($voucher->expires_at?->isPast()) {
                        $mikrotik->disableVoucher($voucher);
                        $mikrotik->disconnect($activeId);
                        $voucher->forceFill(['status'=>'expired'])->save();
                        return;
                    }
                    if (in_array($voucher->status, ['disabled', 'revoked', 'expired'], true)) {
                        Log::error('Blocked voucher was active on the router.', ['voucher_id' => $voucher->id]);
                        try { $mikrotik->disableVoucher($voucher); } catch (Throwable $e) { report($e); }
                        try {
                            $mikrotik->disconnect($activeId);
                            HotspotSession::where('mikrotik_id', $activeId)->whereNull('ended_at')->update(['ended_at' => now()]);
                        } catch (Throwable $e) {
                            report($e);
                        }
                        return;
                    }

                    if ($voucher->device_mac && strtoupper($voucher->device_mac) !== $mac) {
                        \App\Services\VoucherEvents::record($voucher,'device_mismatch',$mac);
                        try { $mikrotik->disconnect($activeId); } catch (Throwable) {
                            $this->warn('A conflicting device could not be disconnected; retry is required.');
                        }
                        return;
                    }

                    if (!$voucher->device_mac) {
                        $mikrotik->bindMac($voucher, $mac);
                        $voucher->device_mac = $mac;
                        \App\Services\VoucherEvents::record($voucher,'device_bound',$mac);
                    }

                    if (!$voucher->activated_at) {
                        $voucher->activated_at = now();
                        if (!$voucher->expires_at) $voucher->expires_at = $voucher->activated_at->copy()->addSeconds($voucher->plan->duration_seconds);
                        $voucher->status = 'active';
                    }
                    if ($voucher->activated_at && !$voucher->expires_at) $voucher->expires_at = $voucher->activated_at->copy()->addSeconds($voucher->plan->duration_seconds);
                    $voucher->last_synced_at = now();
                    $voucher->save();

                    $session = HotspotSession::firstOrNew(['mikrotik_id' => $activeId]);
                    if (!$session->exists) $session->started_at = now();
                    $session->fill([
                        'voucher_id' => $voucher->id,
                        'mac_address' => $mac,
                        'ip_address' => $row['address'] ?? null,
                        'login_by' => $row['login-by'] ?? null,
                        'last_seen_at' => now(),
                        'ended_at' => null,
                        'bytes_in' => (int) ($row['bytes-in'] ?? 0),
                        'bytes_out' => (int) ($row['bytes-out'] ?? 0),
                        'uptime' => $row['uptime'] ?? null,
                    ])->save();
                });
            } catch (Throwable) {
                $this->warn('A voucher session could not be synchronized; it will be retried.');
            }
            $seen[] = $activeId;
        }

        HotspotSession::whereNull('ended_at')->where('last_seen_at', '<', now()->subMinutes(2))->update(['ended_at' => now()]);

        Voucher::with('plan')
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->each(function (Voucher $voucher) use ($mikrotik) {
                try { $mikrotik->disableVoucher($voucher); } catch (Throwable) {}
                $voucher->forceFill(['status' => 'expired', 'last_synced_at' => now()])->save();
            });

        Cache::put('rjay:hotspot:last-successful-sync', now()->toIso8601String(), now()->addDays(30));

        $this->info('Synced '.count($seen).' RJAY active session(s).');
        return self::SUCCESS;
    }
}
