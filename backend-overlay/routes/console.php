<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('rjay:sync-hotspot')->everyMinute()->withoutOverlapping();
