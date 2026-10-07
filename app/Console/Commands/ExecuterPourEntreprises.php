<?php

namespace App\Console\Commands;

use App\Models\Plateforme\Entreprise;
use App\Plateforme\ProcessusEntreprise;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Lance une commande artisan pour chaque entreprise de la plateforme, une par
 * une, chacune dans son propre processus (voir ProcessusEntreprise).
 *
 *   php artisan entreprises:executer migrate --force     (à chaque mise à jour)
 *   php artisan entreprises:executer app:alerter-stock-sous-seuil
 *
 * Une entreprise en échec n'arrête pas les suivantes : elle est signalée et
 * la commande se termine en erreur, pour que la mise à jour ne passe jamais
 * pour réussie alors qu'un espace est resté sur l'ancien schéma.
 */
#[Signature('entreprises:executer {commande* : Commande artisan à lancer, avec ses options} {--toutes : Inclure les entreprises suspendues}')]
#[Description('Lance une commande artisan pour chaque entreprise de la plateforme')]
class ExecuterPourEntreprises extends Command
{
    public function handle(ProcessusEntreprise $processus): int
    {
        if (! plateforme()->estCentrale()) {
            $this->error("Commande à lancer depuis la plateforme, pas depuis l'espace d'une entreprise.");

            return self::FAILURE;
        }

        $entreprises = Entreprise::query()
            ->when(! $this->option('toutes'), fn ($q) => $q->where('statut', Entreprise::ACTIVE))
            ->where('statut', '!=', Entreprise::CREATION)
            ->orderBy('id')
            ->get();

        $echecs = 0;

        foreach ($entreprises as $entreprise) {
            $resultat = $processus->artisan($entreprise->sous_domaine, $this->argument('commande'));

            if ($resultat->successful()) {
                $this->line("<info>OK</info>     {$entreprise->sous_domaine}");
            } else {
                $echecs++;
                $this->line("<error>ÉCHEC</error>  {$entreprise->sous_domaine}");
                $this->line(trim($resultat->errorOutput()."\n".$resultat->output()));
            }
        }

        $this->newLine();
        $this->line($entreprises->count().' entreprise(s) traitée(s), '.$echecs.' en échec.');

        return $echecs === 0 ? self::SUCCESS : self::FAILURE;
    }
}
