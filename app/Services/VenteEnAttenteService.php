<?php

namespace App\Services;

use App\Enums\VenteType;
use App\Exceptions\SessionNonOuverteException;
use App\Models\Client;
use App\Models\SessionCaisse;
use App\Models\User;
use App\Models\Vente;
use App\Models\VenteEnAttente;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Panier mis de côté le temps de servir un autre client : aucun mouvement de
 * stock, aucun encaissement. La reprise applique le prix courant des produits
 * (pas de figeage à la mise en attente) — l'autorisation (qui peut voir/
 * annuler quoi) relève de la couche policy/contrôleur, pas de ce service.
 */
class VenteEnAttenteService
{
    public function __construct(private readonly VenteService $venteService) {}

    /**
     * @param  array<int, array{produit_id:int, unite_vente_id?:?int, magasin_source_id?:?int, quantite:int}>  $lignes
     */
    public function mettreEnAttente(
        SessionCaisse $session,
        User $caissier,
        array $lignes,
        ?string $libelle = null,
        ?VenteEnAttente $existant = null,
        ?Client $client = null,
    ): VenteEnAttente {
        if (empty($lignes)) {
            throw new InvalidArgumentException('Une vente en attente doit comporter au moins une ligne.');
        }

        $session = $session->fresh();
        if ($session->date_cloture !== null || $session->date_fermeture !== null) {
            throw new SessionNonOuverteException();
        }

        return DB::transaction(function () use ($session, $caissier, $lignes, $libelle, $existant, $client) {
            $venteEnAttente = $existant ?? new VenteEnAttente([
                'magasin_id' => $session->caisse->magasin_id,
                'caisse_id' => $session->caisse_id,
                'session_caisse_id' => $session->id,
                'caissier_id' => $caissier->id,
            ]);
            $venteEnAttente->libelle = $libelle;
            $venteEnAttente->client_id = $client?->id;
            $venteEnAttente->save();

            // « Remettre en attente » un panier déjà repris est une mise à jour
            // du même brouillon, pas un nouveau panier : on remplace ses lignes.
            $venteEnAttente->lignes()->delete();
            foreach ($lignes as $ligne) {
                $venteEnAttente->lignes()->create([
                    'produit_id' => $ligne['produit_id'],
                    'unite_vente_id' => $ligne['unite_vente_id'] ?? null,
                    'magasin_source_id' => $ligne['magasin_source_id'] ?? null,
                    'quantite' => $ligne['quantite'],
                ]);
            }

            return $venteEnAttente;
        });
    }

    /**
     * Finalise le panier avec les lignes/remises/paiements tels qu'édités à
     * l'écran de reprise (le panier a pu être modifié depuis la mise en
     * attente) — mêmes règles qu'une vente créée directement.
     *
     * @param  array<int, array{produit_id:int, unite_vente_id?:?int, quantite:int, remise_type?:?string, remise_valeur?:?int}>  $lignes
     * @param  array<int, array{moyen_paiement_id:int, montant:int}>  $paiements
     */
    public function reprendre(
        VenteEnAttente $venteEnAttente,
        array $lignes,
        array $paiements,
        ?string $remiseTotaleType = null,
        ?int $remiseTotaleValeur = null,
        ?int $montantRecu = null,
        ?Client $client = null,
        bool $autoriserDepassementLimite = false,
        VenteType $type = VenteType::Facture,
    ): Vente {
        $venteEnAttente->loadMissing(['sessionCaisse', 'caissier']);

        return DB::transaction(function () use ($venteEnAttente, $lignes, $paiements, $remiseTotaleType, $remiseTotaleValeur, $montantRecu, $client, $autoriserDepassementLimite, $type) {
            $vente = $this->venteService->vendre(
                session: $venteEnAttente->sessionCaisse,
                caissier: $venteEnAttente->caissier,
                lignes: $lignes,
                paiements: $paiements,
                remiseTotaleType: $remiseTotaleType,
                remiseTotaleValeur: $remiseTotaleValeur,
                montantRecu: $montantRecu,
                client: $client,
                autoriserDepassementLimite: $autoriserDepassementLimite,
                type: $type,
            );

            $venteEnAttente->delete();

            return $vente;
        });
    }

    public function annuler(VenteEnAttente $venteEnAttente): void
    {
        $venteEnAttente->delete();
    }
}
