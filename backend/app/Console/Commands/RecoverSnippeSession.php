<?php
namespace App\Console\Commands;
use App\Models\Payment;
use App\Services\SnippeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class RecoverSnippeSession extends Command
{
    protected $signature = 'snippe:recover-session {payment : Local payment UUID} {session : Snippe session reference}';
    protected $description = 'Verify and link an ambiguous hosted session without creating or completing a payment';
    public function handle(SnippeClient $client): int
    {
        $payment=Payment::where('uuid',$this->argument('payment'))->where('provider','snippe')->firstOrFail();
        $gateway=$payment->gatewayAccount;
        if (!$gateway || $payment->business_id !== $gateway->business_id || $payment->order->business_id !== $gateway->business_id) {
            $this->error('Merchant mapping does not match.'); return self::FAILURE;
        }
        try { $data=data_get($client->getSession($gateway,$this->argument('session')),'data'); }
        catch (\Throwable $e) { $this->error('Could not verify the session with Snippe.'); return self::FAILURE; }
        if (!is_array($data) || ($data['reference']??null)!==$this->argument('session')
            || ($data['amount']??null)!==(int)$payment->amount || ($data['currency']??null)!==$payment->currency
            || data_get($data,'metadata.payment_uuid')!==$payment->uuid || data_get($data,'metadata.order_uuid')!==$payment->order->uuid
            || data_get($data,'metadata.business_uuid')!==$gateway->business->uuid || data_get($data,'metadata.system')!=='wifi'
            || ($data['collect_email']??true)!==false || ($data['allowed_methods']??[])!==['mobile_money']) {
            $this->error('Session details do not match.'); return self::FAILURE;
        }
        try { $url=$client->checkoutUrl($data['checkout_url']??''); }
        catch (\Throwable $e) { $this->error('Invalid checkout URL.'); return self::FAILURE; }
        DB::transaction(function () use ($payment,$data,$url) {
            $locked=Payment::lockForUpdate()->findOrFail($payment->id);
            abort_if($locked->session_reference || $locked->status!=='pending',409,'Payment already has a session or is no longer pending.');
            $locked->forceFill(['session_reference'=>$data['reference'],'checkout_url'=>$url,'provider_payload'=>['session_reference'=>$data['reference'],'status'=>$data['status']??null]])->save();
        });
        $this->info('Session linked. Payment is still pending; replay the signed webhook if already paid.');
        return self::SUCCESS;
    }
}
