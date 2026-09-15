<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\VenteType;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Une remise en pourcentage au-delà de 100 n'a pas de sens (le montant
 * résolu est déjà plafonné à la base dans VenteService, mais on bloque la
 * saisie en amont plutôt que de laisser une valeur absurde être stockée).
 */
trait ValideRemises
{
    protected function remisePourcentageMax(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $champType = Str::replaceLast('_valeur', '_type', $attribute);

            if (request()->input($champType) === 'pourcentage' && $value > 100) {
                $fail('Une remise en pourcentage ne peut pas dépasser 100.');
            }
        };
    }

    /**
     * Une remise classique (montant/pourcentage) ne peut jamais être
     * négative. Seule exception : une ligne en "prix personnalisé", où le
     * prix saisi peut dépasser le catalogue (majoration) — auquel cas
     * remise_valeur/remise_ligne_montant devient négatif par construction
     * (voir app.js, remiseValeurEffective()) pour augmenter total_ligne au
     * lieu de le réduire. Toujours résolue comme une remise "montant"
     * classique (VenteService::resoudreLignes()), jamais un vrai champ prix
     * distinct — voir CLAUDE.md, Remises.
     */
    protected function remiseValeurMin(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $champPrixPersonnalise = Str::replaceLast('remise_valeur', 'prix_personnalise', $attribute);

            if (! request()->boolean($champPrixPersonnalise) && $value < 0) {
                $fail('Une remise ne peut pas être négative.');
            }
        };
    }

    /**
     * Sans la permission vente.remise, aucune remise ne doit jamais être
     * appliquée — on efface les champs remise avant validation plutôt que de
     * faire confiance à l'interface (qui les masque déjà), au cas où une
     * requête serait construite à la main pour contourner l'écran.
     * Le superadmin passe toujours (bypass Gate::before).
     */
    protected function bloquerRemiseSansPermission(Request $request): void
    {
        if ($request->user()->can('vente.remise')) {
            return;
        }

        $lignes = collect($request->input('lignes', []))->map(function (array $ligne) {
            $ligne['remise_type'] = null;
            $ligne['remise_valeur'] = null;
            $ligne['prix_personnalise'] = false;

            return $ligne;
        })->all();

        $request->merge([
            'lignes' => $lignes,
            'remise_totale_type' => null,
            'remise_totale_valeur' => null,
        ]);
    }

    /**
     * Sans la permission vente.credit, aucune vente (ni aucun panier en
     * attente qui en découle) ne doit jamais être rattachée à un client —
     * on efface le champ avant validation plutôt que de faire confiance à
     * l'écran (qui masque déjà le sélecteur client), au cas où une requête
     * serait construite à la main. Le superadmin passe toujours (bypass
     * Gate::before).
     */
    protected function bloquerCreditSansPermission(Request $request): void
    {
        if ($request->user()->can('vente.credit')) {
            return;
        }

        $request->merge(['client_id' => null]);
    }

    /**
     * Sans la permission vente.bon_livraison, une vente ne peut jamais être
     * enregistrée directement comme bon de livraison (livrée automatiquement,
     * voir CLAUDE.md) — on force le type à 'facture' avant validation plutôt
     * que de faire confiance à l'écran (qui masque déjà le sélecteur), au cas
     * où une requête serait construite à la main. Le superadmin passe
     * toujours (bypass Gate::before).
     */
    protected function bloquerTypeSansPermission(Request $request): void
    {
        if ($request->user()->can('vente.bon_livraison')) {
            return;
        }

        $request->merge(['type' => VenteType::Facture->value]);
    }
}
