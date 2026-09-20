<?php

namespace App\Services;

use App\Models\Voucher;
use Illuminate\Support\Str;
use Throwable;

class VoucherProvisioner
{
    public function __construct(private readonly MikrotikRestClient $mikrotik) {}

    public function createVoucher(array $attributes): Voucher
    {
        do {
            $code = 'RJAY-'.Str::upper(Str::random(6));
        } while (Voucher::where('code', $code)->exists());

        return Voucher::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'code' => $code,
            'secret' => (string) random_int(100000, 999999),
            'status' => 'provision_pending',
        ], $attributes));
    }

    public function provision(Voucher $voucher): Voucher
    {
        try {
            $response = $this->mikrotik->createVoucherUser($voucher);
            $voucher->forceFill([
                'mikrotik_id' => $response['.id'] ?? $voucher->mikrotik_id,
                'status' => 'ready',
                'provisioned_at' => now(),
                'provision_error' => null,
            ])->save();
        } catch (Throwable $e) {
            $voucher->forceFill([
                'status' => 'provision_pending',
                'provision_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();
        }

        return $voucher->fresh('plan');
    }
}
