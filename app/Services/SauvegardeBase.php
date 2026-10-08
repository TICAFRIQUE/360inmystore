<?php

namespace App\Services;

use Illuminate\Support\Collection;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Sauvegarde et restauration d'une base MySQL (mysqldump / mysql) : le seul
 * endroit qui sait construire ces commandes. Sert à la sauvegarde à la
 * demande (Paramètres), à la sauvegarde automatique de chaque soir
 * (app:sauvegarder) et à la restauration.
 *
 * Un fichier de sauvegarde porte toujours un nom de la forme
 * « {type}-AAAA-MM-JJ-HHMMSS.sql » : c'est ce motif, et lui seul, que la
 * purge et la restauration acceptent — jamais un fichier quelconque du
 * dossier.
 */
class SauvegardeBase
{
    /** Sauvegarde de chaque soir. */
    public const AUTO = 'auto';

    /** Sauvegarde lancée à la main. */
    public const MANUELLE = 'manuelle';

    /** État de la base juste avant une restauration, pour pouvoir y revenir. */
    public const AVANT_RESTAURATION = 'avant-restauration';

    /** Nombre de sauvegardes gardées, pour chaque type. */
    public const A_GARDER = 10;

    private const MOTIF = '/^(auto|manuelle|avant-restauration)-(\d{4})-(\d{2})-(\d{2})-(\d{2})(\d{2})(\d{2})\.sql$/';

    /**
     * Écrit une sauvegarde complète de la base dans $cheminFichier.
     *
     * @param  array<string, mixed>  $connexion  configuration de la connexion (host, port, username, password, database)
     *
     * @throws RuntimeException si mysqldump échoue
     */
    public function exporter(array $connexion, string $cheminFichier): void
    {
        $processus = $this->processus([
            $this->cheminBinaire('mysqldump'),
            '-h', (string) $connexion['host'],
            '-P', (string) $connexion['port'],
            '-u', (string) $connexion['username'],
            '--single-transaction',
            '--routines',
            (string) $connexion['database'],
        ], $connexion);

        // Écrit au fil de l'eau : la sauvegarde n'est jamais gardée entière en mémoire.
        $fichier = fopen($cheminFichier, 'w');
        $processus->run(function (string $type, string $tampon) use ($fichier) {
            if ($type === Process::OUT) {
                fwrite($fichier, $tampon);
            }
        });
        fclose($fichier);

        if (! $processus->isSuccessful()) {
            @unlink($cheminFichier);

            throw new RuntimeException($processus->getErrorOutput() ?: 'mysqldump a échoué sans message d\'erreur.');
        }
    }

    /**
     * Rejoue un fichier de sauvegarde dans la base : son contenu actuel est
     * remplacé. À l'appelant de faire une sauvegarde de sécurité avant.
     *
     * @param  array<string, mixed>  $connexion
     *
     * @throws RuntimeException si mysql échoue
     */
    public function importer(array $connexion, string $cheminFichier): void
    {
        $processus = $this->processus([
            $this->cheminBinaire('mysql'),
            '-h', (string) $connexion['host'],
            '-P', (string) $connexion['port'],
            '-u', (string) $connexion['username'],
            (string) $connexion['database'],
        ], $connexion);

        $processus->setInput(fopen($cheminFichier, 'r'));
        $processus->run();

        if (! $processus->isSuccessful()) {
            throw new RuntimeException($processus->getErrorOutput() ?: 'mysql a échoué sans message d\'erreur.');
        }
    }

    /**
     * Sauvegarde la base dans $dossier (créé au besoin), puis ne garde que
     * les plus récentes de ce type.
     *
     * @param  array<string, mixed>  $connexion
     * @return string chemin du fichier créé
     *
     * @throws RuntimeException
     */
    public function sauvegarder(array $connexion, string $dossier, string $type = self::AUTO, int $aGarder = self::A_GARDER): string
    {
        if (! is_dir($dossier) && ! @mkdir($dossier, 0750, true) && ! is_dir($dossier)) {
            throw new RuntimeException("Impossible de créer le dossier de sauvegarde : {$dossier}");
        }

        $chemin = rtrim($dossier, '\\/').DIRECTORY_SEPARATOR.$type.'-'.now()->format('Y-m-d-His').'.sql';

        $this->exporter($connexion, $chemin);
        $this->purger($dossier, $type, $aGarder);

        return $chemin;
    }

