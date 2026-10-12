<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentConsumer;
use App\Services\ConsumerPaymentService;
use Illuminate\Http\Request;

class ConsumerPaymentController extends Controller
{
    private function consumer(Request $request): PaymentConsumer
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 32 && strlen($token) <= 256, 401);
        $consumer = PaymentConsumer::with('business')->where('token_hash', hash('sha256', $token))->where('active', true)->first();
        abort_unless($consumer && $consumer->business?->status === 'active', 401);
        abort_unless($request->header('X-Business-Code') === $consumer->business->code, 403);
        return $consumer;
    }

    public function configuration(Request $request)
    {
        $consumer = $this->consumer($request);
        $gateway = app(ConsumerPaymentService::class)->gateway($consumer);
        return response()->json(['configured'=>true, 'business_code'=>$consumer->business->code]);
    }

    public function store(Request $request, ConsumerPaymentService $service)
    {
        $consumer = $this->consumer($request);
        // Reject merchant selection instead of silently trusting a browser-supplied value.
        abort_if(array_diff(array_keys($request->all()), ['source_reference', 'attempt', 'amount', 'currency', 'phone', 'kind', 'return_path']), 422, 'Unsupported payment fields.');
        $input = $request->validate([
            'kind'=>['required', 'in:push,checkout'],
            'return_path'=>['required', 'string', 'max:500', 'starts_with:/'],
            'source_reference'=>['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'attempt'=>['required', 'integer', 'min:1', 'max:100'],
            'amount'=>['required', 'integer', 'min:500', 'max:100000000'],
            'currency'=>['required', 'in:TZS'],
            'phone'=>['required_if:kind,push', 'nullable', 'string', 'regex:/^255[67][0-9]{8}$/'],
        ]);
        $path = $input['return_path'];
        abort_if(str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x20\x7f]/', $path)
            || parse_url($path, PHP_URL_HOST) !== null || parse_url($path, PHP_URL_SCHEME) !== null, 422, 'Invalid return path.');
        return response()->json($service->view($service->start($consumer, $input), $consumer));
    }

    public function show(Request $request, string $uuid, ConsumerPaymentService $service)
    {
        $consumer = $this->consumer($request);
        $payment = Payment::where('uuid', $uuid)->where('payment_consumer_id', $consumer->id)->firstOrFail();
        return response()->json($service->view($payment, $consumer));
    }
}
