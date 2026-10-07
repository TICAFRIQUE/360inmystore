<?php

namespace App\Plateforme;

use App\Models\Plateforme\Entreprise;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Ouverture de l'espace d'une nouvelle entreprise : fiche dans le registre de
 * la plateforme, base de données dédiée, référentiel de départ et compte du
 * propriétaire. L'abonnement n'est pas posé ici : le propriétaire choisit sa
 * formule à sa première connexion (voir PaiementAbonnementController), et
 * l'espace reste limité à cette page tant qu'il ne l'a pas fait.
 *
 * Tout ou rien : si une étape échoue, la base créée et la fiche sont
 * supprimées — l'adresse et le numéro de téléphone redeviennent disponibles
 * pour une nouvelle tentative, sans intervention manuelle.
 */
class CreationEntreprise
{
    public const VARIABLE_DONNEES = 'PLATEFORME_INITIALISATION';

    public function __construct(private ProcessusEntreprise $processus) {}

    /**
     * @param  array{nom: string, sous_domaine: string, proprietaire_nom: string, telephone: string, pays: string, username: string, mot_de_passe: string, ip?: ?string}  $donnees
     *   téléphone déjà au format international (voir App\Support\Telephone)
     *
     * @throws RuntimeException si l'espace n'a pas pu être préparé
     */
    public function creer(array $donnees): Entreprise
    {
        $base = $this->nomDeBase($donnees['sous_domaine']);

        $entreprise = Entreprise::create([
            'nom' => $donnees['nom'],
            'sous_domaine' => $donnees['sous_domaine'],
            'base' => $base,
            'proprietaire_nom' => $donnees['proprietaire_nom'],
            'telephone' => $donnees['telephone'],
            'pays' => $donnees['pays'],
            'statut' => Entreprise::CREATION,
            'ip_inscription' => $donnees['ip'] ?? null,
        ]);

        $baseCreee = false;

        try {
            // Jamais « IF NOT EXISTS » : une base portant déjà ce nom n'est
            // pas la nôtre, il ne faut ni l'utiliser ni, en cas d'échec, la
            // supprimer.
            DB::statement("CREATE DATABASE `{$base}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $baseCreee = true;

            $resultat = $this->processus->artisan($entreprise->sous_domaine, ['entreprises:initialiser'], [
                // Par l'environnement du processus, pas en argument de ligne
                // de commande : un mot de passe en argument apparaîtrait dans
                // la liste des processus du serveur.
                self::VARIABLE_DONNEES => json_encode([
                    'nom' => $donnees['nom'],
                    'pays' => $donnees['pays'],
                    'telephone' => $donnees['telephone'],
                    'proprietaire_nom' => $donnees['proprietaire_nom'],
                    'username' => $donnees['username'],
                    'mot_de_passe' => $donnees['mot_de_passe'],
                ], JSON_THROW_ON_ERROR),
            ]);

            if ($resultat->failed()) {
                throw new RuntimeException(trim($resultat->errorOutput()."\n".$resultat->output()));
            }
        } catch (Throwable $erreur) {
            Log::error("Création de l'espace « {$entreprise->sous_domaine} » abandonnée : ".$erreur->getMessage());

            if ($baseCreee) {
                DB::statement("DROP DATABASE IF EXISTS `{$base}`");
            }
            $entreprise->delete();

            throw new RuntimeException("L'espace n'a pas pu être créé.", previous: $erreur);
        }

        $entreprise->update(['statut' => Entreprise::ACTIVE]);

        return $entreprise;
    }

    /**
     * Le sous-domaine est validé en amont (lettres minuscules, chiffres,
     * tirets) ; on le revérifie ici, puisqu'il finit dans une instruction SQL
     * où aucun paramètre lié n'est possible (nom de base de données).
     */
    private function nomDeBase(string $sousDomaine): string
    {
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $sousDomaine) !== 1) {
            throw new RuntimeException('Sous-domaine invalide.');
        }

        return config('plateforme.prefixe_base').str_replace('-', '_', $sousDomaine);
    }
}
