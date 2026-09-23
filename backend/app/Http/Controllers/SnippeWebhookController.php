<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionPaidOrder;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\SnippeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class SnippeWebhookController extends Controller
{
    public function __invoke(Request $request, SnippeClient $snippe)
    {
        try {
            $event = $snippe->verifyWebhook(
                $request->getContent(),
                $request->header('X-Webhook-Timestamp'),
                $request->header('X-Webhook-Signature')
            );
        } catch (Throwable $e) {
            return response()->json(['message' => 'Invalid webhook signature.'], 400);
        }

        $eventId = $event['id'] ?? null;
        $type = $event['type'] ?? $request->header('X-Webhook-Event');
        $reference = data_get($event, 'data.reference');
        if (!$eventId || !$reference || !$type) return response()->json(['message'=>'Malformed webhook'], 422);
        if (PaymentEvent::where('event_id', $eventId)->exists()) return response()->json(['ok'=>true,'duplicate'=>true]);

        $payment = Payment::where('reference', $reference)->first();
        if (!$payment) return response()->json(['message' => 'Payment reference is not ready.'], 503);
        if ($type === 'payment.completed' && (
            !is_int(data_get($event, 'data.amount.value'))
            || data_get($event, 'data.amount.value') !== (int) $payment->amount
            || data_get($event, 'data.amount.currency') !== $payment->currency
            || (data_get($event, 'data.metadata.order_uuid') !== null
                && data_get($event, 'data.metadata.order_uuid') !== $payment->order->uuid)
        )) return response()->json(['message' => 'Payment details do not match the order.'], 422);

        $shouldProvision = DB::transaction(function () use ($payment, $event, $eventId, $reference, $type) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (PaymentEvent::where('event_id', $eventId)->exists()) return false;
            $shouldProvision = false;
            if ($type === 'payment.completed') {
                if ($payment->status !== 'completed') {
                    $order = $payment->order;
                    $payment->forceFill([
                        'status'=>'completed', 'completed_at'=>now(),
                        'external_reference'=>data_get($event,'data.external_reference'),
                        'provider_payload'=>$event,
                    ])->save();
                    if (!$order->paid_at) {
                        $order->forceFill(['status'=>'paid','paid_at'=>now()])->save();
                        $shouldProvision = true;
                    }
                }
            } elseif (in_array($type, ['payment.failed','payment.voided','payment.expired'], true) && $payment->status !== 'completed') {
                $payment->forceFill([
                    'status'=>str_replace('payment.','',$type),
                    'failed_reason'=>data_get($event,'data.failure_reason'),
                    'provider_payload'=>$event,
                ])->save();
            }
            PaymentEvent::create([
                'payment_id'=>$payment->id, 'event_id'=>$eventId, 'event_type'=>$type,
                'reference'=>$reference, 'payload'=>$event, 'processed_at'=>now(),
            ]);
            return $shouldProvision;
        });

        if ($shouldProvision) ProvisionPaidOrder::dispatch($payment->order_id)->afterResponse();
        return response()->json(['ok'=>true]);
    }
}
