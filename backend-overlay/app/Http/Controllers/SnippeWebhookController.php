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
            return response()->json(['message'=>$e->getMessage()], 400);
        }

        $eventId = $event['id'] ?? null;
        $type = $event['type'] ?? $request->header('X-Webhook-Event');
        $reference = data_get($event, 'data.reference');
        if (!$eventId || !$reference || !$type) return response()->json(['message'=>'Malformed webhook'], 422);
        if (PaymentEvent::where('event_id', $eventId)->exists()) return response()->json(['ok'=>true,'duplicate'=>true]);

        $payment = Payment::where('reference', $reference)->first();
        PaymentEvent::create([
            'payment_id'=>$payment?->id, 'event_id'=>$eventId, 'event_type'=>$type,
            'reference'=>$reference, 'payload'=>$event, 'processed_at'=>now(),
        ]);
        if (!$payment) return response()->json(['ok'=>true,'payment_found'=>false]);

        DB::transaction(function () use ($payment, $event, $type) {
            if ($type === 'payment.completed') {
                $payment->forceFill([
                    'status'=>'completed', 'completed_at'=>now(),
                    'external_reference'=>data_get($event,'data.external_reference'),
                    'provider_payload'=>$event,
                ])->save();
                $payment->order->forceFill(['status'=>'paid','paid_at'=>now()])->save();
            } elseif (in_array($type, ['payment.failed','payment.voided','payment.expired'], true)) {
                $payment->forceFill([
                    'status'=>str_replace('payment.','',$type),
                    'failed_reason'=>data_get($event,'data.failure_reason'),
                    'provider_payload'=>$event,
                ])->save();
            }
        });

        if ($type === 'payment.completed') ProvisionPaidOrder::dispatch($payment->order_id)->afterResponse();
        return response()->json(['ok'=>true]);
    }
}
