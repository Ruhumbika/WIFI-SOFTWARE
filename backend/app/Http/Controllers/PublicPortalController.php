<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionPaidOrder;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\SnippeClient;
use App\Services\ClickPesaClient;
use App\Services\ClickPesaPaymentReconciler;
use App\Services\MikrotikRestClient;
use App\Services\VoucherProvisioner;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\RequestException;
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
            'plan_id' => ['required', 'exists:plans,id'],
            'phone' => ['required', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'device_mac' => ['nullable', 'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
        ]);
        $plan = Plan::where('active', true)->findOrFail($data['plan_id']);
        $phone = \App\Services\PhoneNormalizer::normalize($data['phone']);

        $order = Order::create([
            'uuid' => (string) Str::uuid(),
            'order_number' => 'ORD-' . now()->format('ymd') . '-' . Str::upper(Str::random(6)),
            'plan_id' => $plan->id,
            'customer_phone' => $phone,
            'customer_name' => $data['name'] ?? null,
            'customer_email' => $data['email'] ?? null,
            'device_mac' => isset($data['device_mac']) ? strtoupper($data['device_mac']) : null,
            'amount' => $plan->price,
            'currency' => 'TZS',
            'status' => 'pending_payment',
        ]);
        return response()->json(array_merge($order->load('plan')->toArray(), [
            'access_token' => Crypt::encryptString($order->uuid),
        ]), 201);
    }

    public function pay(string $uuid, ClickPesaClient $clickpesa, ClickPesaPaymentReconciler $reconciler)
    {
        $this->authorizeOrder(request(), $uuid);
        $order = Order::with('plan')->where('uuid', $uuid)->firstOrFail();
        $lock = Cache::lock('clickpesa:pay:'.$order->id, 30);
        if (!$lock->get()) return response()->json(['message' => 'A payment request is already in progress.'], 429);
        try {
            return $this->payOrder($order, $clickpesa, $reconciler);
        } finally {
            $lock->release();
        }
    }

    private function payOrder(Order $order, ClickPesaClient $clickpesa, ClickPesaPaymentReconciler $reconciler)
    {
        if ($order->paid_at || in_array($order->status, ['paid', 'provisioning', 'completed'], true)) {
            return response()->json(['message' => 'This order has already been paid. Refresh to see the latest result.'], 409);
        }
        try {
            $clickpesa->assertConfigured();
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Payment is unavailable right now. Please try again later.'], 502);
        }

        $payment = Payment::where('order_id', $order->id)->latest()->first();
        if ($payment && $payment->provider === 'snippe') {
            return response()->json(['message' => 'This order uses an existing payment request. Refresh to see its result.'], 409);
        }
        if ($payment && $payment->status === 'pending' && $payment->reference) {
            $lastPush = (int) Cache::get('payment-push-at:'.$payment->id, $payment->created_at->timestamp);
            try {
                $status = $reconciler->reconcile($payment, $clickpesa);
                if (in_array($status, ['SUCCESS', 'SETTLED'], true)) {
                    return response()->json(['message' => 'Payment is already complete. Refresh to see the result.'], 409);
                }
                if ($status !== 'FAILED' && now()->timestamp - $lastPush < 300) {
                    return response()->json(['message' => 'Payment request is already waiting for approval.'], 409);
                }
            } catch (Throwable $e) {
                if (!$e instanceof RequestException || $e->response->status() !== 404) {
                    report($e);
                    return response()->json(['message' => 'Could not confirm the previous payment request. Please try again.'], 502);
                }
                if (now()->timestamp - $lastPush < 300) {
                    return response()->json(['message' => 'Please wait before requesting another payment.'], 429);
                }
            }
        }
        if (!$payment || $payment->reference || $payment->status !== 'pending') {
            $paymentUuid = (string) Str::uuid();
            $reference = 'R'.strtoupper(substr(str_replace('-', '', $paymentUuid), 0, 19));
            $payment = Payment::create([
                'uuid' => $paymentUuid,
                'order_id' => $order->id,
                'provider' => 'clickpesa',
                'reference' => $reference,
                'status' => 'pending',
                'amount' => $order->amount,
                'currency' => 'TZS',
                'idempotency_key' => $reference,
            ]);
        }

        try {
            $response = $clickpesa->initiate($order, $payment);
            if (($response['orderReference'] ?? null) !== $payment->reference
                || !in_array($response['status'] ?? null, ['PROCESSING', 'SUCCESS', 'SETTLED'], true)) {
                throw new \RuntimeException('ClickPesa returned an unexpected payment response.');
            }
            $payment->forceFill(['provider_payload' => $response])->save();
            Cache::add('payment-push-at:'.$payment->id, now()->timestamp, now()->addHours(4));
            return response()->json([
                'order' => $this->orderPayload($order->fresh()),
                'payment' => ['reference' => $payment->reference, 'status' => 'pending'],
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Payment request could not be sent. Please try again.'], 502);
        }
    }

    public function resendPush(string $uuid, SnippeClient $snippe, ClickPesaClient $clickpesa, ClickPesaPaymentReconciler $reconciler)
    {
        $this->authorizeOrder(request(), $uuid);
        $order = Order::with('plan')->where('uuid', $uuid)->firstOrFail();
        if ($order->paid_at || $order->status !== 'pending_payment') {
            return response()->json(['message' => 'Payment is no longer waiting for approval.'], 409);
        }
        $payment = $order->payments()->latest()->first();
        if (!$payment || $payment->status !== 'pending' || !$payment->reference) {
            return response()->json(['message' => 'There is no pending payment to resend.'], 409);
        }
        $key = 'payment-push-at:'.$payment->id;
        $lastPush = (int) Cache::get($key, $payment->updated_at?->timestamp ?? $payment->created_at->timestamp);
        if (now()->timestamp - $lastPush < 300) {
            return response()->json(['message' => 'Please wait for the countdown to finish before resending.'], 429);
        }
        $guard = 'payment-push-guard:'.$payment->id;
        if (!Cache::add($guard, true, 60)) {
            return response()->json(['message' => 'A resend is already in progress.'], 429);
        }
        try {
            if ($payment->provider === 'clickpesa') {
                $result = $this->pay($uuid, $clickpesa, $reconciler);
                if ($result->getStatusCode() !== 200) return $result;
                return response()->json($this->orderPayload($order->fresh()));
            }
            $status = data_get($snippe->getPayment($payment->reference), 'data.status');
            if ($status !== 'pending') {
                return response()->json(['message' => 'Payment status changed. Refresh to see the latest result.'], 409);
            }
            $snippe->pushPayment($payment->reference);
            Cache::put($key, now()->timestamp, now()->addHours(4));
            return response()->json($this->orderPayload($order->fresh()));
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Could not resend the request. Please try again.'], 502);
        } finally {
            Cache::forget($guard);
        }
    }

    public function showOrder(string $uuid, ClickPesaClient $clickpesa, ClickPesaPaymentReconciler $reconciler)
    {
        $this->authorizeOrder(request(), $uuid);
        $order = Order::with(['plan', 'payments', 'voucher'])->where('uuid', $uuid)->firstOrFail();
        foreach ($order->payments->sortByDesc('id') as $payment) {
            if ($order->paid_at || $payment->provider !== 'clickpesa' || $payment->status !== 'pending'
                || !Cache::add('clickpesa:status-poll:'.$payment->id, true, now()->addSeconds(15))) continue;
            try {
                $reconciler->reconcile($payment, $clickpesa);
                $order->refresh();
            } catch (Throwable $e) {
                report($e);
            }
        }
        return response()->json($this->orderPayload($order));
    }

    public function connectionStatus(Request $request, string $uuid, MikrotikRestClient $mikrotik)
    {
        $this->authorizeOrder($request, $uuid);
        $data = $request->validate([
            'device_mac' => ['nullable', 'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
        ]);
        $order = Order::with('voucher')->where('uuid', $uuid)->firstOrFail();
        $voucher = $order->voucher;
        if (!$voucher) return ['state' => 'offline'];
        $result=app(\App\Services\VoucherConnectionService::class)->connection($voucher, $data['device_mac'] ?? null);
        return response()->json($result,$result['state']==='router_unavailable' ? 503:200);
    }

    public function prepareConnection(
        Request $request,
        string $uuid,
        VoucherProvisioner $provisioner,
        MikrotikRestClient $mikrotik
    ) {
        $this->authorizeOrder($request, $uuid);
        $data = $request->validate([
            'device_mac' => ['nullable', 'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
            'login_url' => ['nullable', 'url', 'max:500'],
        ]);

        $order = Order::with(['plan', 'payments', 'voucher'])->where('uuid', $uuid)->firstOrFail();
        $latestPayment = $order->payments->sortByDesc('id')->first();
        $isPaid = (bool) $order->paid_at
            || in_array($order->status, ['paid', 'provisioning', 'completed'], true)
            || $latestPayment?->status === 'completed';

        if (!$isPaid) {
            return response()->json([
                'state' => 'payment_required',
                'message' => 'Payment has not been confirmed yet.',
            ], 409);
        }

        if (!$order->voucher) {
            ProvisionPaidOrder::dispatchSync($order->id);
            $order->refresh()->load(['plan', 'payments', 'voucher']);
        }

        $voucher = $order->voucher;
        if (!$voucher) {
            return response()->json([
                'state' => 'preparing',
                'message' => 'Your internet access is still being prepared.',
            ], 202);
        }

        $result = app(\App\Services\VoucherConnectionService::class)->prepare($voucher, $data['device_mac'] ?? null, $data['login_url'] ?? null);
        $status = match ($result['state']) {
            'router_unavailable' => 503, 'preparing' => 202,
            'expired', 'unavailable', 'device_mismatch' => 409, default => 200,
        };
        return response()->json($result, $status);
    }

    private function orderPayload(Order $order): array
    {
        $order->loadMissing(['plan', 'payments', 'voucher']);
        $payment = $order->payments->sortByDesc('id')->first();
        $paidAt = $order->paid_at ?? ($payment?->status === 'completed' ? $payment->completed_at : null);
        $voucherReady = in_array($order->voucher?->status, ['ready', 'active'], true);
        $lastPush = $payment ? (int) Cache::get('payment-push-at:'.$payment->id, $payment->updated_at?->timestamp ?? $payment->created_at->timestamp) : null;
        return [
            'uuid' => $order->uuid,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'paid_at' => $paidAt?->toIso8601String(),
            'preparation_timed_out' => (bool) ($paidAt && !$voucherReady && $paidAt->copy()->addMinutes(6)->isPast()),
            'amount' => $order->amount,
            'currency' => $order->currency,
            'phone' => $order->customer_phone,
            'masked_phone' => \App\Services\VoucherAccessService::maskPhone($order->customer_phone),
            'device_mac' => $order->device_mac,
            'plan' => $order->plan,
            'payment' => $payment,
            'payment_push_expires_at' => $payment && $payment->status === 'pending' ? now()->setTimestamp($lastPush + 300)->toIso8601String() : null,
            'voucher' => $order->voucher ? [
                'uuid' => $order->voucher->uuid,
                'recovery_issued' => (bool) $order->voucher->recovery_pin_issued_at,
                'code' => $order->voucher->code,
                'password' => $order->voucher->secret,
                'status' => $order->voucher->status,
                'device_mac' => $order->voucher->device_mac,
                'masked_device_mac' => \App\Services\VoucherAccessService::maskMac($order->voucher->device_mac),
                'activated_at' => $order->voucher->activated_at,
                'expires_at' => $order->voucher->expires_at,
                'provision_error' => $order->voucher->provision_error ? 'Wi-Fi setup is awaiting a retry.' : null,
            ] : null,
        ];
    }

    private function authorizeOrder(Request $request, string $uuid): void
    {
        try {
            abort_unless(hash_equals($uuid, Crypt::decryptString((string) $request->header('X-Order-Token'))), 403);
        } catch (Throwable) {
            abort(403);
        }
    }
}
