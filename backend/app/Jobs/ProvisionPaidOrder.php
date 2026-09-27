<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\VoucherProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionPaidOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public int $orderId) {}

    public function handle(VoucherProvisioner $provisioner): void
    {
        $voucher = \Illuminate\Support\Facades\DB::transaction(function () use ($provisioner) {
            $order = Order::with(['plan','voucher'])->lockForUpdate()->findOrFail($this->orderId);
            if (!$order->paid_at && !in_array($order->status,['paid','provisioning','completed'],true) && !$order->payments()->where('status','completed')->exists()) return null;
            if ($order->voucher && $order->voucher->status !== 'provision_pending') return null;
            $order->forceFill(['status'=>'provisioning'])->save();
            return $order->voucher ?: $provisioner->createVoucher([
                'plan_id'=>$order->plan_id, 'order_id'=>$order->id, 'customer_phone'=>$order->customer_phone,
            ]);
        });
        if (!$voucher) return;
        $order = Order::findOrFail($this->orderId);

        $voucher = $provisioner->provision($voucher);
        if ($voucher->status === 'ready' && $order->fresh()->status !== 'completed') {
            $order->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        }
    }
}
