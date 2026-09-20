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
        try {
            $resource = $mikrotik->resource();
            $this->info(sprintf(
                'Connected to %s · RouterOS %s',
                $resource['board-name'] ?? 'MikroTik',
                $resource['version'] ?? 'unknown'
            ));
        } catch (Throwable $e) {
            $this->error('Router REST preflight failed: '.$e->getMessage());
            $this->warn('Run `php artisan rjay:router-doctor` after fixing credentials/REST access.');
            return self::FAILURE;
        }

        foreach (Plan::where('active', true)->get() as $plan) {
            try {
                $mikrotik->ensureProfile($plan);
                $this->info('✓ Synced '.$plan->mikrotik_profile_name);
            } catch (Throwable $e) {
                $this->error($plan->name.': '.$e->getMessage());
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
