<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentConsumer;
use App\Services\SnippeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverConsumerSession extends Command
{
    protected $signature = 'payments:recover-session {payment} {session}';
    protected $description = 'Link a verified external session after an ambiguous provider timeout';

    public function handle(SnippeClient $client): int
    {
        $payment = Payment::where('uuid', $this->argument('payment'))->whereNotNull('payment_consumer_id')->firstOrFail();
        $consumer = PaymentConsumer::with('business')->findOrFail($payment->payment_consumer_id);
        $gateway = $payment->gatewayAccount;
        if (!$gateway || $gateway->business_id !== $payment->business_id || $consumer->business_id !== $payment->business_id) return self::FAILURE;
        try {
            $data = data_get($client->getSession($gateway, $this->argument('session')), 'data');
            if (!is_array($data) || ($data['reference'] ?? null) !== $this->argument('session')
                || ($data['amount'] ?? null) !== (int) $payment->amount || ($data['currency'] ?? null) !== $payment->currency
                || data_get($data, 'metadata.payment_uuid') !== $payment->uuid
                || data_get($data, 'metadata.business_uuid') !== $consumer->business->uuid
                || data_get($data, 'metadata.system') !== $consumer->code
                || data_get($data, 'metadata.source_reference') !== $payment->source_reference
                || ($data['collect_email'] ?? true) !== false || ($data['allow_custom_amount'] ?? true) !== false
                || ($data['allowed_methods'] ?? []) !== ['mobile_money']) throw new \RuntimeException();
            $url = $client->checkoutUrl($data['checkout_url'] ?? '');
            DB::transaction(function () use ($payment, $data, $url) {
                $locked = Payment::lockForUpdate()->findOrFail($payment->id);
                abort_if($locked->session_reference && $locked->session_reference !== $data['reference'], 409);
                $locked->forceFill(['session_reference'=>$data['reference'], 'checkout_url'=>$url])->save();
            });
        } catch (\Throwable $e) {
            $this->error('Could not verify the session. No new charge was sent.');
            return self::FAILURE;
        }
        $this->info('Session linked. Completion still requires its verified webhook. No push was resent.');
        return self::SUCCESS;
    }
}
