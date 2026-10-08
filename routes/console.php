<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ces alertes portent sur les données d'un commerce : la plateforme les
// lance pour chaque entreprise, chacune dans son propre processus (voir
// entreprises:executer). Le planificateur ne tourne que sur la plateforme,
// jamais « dans » une entreprise.
Schedule::command('entreprises:executer app:alerter-sessions-ouvertes-trop-longtemps')->hourly();
Schedule::command('entreprises:executer app:alerter-stock-sous-seuil')->hourly();

// Sauvegarde de chaque soir, 10 gardées (voir app:sauvegarder) : la base de
// la plateforme, puis celle de chaque entreprise — suspendues comprises, leurs
// données sont conservées. Une sauvegarde qui échoue n'arrête pas les suivantes.
Schedule::command('app:sauvegarder')->dailyAt('23:30');
Schedule::command('entreprises:executer --toutes app:sauvegarder')->dailyAt('23:40');
