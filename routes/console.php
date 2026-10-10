<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('platform:expire-invitations')->hourly()->withoutOverlapping();
Schedule::command('platform:retry-webhooks')->everyFiveMinutes()->withoutOverlapping();
