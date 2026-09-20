<?php

namespace App\Console\Commands;

use App\Services\MikrotikRestClient;
use Illuminate\Console\Command;
use Throwable;

class RouterDoctor extends Command
{
    protected $signature = 'rjay:router-doctor';
    protected $description = 'Verify RouterOS REST connectivity and the HotSpot resources used by RJAY Hotspot.';

    public function handle(MikrotikRestClient $mikrotik): int
    {
        $this->line('Router URL: '.config('mikrotik.base_url'));
        $this->line('Router user: '.config('mikrotik.username'));
        $this->newLine();

        try {
            $resource = $mikrotik->resource();
            $this->info('✓ REST authentication works');
            $this->line('  RouterOS: '.($resource['version'] ?? 'unknown'));
            $this->line('  Board: '.($resource['board-name'] ?? 'unknown'));
        } catch (Throwable $e) {
            $this->error('✗ REST base test failed');
            $this->line($e->getMessage());
            $this->newLine();
            $this->warn('Fix this first. Do not run rjay:bootstrap-router until /rest/system/resource works.');
            return self::FAILURE;
        }

        foreach ([
            'HotSpot profiles' => fn () => $mikrotik->profiles(),
            'HotSpot users' => fn () => $mikrotik->hotspotUsers(),
            'Active sessions' => fn () => $mikrotik->activeSessions(),
        ] as $label => $probe) {
            try {
                $records = $probe();
                $this->info('✓ '.$label.' reachable ('.count($records).' records)');
            } catch (Throwable $e) {
                $this->error('✗ '.$label.' failed');
                $this->line($e->getMessage());
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('Router REST checks passed. You can run: php artisan rjay:bootstrap-router');
        return self::SUCCESS;
    }
}
