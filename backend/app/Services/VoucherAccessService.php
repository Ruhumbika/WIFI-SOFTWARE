<?php

namespace App\Services;

use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class VoucherAccessService
{
    public static function maskPhone(?string $phone): ?string
    {
        return $phone ? '+'.substr($phone, 0, 3).' '.substr($phone, 3, 3).' *** '.substr($phone, -3) : null;
    }

    public static function maskMac(?string $mac): ?string
    {
        return $mac ? '••:••:••:••:'.substr($mac, -5) : null;
    }

    public function token(Voucher $v, string $purpose = 'recovery'): string
    {
        return Crypt::encryptString(json_encode(['voucher_id' => $v->id, 'customer_phone' => $v->customer_phone, 'purpose' => $purpose, 'version' => (int) $v->recovery_token_version, 'expires' => now()->addMinutes(15)->timestamp]));
    }

    public function orderOwns(Request $r, Voucher $v): bool
    {
        if (! $v->order_id) {
            return false;
        }
        try {
            return hash_equals((string) $v->order->uuid, Crypt::decryptString((string) $r->header('X-Order-Token')));
        } catch (\Throwable) {
            return false;
        }
    }

    public function authorize(Request $r, Voucher $v, bool $management = true): void
    {
        if ($this->orderOwns($r, $v)) {
            return;
        }
        try {
            $data = json_decode(Crypt::decryptString((string) $r->header('X-Voucher-Recovery-Token')), true, 16, JSON_THROW_ON_ERROR);
            $valid = ($data['voucher_id'] ?? null) === $v->id
                && ($data['customer_phone'] ?? null) === $v->customer_phone
                && ($data['version'] ?? -1) === (int) $v->recovery_token_version
                && ($data['expires'] ?? 0) > now()->timestamp
                && in_array($data['purpose'] ?? '', $management ? ['recovery'] : ['recovery', 'redeem'], true);
        } catch (\Throwable) {
            $valid = false;
        }
        abort_unless($valid, 403, 'Verify this voucher to continue.');
    }

    public function payload(Voucher $v, bool $verified = false): array
    {
        $v->loadMissing('plan');
        $status = $v->expires_at?->isPast() ? 'expired' : $v->status;
        $result = ['uuid' => $v->uuid, 'code' => $verified ? $v->code : 'RJAY-•••'.substr($v->code, -3),
            'status' => $status, 'plan' => ['name' => $v->plan->name, 'duration_seconds' => $v->plan->duration_seconds, 'rate_limit' => $v->plan->rate_limit],
            'customer_phone' => self::maskPhone($v->customer_phone), 'device_mac' => self::maskMac($v->device_mac),
            'created_at' => $v->created_at, 'activated_at' => $v->activated_at, 'expires_at' => $v->expires_at];
        if ($verified) {
            $result['password'] = $v->secret;
            $result['recovery_issued'] = (bool) $v->recovery_pin_issued_at;
            $result['registered'] = (bool) $v->customer_phone;
            $result['transfer_pending'] = DB::table('voucher_device_transfer_requests')->where('voucher_id', $v->id)->where('status', 'pending')->exists();
            $session = $v->sessions()->latest('last_seen_at')->first();
            $result['session'] = $session ? ['started_at' => $session->started_at, 'last_seen_at' => $session->last_seen_at, 'uptime' => $session->uptime] : null;
        }

        return $result;
    }
}
