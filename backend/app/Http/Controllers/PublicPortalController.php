<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionPaidOrder;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\SnippeClient;
use App\Services\MikrotikRestClient;
use App\Services\VoucherProvisioner;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
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
        $phone = preg_replace('/\D+/', '', $data['phone']);
        if (preg_match('/^0[67]\d{8}$/', $phone)) $phone = '255' . substr($phone, 1);
        elseif (preg_match('/^[67]\d{8}$/', $phone)) $phone = '255' . $phone;
        elseif (!preg_match('/^255[67]\d{8}$/', $phone)) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid Tanzania mobile number.']);
        }

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

    public function pay(string $uuid, SnippeClient $snippe)
    {
        $this->authorizeOrder(request(), $uuid);
        $order = Order::with('plan')->where('uuid', $uuid)->firstOrFail();
        if ($order->status === 'completed') return response()->json($this->orderPayload($order));

        $payment = Payment::where('order_id', $order->id)->where('status', 'pending')->latest()->first();
        if (!$payment) {
            $paymentUuid = (string) Str::uuid();
            $key = 'p-' . substr(str_replace('-', '', $paymentUuid), 0, 26);
            $payment = Payment::create([
                'uuid' => $paymentUuid,
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
            Cache::add('payment-push-at:'.$payment->id, now()->timestamp, now()->addHours(4));
            return response()->json([
                'order' => $this->orderPayload($order->fresh()),
                'payment' => ['reference' => $reference, 'status' => data_get($response, 'data.status', 'pending')],
                'mock' => $snippe->isMock(),
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Payment request could not be sent. Please try again.'], 502);
        }
    }

    public function resendPush(string $uuid, SnippeClient $snippe)
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

    public function showOrder(string $uuid)
    {
        $this->authorizeOrder(request(), $uuid);
        return response()->json($this->orderPayload(Order::with(['plan', 'payments', 'voucher'])->where('uuid', $uuid)->firstOrFail()));
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
        if (in_array($voucher->status, ['disabled', 'revoked'], true)) return ['state' => 'unavailable'];
        if ($voucher->status === 'expired' || $voucher->expires_at?->isPast()) return ['state' => 'expired'];

        $mac = strtoupper($data['device_mac'] ?? $order->device_mac ?? $voucher->device_mac ?? '');
        if ($mac && $voucher->device_mac && $mac !== strtoupper($voucher->device_mac)) {
            return ['state' => 'device_mismatch'];
        }
        try {
            $online = collect($mikrotik->activeSessions())->contains(fn ($active) =>
                ($active['user'] ?? null) === $voucher->code
                && (!$mac || strtoupper($active['mac-address'] ?? '') === $mac));
        } catch (Throwable $e) {
            report($e);
            return response()->json(['state' => 'router_unavailable'], 503);
        }
        return ['state' => $online ? 'online' : 'offline'];
    }

    public function mockComplete(string $uuid, SnippeClient $snippe)
    {
        $this->authorizeOrder(request(), $uuid);
        abort_unless($snippe->isMock(), 404);
        $order = Order::with('payments')->where('uuid', $uuid)->firstOrFail();
        $payment = $order->payments()->latest()->firstOrFail();
        $payment->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $order->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        ProvisionPaidOrder::dispatchSync($order->id);
        return response()->json($this->orderPayload($order->fresh(['plan', 'payments', 'voucher'])));
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

        if ($voucher->expires_at && $voucher->expires_at->isPast()) {
            $voucher->forceFill(['status' => 'expired'])->save();
            return response()->json(['state' => 'expired'], 409);
        }

        if (in_array($voucher->status, ['expired'], true)) {
            return response()->json(['state' => 'expired'], 409);
        }

        if (in_array($voucher->status, ['disabled', 'revoked'], true)) {
            return response()->json(['state' => 'unavailable'], 409);
        }

        $requestMac = isset($data['device_mac']) && $data['device_mac']
            ? strtoupper($data['device_mac'])
            : null;

        if ($requestMac && $voucher->device_mac && strtoupper($voucher->device_mac) !== $requestMac) {
            return response()->json(['state' => 'device_mismatch'], 409);
        }

        if ($voucher->status === 'provision_pending') {
            $voucher = $provisioner->provision($voucher);
        }

        if ($voucher->status === 'provision_pending') {
            try {
                $mikrotik->resource();
            } catch (Throwable $e) {
                return response()->json([
                    'state' => 'router_unavailable',
                    'message' => 'The Wi-Fi router is temporarily unavailable.',
                ], 503);
            }

            return response()->json([
                'state' => 'preparing',
                'message' => 'Your internet access is still being prepared.',
            ], 202);
        }

        try {
            $mikrotik->resource();
        } catch (Throwable $e) {
            return response()->json([
                'state' => 'router_unavailable',
                'message' => 'The Wi-Fi router is temporarily unavailable.',
            ], 503);
        }

        $loginUrl = $data['login_url'] ?? null;
        $routerHost = parse_url((string) config('mikrotik.base_url'), PHP_URL_HOST);
        $trustedLogin = $loginUrl && $routerHost
            && strcasecmp((string) parse_url($loginUrl, PHP_URL_HOST), (string) $routerHost) === 0
            && in_array(parse_url($loginUrl, PHP_URL_SCHEME), ['http', 'https'], true)
            && in_array(parse_url($loginUrl, PHP_URL_PORT), [null, 80, 443], true)
            && parse_url($loginUrl, PHP_URL_PATH) === '/login'
            && !parse_url($loginUrl, PHP_URL_USER)
            && !parse_url($loginUrl, PHP_URL_QUERY)
            && !parse_url($loginUrl, PHP_URL_FRAGMENT);

        return response()->json([
            'state' => $voucher->status === 'active' ? 'active' : 'ready',
            'voucher_status' => $voucher->status,
            'login_url' => $trustedLogin ? $loginUrl : null,
        ]);
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
            'mock' => app(SnippeClient::class)->isMock(),
            'phone' => $order->customer_phone,
            'device_mac' => $order->device_mac,
            'plan' => $order->plan,
            'payment' => $payment,
            'payment_push_expires_at' => $payment && $payment->status === 'pending' ? now()->setTimestamp($lastPush + 300)->toIso8601String() : null,
            'voucher' => $order->voucher ? [
                'code' => $order->voucher->code,
                'password' => $order->voucher->secret,
                'status' => $order->voucher->status,
                'device_mac' => $order->voucher->device_mac,
                'activated_at' => $order->voucher->activated_at,
                'expires_at' => $order->voucher->expires_at,
                'provision_error' => $order->voucher->provision_error,
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
