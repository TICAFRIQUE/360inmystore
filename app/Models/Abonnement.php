<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pas une table : classe de service statique qui dérive l'état courant de
 * l'abonnement à partir du registre immuable AbonnementActivation (règle
 * "solde dérivé, jamais écrasé" appliquée à l'abonnement plutôt qu'à un
 * compte client/fournisseur). La situation courante est toujours celle de
 * la plus récente activation, jamais un cumul de toutes les lignes — un
 * changement d'offre peut réduire la date de fin par rapport à une
 * activation antérieure.
 *
 * Aucune activation en base : accès fermé dans l'espace d'une entreprise,
 * jusqu'au choix d'une formule (voir estBloquant()).
 */
class Abonnement
{
    public const CACHE_KEY = 'abonnement.etat';

    /**
     * État minimal mis en cache (pas le modèle complet, voir le commentaire
     * de Parametre::actuel() sur les pièges de sérialisation du cache
     * "database") : lu sur chaque requête via AssureAbonnementActif, doit
     * rester une lecture de cache, jamais une requête SQL à chaque fois.
     *
     * @return array{existe: bool, illimite: bool, date_fin: ?string}
     */
    protected static function etat(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $activation = AbonnementActivation::query()
                ->orderByDesc('date_debut')
                ->orderByDesc('id')
                ->first();

            if (! $activation) {
                return ['existe' => false, 'illimite' => false, 'date_fin' => null];
            }

            return [
                'existe' => true,
                'illimite' => $activation->illimite,
                'date_fin' => $activation->date_fin?->toDateString(),
            ];
        });
    }

    public static function estBloquant(): bool
    {
        $etat = self::etat();

        // Espace d'une entreprise sans aucune activation : le propriétaire
        // n'a pas encore choisi sa formule — tout l'espace se réduit à la
        // page des tarifs. (Sur le domaine principal, la base de la
        // plateforme n'a jamais d'activation : jamais bloquant.)
        if (! $etat['existe']) {
            return plateforme()->estEntreprise();
        }

        if ($etat['illimite']) {
            return false;
        }

        return Carbon::parse($etat['date_fin'])->isPast();
    }

    /**
     * Null = illimité ou aucun abonnement configuré (jamais bloquant dans
     * les deux cas) — les vues distinguent les deux via une activation
     * chargée directement (voir AbonnementController), pas via cette méthode.
     */
    public static function joursRestants(): ?int
    {
        $etat = self::etat();

        if (! $etat['existe'] || $etat['illimite']) {
            return null;
        }

        $dateFin = Carbon::parse($etat['date_fin']);

        return $dateFin->isPast() ? 0 : (int) today()->diffInDays($dateFin);
    }

    /**
     * Renouvellement additif par défaut : les jours restants de l'abonnement
     * actuel s'ajoutent aux jours de la nouvelle formule, sauf si la
     * nouvelle formule est illimitée (l'illimité prime, jours restants
     * ignorés) ou si $remplacer est vrai — un vrai changement d'offre
     * (upgrade/downgrade délibéré, ex. repasser un client en Essai malgré
     * des jours restants) doit pouvoir ignorer volontairement ce report.
     */
    public static function activer(
        ?FormuleAbonnement $formule,
        int $montant,
        ?int $jours,
        bool $illimite,
        ?string $note,
        User $auteur,
        bool $remplacer = false,
    ): AbonnementActivation {
        return DB::transaction(function () use ($formule, $montant, $jours, $illimite, $note, $auteur, $remplacer) {
            $joursRestantsReportes = $remplacer ? 0 : (self::joursRestants() ?? 0);
            $dateDebut = today();

            $activation = AbonnementActivation::create([
                'formule_id' => $formule?->id,
                'montant' => $montant,
                'jours' => $illimite ? null : $jours,
                'illimite' => $illimite,
                'jours_restants_reportes' => $joursRestantsReportes,
                'date_debut' => $dateDebut,
                'date_fin' => $illimite ? null : $dateDebut->copy()->addDays($jours + $joursRestantsReportes),
                'note' => $note,
                'created_by' => $auteur->id,
            ]);

            self::invaliderCache();

            return $activation;
        });
    }

    public static function invaliderCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
