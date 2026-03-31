<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\FetchEmails;

Schedule::job(new FetchEmails)->everyTwoMinutes()->withoutOverlapping();
Schedule::command('followup:auto-send')->everyTwoHours()->withoutOverlapping();
Schedule::command('followup:pre-generate')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('calendar:sync')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('market:update')->everyFourHours()->withoutOverlapping();
