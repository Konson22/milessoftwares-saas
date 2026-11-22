<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retry events for shared modules (Finance, HR, Payroll) that are due.
Schedule::command('platform:outbox')->everyMinute()->withoutOverlapping();

// Logbook's clock (ADR-0026): recurring tasks, expected entries, missed ones, reminders.
Schedule::command('logbook:operations')->everyMinute()->withoutOverlapping(10);
