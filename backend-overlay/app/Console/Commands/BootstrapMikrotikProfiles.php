<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\MikrotikRestClient;
use Illuminate\Console\Command;
use Throwable;

class BootstrapMikrotikProfiles extends Command
{
    protected $signature = 'rjay:bootstrap-router';
    protected $description = 'Create/update RJAY HotSpot profiles on the MikroTik router.';

    public function handle(MikrotikRestClient $mikrotik): int
    {
        foreach (Plan::where('active', true)->get() as $plan) {
            try {
                $mikrotik->ensureProfile($plan);
                $this->info('Synced '.$plan->mikrotik_profile_name);
            } catch (Throwable $e) {
                $this->error($plan->name.': '.$e->getMessage());
                return self::FAILURE;
            }
        }
        return self::SUCCESS;
    }
}
