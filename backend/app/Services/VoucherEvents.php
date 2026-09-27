<?php

namespace App\Services;

use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

final class VoucherEvents
{
    private const TYPES = ['recovery_pin_issued', 'recovery_verified', 'recovery_failed', 'voucher_claimed', 'manual_voucher_created', 'device_bound', 'device_mismatch', 'device_transfer_requested', 'device_transfer_approved', 'device_transfer_rejected', 'device_released', 'voucher_compromised', 'credentials_rotated', 'voucher_disabled'];

    public static function record(Voucher $voucher, string $type, ?string $mac = null, ?int $actor = null): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported voucher event');
        }
        if ($type === 'device_mismatch' && DB::table('voucher_events')->where('voucher_id', $voucher->id)->where('event_type', $type)->where('attempted_mac', $mac)->where('occurred_at', '>', now()->subMinutes(5))->exists()) {
            return;
        }
        DB::table('voucher_events')->insert([
            'voucher_id' => $voucher->id, 'event_type' => $type,
            'actor_type' => $actor ? 'admin' : (app()->runningInConsole() ? 'system' : 'customer'),
            'actor_id' => $actor, 'expected_mac' => $voucher->device_mac, 'attempted_mac' => $mac,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
