<?php

namespace App\Services;

use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

final class VoucherRecoveryService
{
    public function issue(Voucher $voucher, bool $reset = false, ?int $actor = null): string
    {
        return DB::transaction(function () use ($voucher, $reset, $actor) {
            $v = Voucher::lockForUpdate()->findOrFail($voucher->id);
            abort_unless($v->customer_phone, 409, 'Register a phone before issuing recovery access.');
            abort_if($v->recovery_pin_issued_at && ! $reset, 409, 'The recovery PIN has already been issued.');
            $oldHash = $v->recovery_pin_hash;
            do {
                $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            } while (hash_equals((string) $v->secret, $pin) || ($oldHash && Hash::check($pin, $oldHash)));
            $v->forceFill(['recovery_pin_hash' => Hash::make($pin), 'recovery_pin_created_at' => now(), 'recovery_pin_issued_at' => now(), 'recovery_token_version' => $v->recovery_token_version + 1])->save();
            VoucherEvents::record($v, 'recovery_pin_issued', null, $actor);

            return $pin;
        });
    }

    public function verify(string $uuid, string $phone, string $pin, string $ip): Voucher
    {
        $key = 'voucher-recovery:'.hash('sha256', $phone.'|'.$ip);
        $phoneKey = 'voucher-recovery-phone:'.hash('sha256', $phone);
        abort_if(RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($phoneKey, 15), 429, 'Please wait before trying recovery again.');
        // Count before hashing so concurrent attempts share the same attempt budget.
        RateLimiter::hit($key, 600);
        RateLimiter::hit($phoneKey, 600);
        $v = Voucher::where('uuid', $uuid)->where('customer_phone', $phone)->first();
        if (! $v || ! $v->recovery_pin_hash || ! Hash::check($pin, $v->recovery_pin_hash)) {
            if ($v) {
                VoucherEvents::record($v, 'recovery_failed');
            }
            abort(422, "We couldn't verify those recovery details.");
        }
        VoucherEvents::record($v,'recovery_verified');

        return $v;
    }
}
