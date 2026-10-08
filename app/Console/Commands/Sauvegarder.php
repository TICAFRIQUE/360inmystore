<?php

namespace App\Console\Commands;

use App\Models\Parametre;
use App\Services\SauvegardeBase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Sauvegarde automatique de la base : lancée chaque soir par le planificateur
 * (voir routes/console.php), elle ne garde que les 10 plus récentes.
 *
 * Les fichiers vont dans le dossier de sauvegarde réglé dans Paramètres s'il
 * y en a un (version installée : un disque externe, un dossier synchronisé),
 * sinon dans storage/app/sauvegardes/{base}.
 */
#[Signature('app:sauvegarder {--garder=10 : Nombre de sauvegardes automatiques à conserver}')]
#[Description('Sauvegarde la base de données et ne garde que les plus récentes')]
class Sauvegarder extends Command
{
    public function handle(SauvegardeBase $sauvegarde): int
    {
        $connexion = config('database.connections.'.config('database.default'));

        if (($connexion['driver'] ?? null) !== 'mysql') {
            $this->error('Sauvegarde uniquement disponible pour une base MySQL.');

            return self::FAILURE;
        }

        try {
            $chemin = $sauvegarde->sauvegarder($connexion, $this->dossier($sauvegarde, $connexion['database']), SauvegardeBase::AUTO, max(1, (int) $this->option('garder')));
        } catch (\Throwable $e) {
            report($e);
            $this->error('Sauvegarde impossible : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sauvegarde enregistrée : {$chemin}");

        return self::SUCCESS;
    }

    private function dossier(SauvegardeBase $sauvegarde, string $base): string
    {
        // La table peut manquer sur une base tout juste créée.
        $regle = Schema::hasTable('parametres') ? Parametre::query()->value('dossier_sauvegarde') : null;

        return $regle && is_dir($regle) && is_writable($regle) ? $regle : $sauvegarde->dossier($base);
    }
}
