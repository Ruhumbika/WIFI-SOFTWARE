<?php
namespace App\Services;
use App\Models\Payment;
final class PaymentSettlementService
{
    public function validate(Payment $payment, mixed $settlement): array
    {
        if ($settlement === null) return [];
        abort_unless(is_array($settlement), 422, 'Invalid settlement.');
        foreach (['gross','fees','net'] as $name) {
            $value = data_get($settlement, "$name.value");
            abort_unless(is_int($value) && $value >= 0 && data_get($settlement, "$name.currency") === $payment->currency, 422, 'Settlement details do not match.');
        }
        $gross = $settlement['gross']['value']; $fee = $settlement['fees']['value']; $net = $settlement['net']['value'];
        abort_unless($gross === (int) $payment->amount && $fee <= $gross && $net === $gross - $fee, 422, 'Settlement amounts do not match.');
        $values = ['gross_amount'=>$gross,'fee_amount'=>$fee,'net_amount'=>$net,'settlement_currency'=>$payment->currency];
        // Once confirmed, settlement cannot be overwritten by a conflicting delivery.
        foreach ($values as $key=>$value) abort_if($payment->$key !== null && $payment->$key !== $value, 422, 'Settlement conflicts with recorded values.');
        return $values;
    }
}
