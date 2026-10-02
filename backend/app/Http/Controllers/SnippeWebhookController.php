<?php
namespace App\Http\Controllers;

use App\Jobs\ProvisionPaidOrder;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentGatewayAccount;
use App\Services\SnippeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SnippeWebhookController extends Controller
{
    public function legacy(Request $request, SnippeClient $client)
    {
        // Historical callback URLs can only resolve an explicitly linked legacy payment.
        $reference = $request->input('data.reference');
        abort_unless(is_string($reference), 422);
        $payments = Payment::where('provider','snippe')->whereNull('session_reference')->where('reference',$reference)->whereNotNull('payment_gateway_account_id')->get();
        abort_unless($payments->count() === 1, 503, 'Legacy merchant mapping is required.');
        return $this->__invoke($request, $payments->first()->gatewayAccount, $client);
    }
    public function __invoke(Request $request, PaymentGatewayAccount $gateway, SnippeClient $client)
    {
        try { $event = $client->verifyWebhook($gateway, $request->getContent(), $request->header('X-Webhook-Timestamp'), $request->header('X-Webhook-Signature')); }
        catch (\Throwable $e) { return response()->json(['message'=>'Invalid webhook signature.'],400); }
        $type = $event['type'] ?? null;
        $reference = data_get($event,'data.reference');
        $session = data_get($event,'data.session_reference');
        abort_unless(is_string($reference) && $reference !== '' && strlen($reference) <= 255
            && is_string($type) && in_array($type,['payment.completed','payment.failed','payment.expired','payment.voided'],true)
            && ($session === null || (is_string($session) && trim($session) !== '' && strlen($session) <= 255)), 422, 'Malformed webhook.');
        // Sessions documentation omits event IDs; the signed payment reference + type is a stable fallback.
        abort_if(isset($event['id']) && (!is_string($event['id']) || strlen($event['id']) > 255),422,'Invalid event ID.');
        $eventId = 'snippe:'.hash('sha256', $gateway->uuid.'|'.($event['id'] ?? ($type.'|'.$reference.'|'.$session)));
        $result = DB::transaction(function () use ($gateway,$event,$eventId,$reference,$session,$type) {
            $query = Payment::where('provider','snippe')->where('payment_gateway_account_id',$gateway->id);
            $paymentUuid = data_get($event,'data.metadata.payment_uuid');
            if ($session !== null) {
                $payment = $query->where('session_reference',$session)->first();
            } else {
                // Signed metadata identifies hosted attempts whose provider reference is not stored yet.
                abort_if($paymentUuid !== null && (!is_string($paymentUuid) || !\Illuminate\Support\Str::isUuid($paymentUuid)),422,'Invalid payment metadata.');
                $payment = $paymentUuid !== null
                    ? (clone $query)->whereNotNull('session_reference')->where('uuid',$paymentUuid)->first()
                    : null;
                if (!$payment) $payment = $query->whereNull('session_reference')->where('reference',$reference)->first();
            }
            abort_unless($payment, 503, 'Payment reference is not ready.');
            $order = Order::lockForUpdate()->findOrFail($payment->order_id);
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            abort_unless($payment->business_id === $gateway->business_id && $order->business_id === $gateway->business_id
                && (int) $payment->amount === (int) $order->amount && $payment->currency === $order->currency
                && is_int(data_get($event,'data.amount.value')) && data_get($event,'data.amount.value') === (int) $payment->amount
                && data_get($event,'data.amount.currency') === $payment->currency, 422, 'Payment details do not match.');
            foreach (['order_uuid'=>$order->uuid,'payment_uuid'=>$payment->uuid,'business_uuid'=>$gateway->business->uuid,'system'=>'wifi'] as $key=>$expected) {
                $actual = data_get($event,'data.metadata.'.$key);
                abort_if($actual !== null && $actual !== $expected,422,'Payment metadata does not match.');
            }
            if ($type === 'payment.completed') abort_unless(data_get($event,'data.status') === 'completed',422,'Payment status does not match.');
            $settlement = $type === 'payment.completed'
                ? app(\App\Services\PaymentSettlementService::class)->validate($payment, data_get($event,'data.settlement')) : [];
            if (PaymentEvent::where('event_id',$eventId)->exists()) return ['duplicate'=>true];
            if ($payment->status === 'completed') {
                abort_unless($payment->reference === $reference,422,'Completed payment reference does not match.');
                if ($settlement) $payment->forceFill($settlement)->save();
            } elseif ($type === 'payment.completed') {
                $payment->forceFill(['reference'=>$reference,'status'=>'completed','completed_at'=>now(),
                    'provider_payload'=>['type'=>$type,'reference'=>$reference,'session_reference'=>$session]] + $settlement)->save();
                if (!$order->paid_at) {
                    $order->forceFill(['status'=>'paid','paid_at'=>now()])->save();
                    ProvisionPaidOrder::dispatch($order->id)->afterCommit();
                }
            } elseif (!$payment->session_reference) {
                $payment->forceFill(['status'=>substr($type,8)])->save();
            }
            // A failed hosted-checkout attempt does not expire its reusable session.
            PaymentEvent::create(['payment_id'=>$payment->id,'event_id'=>$eventId,'event_type'=>$type,'reference'=>$reference,
                'payload'=>['type'=>$type,'reference'=>$reference,'session_reference'=>$session], 'processed_at'=>now()]);
            return ['duplicate'=>false];
        });
        return response()->json(['ok'=>true]+$result);
    }
}
