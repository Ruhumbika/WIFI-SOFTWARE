<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionPaidOrder;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\SnippeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class PublicPortalController extends Controller
{
    public function plans()
    {
        return Plan::where('active', true)->orderBy('price')->get();
    }

    public function createOrder(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required','exists:plans,id'],
            'phone' => ['required','string','max:20'],
            'name' => ['nullable','string','max:100'],
            'email' => ['nullable','email','max:150'],
            'device_mac' => ['nullable','regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
        ]);
        $plan = Plan::where('active', true)->findOrFail($data['plan_id']);
        $phone = preg_replace('/\D+/', '', $data['phone']);
        if (str_starts_with($phone, '0')) $phone = '255'.substr($phone, 1);
        if (!str_starts_with($phone, '255')) $phone = '255'.$phone;

        $order = Order::create([
            'uuid' => (string) Str::uuid(),
            'order_number' => 'ORD-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'plan_id' => $plan->id,
            'customer_phone' => $phone,
            'customer_name' => $data['name'] ?? null,
            'customer_email' => $data['email'] ?? null,
            'device_mac' => isset($data['device_mac']) ? strtoupper($data['device_mac']) : null,
            'amount' => $plan->price,
            'currency' => 'TZS',
            'status' => 'pending_payment',
        ]);
        return response()->json($order->load('plan'), 201);
    }

    public function pay(string $uuid, SnippeClient $snippe)
    {
        $order = Order::with('plan')->where('uuid', $uuid)->firstOrFail();
        if ($order->status === 'completed') return response()->json($this->orderPayload($order));

        $payment = Payment::where('order_id', $order->id)->where('status', 'pending')->latest()->first();
        if (!$payment) {
            $key = 'p-'.substr(str_replace('-', '', $order->uuid), 0, 26); // <= 28 chars
            $payment = Payment::create([
                'uuid' => (string) Str::uuid(),
                'order_id' => $order->id,
                'provider' => 'snippe',
                'status' => 'pending',
                'amount' => $order->amount,
                'currency' => 'TZS',
                'idempotency_key' => $key,
            ]);
        }

        try {
            $response = $snippe->createPayment($order, $payment);
            $reference = data_get($response, 'data.reference');
            $payment->forceFill(['reference' => $reference, 'provider_payload' => $response])->save();
            return response()->json([
                'order' => $this->orderPayload($order->fresh()),
                'payment' => ['reference'=>$reference, 'status'=>data_get($response,'data.status','pending')],
                'mock' => $snippe->isMock(),
            ]);
        } catch (Throwable $e) {
            return response()->json(['message'=>'Payment initiation failed','error'=>$e->getMessage()], 502);
        }
    }

    public function showOrder(string $uuid)
    {
        return response()->json($this->orderPayload(Order::with(['plan','payments','voucher'])->where('uuid', $uuid)->firstOrFail()));
    }

    public function mockComplete(string $uuid, SnippeClient $snippe)
    {
        abort_unless($snippe->isMock(), 404);
        $order = Order::with('payments')->where('uuid', $uuid)->firstOrFail();
        $payment = $order->payments()->latest()->firstOrFail();
        $payment->forceFill(['status'=>'completed','completed_at'=>now()])->save();
        $order->forceFill(['status'=>'paid','paid_at'=>now()])->save();
        ProvisionPaidOrder::dispatchSync($order->id);
        return response()->json($this->orderPayload($order->fresh(['plan','payments','voucher'])));
    }

    private function orderPayload(Order $order): array
    {
        $order->loadMissing(['plan','payments','voucher']);
        return [
            'uuid'=>$order->uuid, 'order_number'=>$order->order_number, 'status'=>$order->status,
            'amount'=>$order->amount, 'currency'=>$order->currency, 'phone'=>$order->customer_phone,
            'device_mac'=>$order->device_mac, 'plan'=>$order->plan,
            'payment'=>$order->payments->sortByDesc('id')->first(),
            'voucher'=>$order->voucher ? [
                'code'=>$order->voucher->code,
                'password'=>$order->voucher->secret,
                'status'=>$order->voucher->status,
                'device_mac'=>$order->voucher->device_mac,
                'activated_at'=>$order->voucher->activated_at,
                'expires_at'=>$order->voucher->expires_at,
                'provision_error'=>$order->voucher->provision_error,
            ] : null,
        ];
    }
}
