<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:alerter-sessions-ouvertes-trop-longtemps')->hourly();
Schedule::command('app:alerter-stock-sous-seuil')->hourly();

// Sauvegarde de la base chaque soir, 10 gardées (voir app:sauvegarder).
Schedule::command('app:sauvegarder')->dailyAt('23:30');
