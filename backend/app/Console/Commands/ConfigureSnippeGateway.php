<?php
namespace App\Console\Commands;
use App\Models\Business;
use App\Models\Payment;
use App\Services\GatewayConfiguration;
use Illuminate\Console\Command;
class ConfigureSnippeGateway extends Command
{
    protected $signature = 'snippe:configure {business=RJAY_WIFI} {--gateway= : Existing gateway UUID} {--link-legacy : Link unmapped historical Snippe payments after confirming merchant ownership}';
    protected $description = 'Configure encrypted merchant credentials using hidden prompts';
    public function handle(GatewayConfiguration $configuration): int
    {
        $business = Business::where('code',$this->argument('business'))->firstOrFail();
        $gateway = $this->option('gateway') ? $business->gatewayAccounts()->where('uuid',$this->option('gateway'))->firstOrFail() : null;
        $input = ['base_url'=>$this->ask('Snippe API origin','https://api.snippe.sh'),
            'payment_profile_id'=>$this->ask('Payment profile ID (collect_email=false, mobile_money only)'),
            'active'=>$this->confirm('Enable this gateway?',true)];
        $key=$this->secret($gateway ? 'New API key (leave blank to keep)' : 'API key');
        $secret=$this->secret($gateway ? 'New webhook secret (leave blank to keep)' : 'Webhook secret');
        if ($key) $input['api_key']=$key;
        if ($secret) $input['webhook_secret']=$secret;
        $gateway=$configuration->save($business,$input,$gateway);
        $this->info('Gateway: '.$gateway->uuid); $this->info('Webhook: '.$gateway->webhook_url);
        if ($this->option('link-legacy') && $this->confirm('I verified that ALL unmapped historical Snippe payments for this business belong to this exact merchant account. Link them?',false)) {
            $count=Payment::where('business_id',$business->id)->where('provider','snippe')->whereNull('payment_gateway_account_id')->whereNull('session_reference')->update(['payment_gateway_account_id'=>$gateway->id]);
            $this->info('Linked payments: '.$count);
        }
        return self::SUCCESS;
    }
}
