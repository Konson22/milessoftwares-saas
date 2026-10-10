<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('platform:expire-invitations')->hourly()->withoutOverlapping();
