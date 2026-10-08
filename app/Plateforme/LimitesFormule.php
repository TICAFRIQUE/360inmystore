<?php

namespace App\Plateforme;

use App\Models\AbonnementActivation;
use App\Models\Caisse;
use App\Models\FormuleAbonnement;
use App\Models\Magasin;
use App\Models\Produit;
use App\Models\User;

/**
 * Limites de la formule d'une entreprise : combien de magasins, de caisses,
 * d'utilisateurs et de produits son abonnement lui permet d'avoir. L'éditeur
 * les règle dans son catalogue de formules (Formules d'abonnement) ; une
 * limite vide vaut « sans limite ».
 *
 * La limite ne fait que refuser une création de plus : rien de ce qui existe
 * déjà n'est jamais retiré ni masqué, même si l'éditeur abaisse une limite
 * ou si le client repasse à une formule plus petite.
 *
 * Hors de l'espace d'une entreprise (domaine de l'éditeur), il n'y a aucune
 * limite : il n'y a pas non plus de commerce à gérer.
 */
class LimitesFormule
{
    /**
     * Formule dont les limites s'appliquent : celle du catalogue de l'éditeur
     * qui porte le nom de la formule en cours — ses limites actuelles, pour
     * qu'un réglage de l'éditeur vaille tout de suite pour ses clients. Si
     * elle a disparu du catalogue (renommée, supprimée), la copie gardée dans
     * l'espace à la souscription fait foi. null : aucune formule (durée libre
     * accordée par l'éditeur), donc aucune limite.
     */
    public static function formule(): ?FormuleAbonnement
    {
        if (! plateforme()->estEntreprise()) {
            return null;
        }

        $copie = AbonnementActivation::query()->orderByDesc('date_debut')->orderByDesc('id')->first()?->formule;

        if (! $copie) {
            return null;
        }

        // actif = proposée à la souscription : c'est elle, et non une copie
        // d'historique du même nom, qui porte le réglage de l'éditeur. Une
        // formule retirée de la vente garde ses limites pour ses abonnés.
        return FormuleAbonnement::on('plateforme')->where('nom', $copie->nom)->orderByDesc('actif')->orderBy('id')->first() ?? $copie;
    }

    /** Nombre maximal permis pour cette ressource, null si elle n'est pas limitée. */
    public static function limite(string $ressource): ?int
    {
        return self::formule()?->{FormuleAbonnement::LIMITES[$ressource]['colonne']};
    }

    /** Nombre déjà créé dans l'espace. Un dépôt n'est pas un magasin : il ne compte pas. */
    public static function utilise(string $ressource): int
    {
        return match ($ressource) {
            'magasins' => Magasin::magasins()->count(),
            'caisses' => Caisse::count(),
            'utilisateurs' => User::count(),
            'produits' => Produit::count(),
        };
    }

    /**
     * Message de refus si en créer $ajout de plus dépasserait la limite de la
     * formule, null si c'est permis.
     */
    public static function refus(string $ressource, int $ajout = 1): ?string
    {
        $formule = self::formule();
        $limite = $formule?->{FormuleAbonnement::LIMITES[$ressource]['colonne']};

        if ($limite === null) {
            return null;
        }

        $utilise = self::utilise($ressource);

        if ($utilise + $ajout <= $limite) {
            return null;
        }

        $libelle = FormuleAbonnement::LIMITES[$ressource][$limite > 1 ? 'pluriel' : 'singulier'];

        return "Votre formule « {$formule->nom} » permet {$limite} {$libelle} au plus, et vous en avez déjà {$utilise}. "
            .'Pour en ajouter, choisissez une formule supérieure (menu Abonnement).';
    }
}
