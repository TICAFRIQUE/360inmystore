<?php

namespace App\Plateforme\Paiement;

use App\Models\FormuleAbonnement;
use App\Models\Plateforme\Entreprise;
use App\Models\Plateforme\PaiementAbonnement;
use App\Models\User;
use App\Plateforme\ProcessusEntreprise;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Activation d'un abonnement par l'éditeur, depuis sa console : un client a
 * payé en direct (espèces, Mobile Money, virement…) ou reçoit des jours
 * offerts. C'est la voie normale tant que le paiement en ligne est simulé.
 *
 * L'activation se fait dans la base de l'entreprise, donc dans un processus
 * séparé (voir ProcessusEntreprise) ; le paiement n'est inscrit au registre
 * de la plateforme qu'une fois l'abonnement réellement activé — l'argent est
 * déjà reçu, il ne doit jamais apparaître comme encaissé pour un espace
 * resté fermé.
 */
class ActivationParEditeur
{
    public const VARIABLE_DONNEES = 'PLATEFORME_ACTIVATION';

    /** Moyens par lesquels l'éditeur peut avoir été réglé en direct : code => libellé. */
    public const MOYENS = [
        'especes' => 'Espèces',
        'mobile_money' => 'Mobile Money',
        'virement' => 'Virement',
        'cheque' => 'Chèque',
        'offert' => 'Offert (sans paiement)',
    ];

    public function __construct(private ProcessusEntreprise $processus) {}

    /**
     * @param  FormuleAbonnement|null  $formule  formule du catalogue, ou null pour une durée et un montant libres
     *
     * @throws RuntimeException si l'abonnement n'a pas pu être activé dans l'espace
     */
    public function activer(
        Entreprise $entreprise,
        ?FormuleAbonnement $formule,
        int $montant,
        ?int $jours,
        bool $illimite,
        string $moyen,
        bool $remplacer,
        User $editeur,
    ): PaiementAbonnement {
        $reference = 'MAN-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));

        $resultat = $this->processus->artisan($entreprise->sous_domaine, ['entreprises:activer-abonnement'], [
            self::VARIABLE_DONNEES => json_encode([
                'formule' => $formule?->only(['nom', 'jours', 'illimite', 'prix', 'max_magasins', 'max_caisses', 'max_utilisateurs', 'max_produits']),
                'montant' => $montant,
                'jours' => $illimite ? null : $jours,
                'illimite' => $illimite,
                'remplacer' => $remplacer,
                'note' => 'Activé par '.config('app.name').' ('.self::MOYENS[$moyen].') '.$reference,
            ], JSON_THROW_ON_ERROR),
        ]);

        // La commande termine par une ligne JSON décrivant l'abonnement activé.
        $activation = json_decode((string) collect(preg_split('/\R/', trim($resultat->output())))->last(), true);

        if ($resultat->failed() || ! is_array($activation)) {
            throw new RuntimeException(trim($resultat->errorOutput()."\n".$resultat->output()) ?: "L'activation a échoué.");
        }

        $paiement = PaiementAbonnement::create([
            'entreprise_id' => $entreprise->id,
            'formule_nom' => $formule?->nom ?? 'Durée libre',
            'jours' => $illimite ? null : $jours,
            'illimite' => $illimite,
            'montant' => $montant,
            'moyen' => $moyen,
            'passerelle' => 'manuel',
            'reference' => $reference,
            'statut' => PaiementAbonnement::REUSSI,
            'payeur' => 'Enregistré par '.$editeur->name,
        ]);

        $entreprise->recopierAbonnement($formule?->nom ?? 'Durée libre', $activation['date_fin'], (bool) $activation['illimite']);

        return $paiement;
    }
}
