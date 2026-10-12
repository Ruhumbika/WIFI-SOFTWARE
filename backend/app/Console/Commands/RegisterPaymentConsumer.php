<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\PaymentConsumer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RegisterPaymentConsumer extends Command
{
    protected $signature = 'payments:register-consumer {business} {code} {origin}';
    protected $description = 'Bind an application token to its business using a hidden prompt';

    public function handle(): int
    {
        $origin = rtrim($this->argument('origin'), '/');
        $parts = parse_url($origin);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !empty($parts['path']) || (isset($parts['port']) && $parts['port'] !== 443)
            || !preg_match('/^[A-Z0-9_]{2,50}$/D', $this->argument('business'))
            || !preg_match('/^[a-z0-9_-]{2,50}$/D', $this->argument('code'))) {
            $this->error('Use a HTTPS origin, uppercase business code and lowercase installation code.');
            return self::FAILURE;
        }
        $token = $this->secret('Application service token (32–256 characters; not a Snippe API key)');
        if (!is_string($token) || strlen($token) < 32 || strlen($token) > 256) return self::FAILURE;
        $business = Business::firstOrCreate(['code'=>$this->argument('business')], [
            'uuid'=>(string) Str::uuid(), 'name'=>$this->argument('business'), 'status'=>'active',
        ]);
        $existing = PaymentConsumer::where('code', $this->argument('code'))->first();
        if ($existing && $existing->business_id !== $business->id) {
            $this->error('An existing installation cannot be reassigned to another business.');
            return self::FAILURE;
        }
        PaymentConsumer::updateOrCreate(['code'=>$this->argument('code')], [
            'uuid'=>$existing?->uuid ?? (string) Str::uuid(), 'business_id'=>$business->id,
            'token_hash'=>hash('sha256', $token), 'return_origin'=>$origin, 'active'=>true,
        ]);
        $this->info('Application identity registered. Configure its own Snippe gateway separately.');
        return self::SUCCESS;
    }
}
