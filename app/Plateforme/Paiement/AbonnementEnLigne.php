<?php

namespace App\Plateforme\Paiement;

use App\Models\Abonnement;
use App\Models\AbonnementActivation;
use App\Models\FormuleAbonnement;
use App\Models\Plateforme\Entreprise;
use App\Models\Plateforme\PaiementAbonnement;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Souscription d'une formule par une entreprise, depuis son propre espace :
 * essai gratuit de démarrage, ou paiement en ligne — encaissement par la
 * passerelle, inscription au registre des paiements de la plateforme, puis
 * activation de l'abonnement dans la base de l'entreprise.
 *
 * Le registre et l'activation vivent dans deux bases différentes : pas de
 * transaction commune possible. Le paiement est donc toujours enregistré
 * AVANT l'activation — si celle-ci échouait, il resterait une trace du
 * paiement reçu à régulariser, jamais un abonnement prolongé sans paiement.
 */
class AbonnementEnLigne
{
    public function __construct(private PasserellePaiement $passerelle) {}

    /**
     * L'essai gratuit (formule à 0 F du catalogue) n'est proposé qu'au
     * démarrage : tant que l'espace n'a jamais eu d'abonnement.
     */
    public function essaiDisponible(): bool
    {
        return ! AbonnementActivation::query()->exists();
    }

    /**
     * @param  FormuleAbonnement  $formule  formule gratuite du catalogue de la plateforme
     *
     * @throws RuntimeException si l'essai a déjà été utilisé ou si la formule n'est pas gratuite
     */
    public function demarrerEssai(Entreprise $entreprise, FormuleAbonnement $formule, User $auteur): AbonnementActivation
    {
        if (! $formule->estGratuite() || ! $this->essaiDisponible()) {
            throw new RuntimeException("L'essai gratuit n'est proposé qu'une fois, au démarrage de votre espace.");
        }

        return $this->activer($entreprise, $formule, $auteur, 'Essai gratuit');
    }

    /**
     * @param  FormuleAbonnement  $formule  formule payante du catalogue de la plateforme
     * @param  array<string, mixed>  $options  transmises à la passerelle
     *
     * @throws PaiementRefuseException paiement non abouti (déjà inscrit au registre comme refusé)
     */
    public function payer(Entreprise $entreprise, FormuleAbonnement $formule, string $moyen, User $auteur, array $options = []): PaiementAbonnement
    {
        $reference = 'PAY-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));

        $donnees = [
            'entreprise_id' => $entreprise->id,
            'formule_nom' => $formule->nom,
            'jours' => $formule->illimite ? null : $formule->jours,
            'illimite' => $formule->illimite,
            'montant' => $formule->prix,
            'moyen' => $moyen,
            'passerelle' => $this->passerelle->nom(),
            'reference' => $reference,
            'payeur' => $auteur->name,
        ];

        try {
            $this->passerelle->encaisser($formule->prix, $moyen, $reference, $options);
        } catch (PaiementRefuseException $refus) {
            PaiementAbonnement::create($donnees + ['statut' => PaiementAbonnement::REFUSE]);

            throw $refus;
        }

        $paiement = PaiementAbonnement::create($donnees + ['statut' => PaiementAbonnement::REUSSI]);

        $this->activer($entreprise, $formule, $auteur, 'Paiement en ligne '.$reference.($this->passerelle->estSimulation() ? ' (simulation)' : ''));

        return $paiement;
    }

    private function activer(Entreprise $entreprise, FormuleAbonnement $formule, User $auteur, string $note): AbonnementActivation
    {
        $activation = Abonnement::activer(
            formule: FormuleAbonnement::copieLocale($formule->nom, $formule->jours, $formule->illimite, $formule->prix, $formule->limites()),
            montant: $formule->prix,
            jours: $formule->illimite ? null : $formule->jours,
            illimite: $formule->illimite,
            note: $note,
            auteur: $auteur,
        );

        $entreprise->recopierAbonnement($formule->nom, $activation->date_fin, $activation->illimite);

        return $activation;
    }
}
