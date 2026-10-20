<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('platform:expire-invitations')->hourly()->withoutOverlapping();
Schedule::command('platform:retry-webhooks')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('platform:audit-export')->hourly()->withoutOverlapping();
Schedule::command('platform:audit-verify')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('platform:sync-plan-features')->dailyAt('04:00')->withoutOverlapping();
// Raises QueueBusy (logged as critical) when the backlog stops draining.
Schedule::command('queue:monitor', ['redis:default', '--max' => (int) config('platform.queue_monitor_max')])->everyMinute();
