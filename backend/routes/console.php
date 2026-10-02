<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('rjay:sync-hotspot')->everyMinute()->withoutOverlapping()
    ->before(fn () => \Illuminate\Support\Facades\Cache::put('rjay:scheduler:last-attempt', now()->toIso8601String(), now()->addDays(30)))
    ->onSuccess(fn () => \Illuminate\Support\Facades\Cache::put('rjay:scheduler:last-success', now()->toIso8601String(), now()->addDays(30)))
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('Scheduled HotSpot synchronization failed.'));