    /** Dossier des sauvegardes d'une base, hors de tout dossier public. */
    public function dossier(string $base): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'sauvegardes'.DIRECTORY_SEPARATOR.preg_replace('/[^A-Za-z0-9_\-]/', '_', $base));
    }

    /**
     * Sauvegardes présentes dans le dossier, les plus récentes d'abord.
     *
     * @return Collection<int, array{nom: string, chemin: string, type: string, date: \Illuminate\Support\Carbon, taille: int}>
     */
    public function lister(string $dossier): Collection
    {
        if (! is_dir($dossier)) {
            return collect();
        }

        return collect(scandir($dossier))
            ->map(function (string $nom) use ($dossier) {
                if (! preg_match(self::MOTIF, $nom, $m)) {
                    return null;
                }

                $chemin = rtrim($dossier, '\\/').DIRECTORY_SEPARATOR.$nom;

                return [
                    'nom' => $nom,
                    'chemin' => $chemin,
                    'type' => $m[1],
                    'date' => now()->setDate((int) $m[2], (int) $m[3], (int) $m[4])->setTime((int) $m[5], (int) $m[6], (int) $m[7]),
                    'taille' => (int) filesize($chemin),
                ];
            })
            ->filter()
            ->sortByDesc('nom')
            ->sortByDesc(fn (array $sauvegarde) => $sauvegarde['date']->getTimestamp())
            ->values();
    }

    /**
     * Chemin d'une sauvegarde du dossier d'après son nom, ou null si ce nom
     * n'est pas celui d'une sauvegarde (jamais un chemin fourni tel quel par
     * un visiteur).
     */
    public function trouver(string $dossier, string $nom): ?string
    {
        return $this->lister($dossier)->firstWhere('nom', $nom)['chemin'] ?? null;
    }

    /**
     * Supprime les sauvegardes de ce type au-delà des $aGarder plus récentes.
     *
     * @return int nombre de fichiers supprimés
     */
    public function purger(string $dossier, string $type, int $aGarder = self::A_GARDER): int
    {
        return $this->lister($dossier)
            ->where('type', $type)
            ->slice(max(0, $aGarder))
            ->each(fn (array $sauvegarde) => @unlink($sauvegarde['chemin']))
            ->count();
    }

    /**
     * Chemin de mysqldump ou de mysql : celui du fichier .env
     * (MYSQLDUMP_PATH / MYSQL_PATH) s'il est renseigné ; sinon le dossier
     * Laragon habituel sous Windows (le numéro de version change à chaque
     * mise à jour, d'où la recherche) ; sinon le simple nom, que le système
     * trouve lui-même sur un serveur Linux.
     */
    public function cheminBinaire(string $executable): string
    {
        $configure = config('database.'.$executable.'_path');

        if ($configure) {
            return $configure;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $trouves = glob("C:\\laragon\\bin\\mysql\\*\\bin\\{$executable}.exe");

            if (! empty($trouves)) {
                // Le plus récent, si plusieurs versions sont installées côte à côte.
                rsort($trouves);

                return $trouves[0];
            }
        }

        return $executable;
    }

    /**
     * Le mot de passe passe par l'environnement du sous-processus, jamais par
     * la ligne de commande (visible des autres comptes du serveur). Cet
     * environnement s'ajoute à celui du processus courant : le remplacer
     * priverait mysqldump.exe de ce qu'il lui faut pour démarrer sous Windows.
     *
     * @param  list<string>  $commande
     * @param  array<string, mixed>  $connexion
     */
    private function processus(array $commande, array $connexion): Process
    {
        $processus = new Process($commande, env: getenv() + ['MYSQL_PWD' => (string) $connexion['password']]);
        $processus->setTimeout(null);

        return $processus;
    }
}
