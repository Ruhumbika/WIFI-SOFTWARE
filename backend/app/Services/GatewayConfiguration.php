<?php
namespace App\Services;
use App\Models\Business;
use App\Models\PaymentGatewayAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class GatewayConfiguration
{
    public function save(Business $business, array $input, ?PaymentGatewayAccount $gateway = null): PaymentGatewayAccount
    {
        $data = Validator::make($input, [
            'api_key'=>[$gateway ? 'sometimes':'required','string','min:1','max:4096'],
            'webhook_secret'=>[$gateway ? 'sometimes':'required','string','min:1','max:4096'],
            'base_url'=>['required','url','max:255'], 'payment_profile_id'=>['required','string','max:255'],
            'active'=>['required','boolean'],
        ])->validate();
        try { app(SnippeClient::class)->assertUrl($data['base_url'], config('snippe.allowed_api_hosts')); }
        catch (\Throwable $e) { throw ValidationException::withMessages(['base_url'=>'Use the approved Snippe HTTPS API origin.']); }
        if (parse_url($data['base_url'],PHP_URL_QUERY) || parse_url($data['base_url'],PHP_URL_FRAGMENT)) throw ValidationException::withMessages(['base_url'=>'Use an API origin without query or fragment.']);
        if (!in_array(parse_url($data['base_url'],PHP_URL_PATH), [null,'','/'],true)) throw ValidationException::withMessages(['base_url'=>'Use an API origin without a path.']);
        return DB::transaction(function () use ($business,$gateway,$data) {
            Business::lockForUpdate()->findOrFail($business->id);
            if ($gateway) {
                $gateway = PaymentGatewayAccount::lockForUpdate()->findOrFail($gateway->id);
                abort_unless($gateway->business_id === $business->id,404);
                if ($gateway->payments()->where('status','pending')->exists() && (isset($data['api_key']) || isset($data['webhook_secret']) || $data['base_url'] !== $gateway->base_url)) {
                    throw ValidationException::withMessages(['gateway'=>'Resolve pending payments before changing merchant credentials.']);
                }
            }
            $uuid = $gateway?->uuid ?? (string) Str::uuid();
            $webhook = rtrim(config('snippe.webhook_base_url'),'/').'/api/webhooks/snippe/'.$uuid;
            try { app(SnippeClient::class)->assertUrl($webhook); }
            catch (\Throwable $e) { throw ValidationException::withMessages(['gateway'=>'Configure an HTTPS SNIPPE_WEBHOOK_BASE_URL first.']); }
            if ($data['active'] && $business->gatewayAccounts()->where('provider','snippe')->where('active',true)->when($gateway,fn($q)=>$q->where('id','!=',$gateway->id))->exists()) {
                throw ValidationException::withMessages(['active'=>'Disable the existing active Snippe gateway first.']);
            }
            $values = ['uuid'=>$uuid,'business_id'=>$business->id,'provider'=>'snippe','base_url'=>$data['base_url'],
                'webhook_url'=>$webhook,'payment_profile_id'=>$data['payment_profile_id'],'active'=>$data['active']];
            if (isset($data['api_key'])) $values['api_key_encrypted']=$data['api_key'];
            if (isset($data['webhook_secret'])) $values['webhook_secret_encrypted']=$data['webhook_secret'];
            if ($gateway) { $gateway->fill($values)->save(); return $gateway; }
            return PaymentGatewayAccount::create($values);
        });
    }
}
