<?php
namespace App\Services;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
final class HotspotSyncHealth
{
    public function snapshot(): array {
        $attempt = Cache::get('rjay:scheduler:last-attempt');
        $age = $attempt ? CarbonImmutable::parse($attempt)->diffInSeconds(now(),false) : null;
        return ['scheduler_status'=>$age === null ? 'not_recorded' : ($age <= 180 ? 'healthy' : 'delayed'),
            'last_scheduler_attempt'=>$attempt,'last_scheduler_success'=>Cache::get('rjay:scheduler:last-success'),
            'last_successful_sync'=>Cache::get('rjay:hotspot:last-successful-sync'),
            'last_sync_result'=>Cache::get('rjay:hotspot:last-result')];
    }
}
