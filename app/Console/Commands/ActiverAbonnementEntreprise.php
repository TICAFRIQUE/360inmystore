<?php

namespace App\Console\Commands;

use App\Models\Abonnement;
use App\Models\FormuleAbonnement;
use App\Models\User;
use App\Plateforme\Paiement\ActivationParEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Active un abonnement dans la base d'une entreprise, à la demande de
 * l'éditeur. Lancée par App\Plateforme\Paiement\ActivationParEditeur dans un
 * processus dédié à cette entreprise — jamais à la main.
 *
 * Termine par une ligne JSON (date de fin, illimité) que l'appelant relit
 * pour mettre à jour le registre de la plateforme.
 */
#[Signature('entreprises:activer-abonnement')]
#[Description("Active l'abonnement d'une entreprise (usage interne à la plateforme)")]
class ActiverAbonnementEntreprise extends Command
{
    protected $hidden = true;

    public function handle(): int
    {
        $donnees = json_decode((string) getenv(ActivationParEditeur::VARIABLE_DONNEES), true);

        if (! plateforme()->estEntreprise() || ! is_array($donnees)) {
            $this->error("Cette commande est lancée par la plateforme, pas à la main.");

            return self::FAILURE;
        }

        // L'activation doit avoir un auteur dans la base de l'entreprise :
        // son propriétaire. L'origine réelle (l'éditeur) figure dans la note.
        $proprietaire = User::role('Superadmin')->orderBy('id')->first();

        if (! $proprietaire) {
            $this->error("Aucun compte propriétaire dans cet espace.");

            return self::FAILURE;
        }

        $formule = $donnees['formule']
            ? FormuleAbonnement::copieLocale($donnees['formule']['nom'], $donnees['formule']['jours'], (bool) $donnees['formule']['illimite'], (int) $donnees['formule']['prix'], $donnees['formule'])
            : null;

        $activation = Abonnement::activer(
            formule: $formule,
            montant: (int) $donnees['montant'],
            jours: $donnees['jours'] === null ? null : (int) $donnees['jours'],
            illimite: (bool) $donnees['illimite'],
            note: $donnees['note'],
            auteur: $proprietaire,
            remplacer: (bool) $donnees['remplacer'],
        );

        $this->line(json_encode([
            'date_fin' => $activation->date_fin?->toDateString(),
            'illimite' => $activation->illimite,
        ]));

        return self::SUCCESS;
    }
}
