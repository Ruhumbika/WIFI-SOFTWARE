<?php

namespace App\Services;

use App\Jobs\ProvisionPaidOrder;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ClickPesaPaymentReconciler
{
    public function reconcile(Payment $payment, ClickPesaClient $client): string
    {
        $transactions = $client->payments($payment->reference);
        $matching = array_values(array_filter($transactions, fn($item) => is_array($item)
            && ($item['orderReference'] ?? null) === $payment->reference
            && ($item['clientId'] ?? null) === (string) config('clickpesa.client_id')));
        if (count($matching) !== 1) throw new RuntimeException('ClickPesa payment reference is missing or ambiguous.');
        $result = $matching[0];
        $status = $result['status'] ?? null;
        if (!in_array($status, ['SUCCESS', 'SETTLED', 'PROCESSING', 'PENDING', 'FAILED'], true)) {
            throw new RuntimeException('Unexpected ClickPesa payment status.');
        }
        if (in_array($status, ['SUCCESS', 'SETTLED'], true) && (
            !is_numeric($result['collectedAmount'] ?? null)
            || !preg_match('/^\d+(?:\.0+)?$/', (string) $result['collectedAmount'])
            || (float) $result['collectedAmount'] !== (float) $payment->amount
            || ($result['collectedCurrency'] ?? null) !== $payment->currency
            || ($result['paymentPhoneNumber'] ?? null) !== $payment->order->customer_phone
        )) throw new RuntimeException('ClickPesa payment details do not match the order.');

        $shouldProvision = DB::transaction(function () use ($payment, $result, $status) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $order = $payment->order;
            if (in_array($status, ['SUCCESS', 'SETTLED'], true)) {
                if ($payment->status !== 'completed') {
                    $payment->forceFill([
                        'status' => 'completed', 'completed_at' => now(),
                        'external_reference' => $result['paymentReference'] ?? null,
                        'provider_payload' => $result,
                    ])->save();
                }
                if (!$order->paid_at) {
                    $order->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
                    $shouldProvision = true;
                } else $shouldProvision = false;
            } else {
                $shouldProvision = false;
                if ($status === 'FAILED' && $payment->status !== 'completed') {
                    $payment->forceFill(['status' => 'failed', 'failed_reason' => $result['message'] ?? null,
                        'provider_payload' => $result])->save();
                }
            }
            $eventId = 'clickpesa:'.($result['id'] ?? $payment->reference).':'.$status;
            PaymentEvent::firstOrCreate(['event_id' => $eventId], [
                'payment_id' => $payment->id, 'event_type' => $status,
                'reference' => $payment->reference, 'payload' => $result, 'processed_at' => now(),
            ]);
            return $shouldProvision;
        });
        if ($shouldProvision) ProvisionPaidOrder::dispatch($payment->order_id)->afterResponse();
        return $status;
    }
}
