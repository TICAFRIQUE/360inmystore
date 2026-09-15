<?php

namespace App\Services;

use App\Enums\MouvementStockType;
use App\Exceptions\QuantiteRetourInvalideException;
use App\Exceptions\RetourDateLimiteDepasseeException;
use App\Exceptions\SessionNonOuverteException;
use App\Models\Client;
use App\Models\LigneRetourVente;
use App\Models\RetourVente;
use App\Models\SessionCaisse;
use App\Models\User;
use App\Models\Vente;
use App\Support\Arrondi;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Retour client : toujours lié à une vente précise, ligne par ligne, jamais
 * un avoir libre (règle produit, voir CLAUDE.md section Retours). Ne
 * mouvemente jamais la caisse — l'avoir est crédité sur le compte client via
 * CompteClientService::crediterRetour(), qui autorise un solde négatif. Une
 * vente comptant (sans client identifié) reste anonyme, mais son retour doit
 * tout de même créditer un client précis — jamais silencieusement regroupé
 * sur le seul compte "Client comptant" (règle produit : plusieurs personnes
 * différentes font des ventes comptant, mélanger leurs avoirs sur un compte
 * partagé les rend impossibles à distinguer). L'appelant (contrôleur) doit
 * donc résoudre ce client (existant, nouveau, ou "Client comptant" en
 * dernier recours si l'identité est vraiment inconnue) et le passer
 * explicitement via $clientCredite.
 *
 * Exige une session de caisse ouverte, et uniquement le jour même de la
 * vente — au-delà, plus aucun retour possible sur cette facture, même avec
 * une session ouverte (règle produit, revient sur l'ancien comportement
 * "aucune session requise" après retour d'expérience). Le retour se rattache
 * à CETTE session (pas forcément celle de la vente d'origine), pour compter
 * dans le journal de la caisse où il est réellement traité.
 */
class RetourVenteService
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly CompteClientService $compteClientService,
    ) {}

    /**
     * @param  array<int, array{ligne_vente_id:int, quantite_pieces:int|float}>  $lignes
     */
    public function retourner(Vente $vente, array $lignes, User $auteur, SessionCaisse $session, ?string $motif = null, ?Client $clientCredite = null): RetourVente
    {
        if ($vente->trashed()) {
            throw new InvalidArgumentException("Impossible d'enregistrer un retour sur une vente annulée.");
        }

        $session = $session->fresh();
        if ($session->date_cloture !== null || $session->date_fermeture !== null) {
            throw new SessionNonOuverteException('enregistrer un retour');
        }

        if (! $vente->created_at->isSameDay(now())) {
            throw new RetourDateLimiteDepasseeException($vente);
        }

        $lignes = array_values(array_filter($lignes, fn (array $l) => ($l['quantite_pieces'] ?? 0) > 0));

        if (empty($lignes)) {
            throw new InvalidArgumentException('Un retour doit comporter au moins une ligne avec une quantité positive.');
        }

        $vente->loadMissing('lignes.produit', 'lignes.magasinSource', 'client');

        // Client explicitement choisi par l'appelant en priorité (vente
        // comptant : le formulaire impose de choisir/créer un client précis,
        // voir RetourVenteController::store()) ; sinon le client de la vente
        // elle-même ; "Client comptant" reste un ultime filet de sécurité
        // pour les appels programmatiques (tinker, tests) qui n'en passent
        // aucun.
        $clientCredite ??= $vente->client ?? Client::comptantDefaut();

        // Une seule requête groupée pour les quantités déjà retournées de
        // toutes les lignes de la vente (zéro N+1 — règle Performance).
        $dejaRetourne = LigneRetourVente::whereIn('ligne_vente_id', $vente->lignes->pluck('id'))
            ->selectRaw('ligne_vente_id, SUM(quantite_pieces) as total')
            ->groupBy('ligne_vente_id')
            ->pluck('total', 'ligne_vente_id');

        return DB::transaction(function () use ($vente, $lignes, $auteur, $session, $motif, $dejaRetourne, $clientCredite) {
            $retour = RetourVente::create([
                'numero' => $this->genererNumero(),
                'vente_id' => $vente->id,
                'client_id' => $clientCredite->id,
                'session_caisse_id' => $session->id,
                'motif' => $motif,
                'montant_total' => 0,
                'created_by' => $auteur->id,
            ]);

            $montantTotal = 0;

            foreach ($lignes as $demande) {
                $ligneVente = $vente->lignes->firstWhere('id', $demande['ligne_vente_id']);

                if (! $ligneVente) {
                    throw new InvalidArgumentException('Ligne de vente introuvable sur cette facture.');
                }

                $quantiteAvant = (float) ($dejaRetourne[$ligneVente->id] ?? 0);
                $quantiteRestante = (float) $ligneVente->quantite_pieces - $quantiteAvant;
                $quantiteRetour = (float) $demande['quantite_pieces'];

                if ($quantiteRetour > $quantiteRestante) {
                    throw new QuantiteRetourInvalideException($ligneVente, $quantiteRetour, $quantiteRestante);
                }

                $montantLigne = $this->montantTelescope($ligneVente->total_ligne, $ligneVente->quantite_pieces, $quantiteAvant, $quantiteRetour);

                $retour->lignes()->create([
                    'ligne_vente_id' => $ligneVente->id,
                    'produit_id' => $ligneVente->produit_id,
                    'magasin_id' => $ligneVente->magasin_source_id,
                    'quantite_pieces' => $quantiteRetour,
                    'montant' => $montantLigne,
                    'cout_applique' => $ligneVente->cout_applique,
                ]);

                $this->stockService->enregistrerMouvement(
                    produit: $ligneVente->produit,
                    magasin: $ligneVente->magasinSource,
                    quantite: $quantiteRetour,
                    type: MouvementStockType::RetourClient,
                    auteur: $auteur,
                    reference: $retour,
                    motif: $motif,
                );

                $montantTotal += $montantLigne;
                // Pour rester cohérent si le même formulaire référence deux
                // fois la même ligne (rejeu improbable mais pas impossible).
                $dejaRetourne[$ligneVente->id] = $quantiteAvant + $quantiteRetour;
            }

            $retour->update(['montant_total' => $montantTotal]);

            if ($montantTotal > 0) {
                $this->compteClientService->crediterRetour($clientCredite, $montantTotal, $retour, $auteur);
            }

            return $retour->fresh('lignes');
        });
    }

    /**
     * Répartition proportionnelle du montant de ligne, calculée sur le cumulé
     * (pas incrément par incrément) pour que la somme de plusieurs retours
     * partiels converge exactement vers total_ligne sans dérive d'arrondi.
     */
    private function montantTelescope(int $totalLigne, int|float $quantitePiecesTotal, int|float $quantiteAvant, int|float $quantiteRetour): int
    {
        if ($quantitePiecesTotal <= 0) {
            return 0;
        }

        $cumuleApres = Arrondi::entier($totalLigne * ($quantiteAvant + $quantiteRetour) / $quantitePiecesTotal);
        $cumuleAvant = Arrondi::entier($totalLigne * $quantiteAvant / $quantitePiecesTotal);

        return $cumuleApres - $cumuleAvant;
    }

    private function genererNumero(): string
    {
        do {
            $numero = 'RC-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (RetourVente::where('numero', $numero)->exists());

        return $numero;
    }
}
