<?php

namespace App\Plateforme;

use App\Providers\PlateformeServiceProvider;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Exécute une commande artisan dans le contexte d'UNE entreprise, dans un
 * processus séparé.
 *
 * Un processus neuf par entreprise plutôt qu'un changement de base « à chaud »
 * dans le processus courant : la base de l'entreprise y est choisie dès le
 * démarrage (voir PlateformeServiceProvider), donc rien — connexion, cache,
 * permissions déjà chargées en mémoire — ne peut rester branché sur la base
 * d'une autre entreprise ou de la plateforme.
 */
class ProcessusEntreprise
{
    /**
     * @param  array<int, string>  $commande  commande artisan et ses arguments
     * @param  array<string, string>  $environnement  variables supplémentaires
     */
    public function artisan(string $sousDomaine, array $commande, array $environnement = [], int $delaiMaximal = 600): ProcessResult
    {
        return Process::path(base_path())
            ->timeout($delaiMaximal)
            ->env([PlateformeServiceProvider::VARIABLE_ENTREPRISE => $sousDomaine] + $environnement)
            ->run([$this->php(), 'artisan', ...$commande, '--no-interaction']);
    }

    private function php(): string
    {
        $php = config('plateforme.php') ?: (new PhpExecutableFinder)->find(false);

        if (! $php) {
            throw new RuntimeException("Exécutable PHP introuvable : renseignez PLATEFORME_PHP dans le fichier .env.");
        }

        return $php;
    }
}
