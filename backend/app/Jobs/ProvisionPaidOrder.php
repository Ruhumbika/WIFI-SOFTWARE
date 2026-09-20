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
        $order = Order::with(['plan', 'voucher'])->findOrFail($this->orderId);
        if ($order->status === 'completed' && $order->voucher?->status === 'ready') return;

        $order->forceFill(['status' => 'provisioning'])->save();
        $voucher = $order->voucher ?: $provisioner->createVoucher([
            'plan_id' => $order->plan_id,
            'order_id' => $order->id,
            'customer_phone' => $order->customer_phone,
            'device_mac' => $order->device_mac,
        ]);

        $voucher = $provisioner->provision($voucher);
        if ($voucher->status === 'ready' && $order->fresh()->status !== 'completed') {
            $order->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        }
    }
}
