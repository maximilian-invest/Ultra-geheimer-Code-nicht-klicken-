<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\FetchEmails;

Schedule::job(new FetchEmails)->everyTwoMinutes()->withoutOverlapping();
Schedule::command('followup:auto-send')->everyTwoHours()->withoutOverlapping();
Schedule::command('followup:pre-generate')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('calendar:sync')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('market:update')->everyFourHours()->withoutOverlapping();

Schedule::command('links:purge-old-sessions')->dailyAt('03:15');

// Tagesbriefing — KI-Zusammenfassung pro User, täglich 06:30 Europe/Vienna
Schedule::command('briefing:generate-daily')
    ->dailyAt('06:30')
    ->timezone('Europe/Vienna')
    ->withoutOverlapping();
