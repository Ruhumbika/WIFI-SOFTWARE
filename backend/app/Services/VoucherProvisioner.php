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
            $code = config('mikrotik.voucher_prefix').Str::upper(Str::random(6));
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
        return \Illuminate\Support\Facades\DB::transaction(function () use ($voucher) {
            $voucher = Voucher::lockForUpdate()->findOrFail($voucher->id);
            if ($voucher->status !== 'provision_pending') return $voucher->fresh('plan');
            try {
                $response = $this->mikrotik->createVoucherUser($voucher);
                if (empty($response['.id'])) throw new \RuntimeException('Router did not confirm voucher creation.');
                $voucher->forceFill([
                    'mikrotik_id' => $response['.id'] ?? $voucher->mikrotik_id,
                    'status' => 'ready',
                    'provisioned_at' => now(),
                    'provision_error' => null,
                ])->save();
            } catch (Throwable $e) {
                $voucher->forceFill([
                    'status' => 'provision_pending',
                    'provision_error' => 'Router could not confirm voucher provisioning. Retry when available.',
                ])->save();
            }

            if ($voucher->status === 'ready' && $voucher->order_id) {
                $order = $voucher->order()->first();
                if ($order && $order->paid_at && $order->status !== 'completed') {
                    $order->forceFill([
                        'status' => 'completed',
                        'completed_at' => $voucher->provisioned_at ?? now(),
                    ])->save();
                }
            }

            return $voucher->fresh('plan');
        });
    }
}
