<?php

namespace App\Services;

use App\Enums\MouvementStockType;
use App\Enums\VenteType;
use App\Exceptions\SessionNonOuverteException;
use App\Models\Caisse;
use App\Models\Client;
use App\Models\Magasin;
use App\Models\Produit;
use App\Models\SessionCaisse;
use App\Models\Taxe;
use App\Models\UniteVente;
use App\Models\User;
use App\Models\Vente;
use App\Support\Arrondi;
use App\Support\Remise;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Vente atomique : stock (mouvements) + paiements + rattachement à la session
 * + (si à crédit) écriture de dette client, dans une seule transaction. Tout
 * ou rien (règle non négociable n°3).
 *
 * Remises : lignes d'abord, puis remise sur le sous-total. Chaque montant
 * résolu est stocké à côté du type/de la valeur saisie pour un ticket et des
 * rapports reproductibles.
 */
class VenteService
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly CompteClientService $compteClientService,
        private readonly BonLivraisonService $bonLivraisonService,
    ) {}

    /**
     * @param  array<int, array{produit_id:int, unite_vente_id?:?int, magasin_source_id?:?int, quantite:int, remise_type?:?string, remise_valeur?:?int}>  $lignes
     * @param  array<int, array{moyen_paiement_id:int, montant:int}>  $paiements
     */
    public function vendre(
        SessionCaisse $session,
        User $caissier,
        array $lignes,
        array $paiements,
        ?string $remiseTotaleType = null,
        ?int $remiseTotaleValeur = null,
        ?int $montantRecu = null,
        ?Client $client = null,
        bool $autoriserDepassementLimite = false,
        VenteType $type = VenteType::Facture,
    ): Vente {
        if (empty($lignes)) {
            throw new InvalidArgumentException('Une vente doit comporter au moins une ligne.');
        }

        $session = $session->fresh();
        if ($session->date_cloture !== null || $session->date_fermeture !== null) {
            throw new SessionNonOuverteException();
        }

        return DB::transaction(function () use ($session, $caissier, $lignes, $paiements, $remiseTotaleType, $remiseTotaleValeur, $montantRecu, $client, $autoriserDepassementLimite, $type) {
            $caisse = Caisse::whereKey($session->caisse_id)->lockForUpdate()->firstOrFail();
            $magasin = $caisse->magasin;

            $caisse->increment('sequence_ventes');
            // Préfixe "BL-" pour un bon de livraison : distingue le numéro
            // d'une facture classique au premier coup d'œil (ticket, liste
            // des ventes, rapports) — même compteur de séquence que les
            // factures de cette caisse (pas de série numérotée à part).
            $prefixe = $type === VenteType::BonLivraison ? 'BL-' : '';
            $numero = sprintf('%sM%d-C%02d-%06d', $prefixe, $magasin->id, $caisse->id, $caisse->sequence_ventes);

            [$lignesResolues, $sousTotal, $totalTaxes] = $this->resoudreLignes($lignes);

            // La remise totale porte sur le montant TTC (HT + taxes des
            // lignes), pas seulement sur le HT : sans ça, appliquer une
            // remise totale à une vente comportant des lignes taxées
            // laisserait la taxe intacte alors que le client paie moins —
            // sans taxe sur aucune ligne (cas actuel de tous les clients
            // existants), totalTaxes = 0 et ce calcul reste identique à
            // avant (aucune régression).
            $totalAvantRemiseTotale = $sousTotal + $totalTaxes;
            $remiseTotaleMontant = $this->resoudreRemise($remiseTotaleType, $remiseTotaleValeur, $totalAvantRemiseTotale);
            $totalNet = $totalAvantRemiseTotale - $remiseTotaleMontant;

            $totalPaiements = array_sum(array_column($paiements, 'montant'));
            $soldeDu = $totalNet - $totalPaiements;

            if ($soldeDu < 0) {
                throw new InvalidArgumentException(
                    "Le total des paiements ({$totalPaiements}) ne peut pas dépasser le net à payer ({$totalNet})."
                );
            }

            // Vente à crédit (règle 13) : un solde restant dû n'est autorisé
            // que si un client est identifié. Sans client, la vente doit
            // être payée intégralement, comme avant l'introduction du crédit.
            if ($soldeDu > 0 && $client === null) {
                throw new InvalidArgumentException(
                    "Le total des paiements ({$totalPaiements}) ne correspond pas au net à payer ({$totalNet})."
                );
            }

            // Le client peut donner plus que le net à payer (monnaie à rendre) :
            // ce n'est qu'une information affichée sur le ticket, jamais utilisé
            // pour les paiements enregistrés ci-dessous (toujours plafonnés au
            // net à payer par le contrôleur, voir CLAUDE.md — comptage espèces).
            $montantRecu = max($montantRecu ?? $totalNet, $totalNet);
            $monnaieRendue = $montantRecu - $totalNet;

            // Avoir déjà disponible sur le compte AVANT cette vente : la part
            // du solde à crédit qu'il couvrira est figée ici pour un ticket
            // reproductible (voir Vente::soldeDuReel()) — l'écriture de dette
            // elle-même (crediterDette ci-dessous) reste inchangée, seule
            // cette annotation informative est nouvelle.
            $avoirApplique = 0;
            if ($soldeDu > 0 && $client !== null) {
                $avoirDisponible = max(0, -$this->compteClientService->solde($client));
                $avoirApplique = min($avoirDisponible, $soldeDu);
            }

            $vente = Vente::create([
                'numero' => $numero,
                'type' => $type,
                'magasin_id' => $magasin->id,
                'session_caisse_id' => $session->id,
                'caissier_id' => $caissier->id,
                'client_id' => $client?->id,
                'sous_total' => $sousTotal,
                'remise_totale_type' => $remiseTotaleType,
                'remise_totale_valeur' => $remiseTotaleValeur,
                'remise_totale_montant' => $remiseTotaleMontant,
                'total_net' => $totalNet,
                'montant_recu' => $montantRecu,
                'monnaie_rendue' => $monnaieRendue,
                'avoir_applique' => $avoirApplique,
            ]);

            $lignesLivraisonAuto = [];
            foreach ($lignesResolues as $l) {
                $ligneVente = $vente->lignes()->create([
                    'produit_id' => $l['produit']->id,
                    'unite_vente_id' => $l['unite_vente']?->id,
                    'taxe_id' => $l['taxe']?->id,
                    'magasin_source_id' => $l['magasin_source']->id,
                    'quantite' => $l['quantite'],
                    'quantite_pieces' => $l['quantite_pieces'],
                    'prix_unitaire_applique' => $l['prix_unitaire_applique'],
                    'cout_applique' => $l['cout_applique'],
                    'sous_total_ligne' => $l['sous_total_ligne'],
                    'remise_ligne_type' => $l['remise_ligne_type'],
                    'remise_ligne_valeur' => $l['remise_ligne_valeur'],
                    'remise_ligne_montant' => $l['remise_ligne_montant'],
                    'prix_personnalise' => $l['prix_personnalise'],
                    'total_ligne' => $l['total_ligne'],
                ]);

                // Lève StockInsuffisantException si le stock est insuffisant, ce qui
                // annule toute la transaction (vente, lignes, paiements compris).
                // Chaque ligne prélève sur son propre magasin/dépôt source, qui
                // peut différer du magasin de la vente (voir CLAUDE.md).
                $this->stockService->enregistrerMouvement(
                    $l['produit'], $l['magasin_source'], -$l['quantite_pieces'], MouvementStockType::Vente, $caissier, reference: $vente,
                );

                $lignesLivraisonAuto[] = [
                    'ligne_vente_id' => $ligneVente->id,
                    'quantite_pieces' => $l['quantite_pieces'],
                ];
            }

            foreach ($paiements as $p) {
                $vente->paiements()->create([
                    'moyen_paiement_id' => $p['moyen_paiement_id'],
                    'montant' => $p['montant'],
                ]);
            }

            if ($soldeDu > 0) {
                $this->compteClientService->crediterDette(
                    $client, $soldeDu, $vente, $caissier, $autoriserDepassementLimite,
                );
            }

            // Une vente enregistrée en bon de livraison est considérée livrée
            // par défaut (règle métier du bouton Facture/BL) : on enchaîne un
            // bon de livraison couvrant la totalité des lignes, dans la même
            // transaction — même pattern que DevisService::transformer() qui
            // enchaîne deux opérations liées en une seule opération atomique.
            if ($type === VenteType::BonLivraison) {
                $this->bonLivraisonService->livrer(
                    $vente, $lignesLivraisonAuto, $caissier, 'Livraison automatique (vente enregistrée en bon de livraison)',
                );
            }

            return $vente->refresh();
        });
    }

    /**
     * Net à payer pour des lignes non encore vendues, aux prix/remises
     * courants — utilisé pour vérifier en amont (avant d'appeler vendre())
     * qu'un paiement partiel resterait autorisé, sans dupliquer la
     * résolution des lignes (ex. transformation d'un devis, voir
     * VenteController::transformerDevis()).
     *
     * @param  array<int, array{produit_id:int, unite_vente_id?:?int, quantite:int, remise_type?:?string, remise_valeur?:?int}>  $lignes
     */
    public function calculerTotalNet(array $lignes, ?string $remiseTotaleType, ?int $remiseTotaleValeur): int
    {
        [, $sousTotal, $totalTaxes] = $this->resoudreLignes($lignes);
        $totalAvantRemiseTotale = $sousTotal + $totalTaxes;

        return $totalAvantRemiseTotale - $this->resoudreRemise($remiseTotaleType, $remiseTotaleValeur, $totalAvantRemiseTotale);
    }

    /**
     * Le choix du lieu de prélèvement (magasin ou dépôt) est obligatoire par
     * ligne — pas de repli implicite sur le magasin de la vente (voir
     * CLAUDE.md) : chaque ligne doit porter un magasin_source_id valide.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int, 2: int}
     */
    private function resoudreLignes(array $lignes): array
    {
        $magasinSourceIds = collect($lignes)->pluck('magasin_source_id')->filter()->unique();
        $magasinsParId = $magasinSourceIds->isNotEmpty()
            ? Magasin::whereIn('id', $magasinSourceIds)->get()->keyBy('id')
            : collect();

        $taxeIds = collect($lignes)->pluck('taxe_id')->filter()->unique();
        $taxesParId = $taxeIds->isNotEmpty()
            ? Taxe::whereIn('id', $taxeIds)->get()->keyBy('id')
            : collect();

        $resolues = [];
        $sousTotal = 0;
        $totalTaxes = 0;

        foreach ($lignes as $ligne) {
            $produit = Produit::findOrFail($ligne['produit_id']);
            $uniteVente = ! empty($ligne['unite_vente_id'])
                ? UniteVente::findOrFail($ligne['unite_vente_id'])
                : null;
            $taxe = ! empty($ligne['taxe_id']) ? $taxesParId->get($ligne['taxe_id']) : null;

            $magasinSource = $magasinsParId->get($ligne['magasin_source_id'] ?? null);
            if ($magasinSource === null) {
                throw new InvalidArgumentException('Magasin source introuvable pour une ligne de vente.');
            }

            $quantite = Arrondi::quantite((float) $ligne['quantite']);
            if ($quantite <= 0) {
                throw new InvalidArgumentException('La quantité d\'une ligne de vente doit être positive.');
            }

            $quantitePieces = Arrondi::quantite($quantite * ($uniteVente->facteur ?? 1));
            $prixUnitaireApplique = $uniteVente ? $uniteVente->prix : $produit->prix_piece;
            $sousTotalLigne = Arrondi::entier($prixUnitaireApplique * $quantite);

            $remiseLigneMontant = $this->resoudreRemise(
                $ligne['remise_type'] ?? null,
                $ligne['remise_valeur'] ?? null,
                $sousTotalLigne,
            );

            // total_ligne reste HT (comme prix_achat côté achat, voir
            // CLAUDE.md) : la taxe s'ajoute par-dessus, jamais incluse dedans.
            $totalLigne = $sousTotalLigne - $remiseLigneMontant;
            $sousTotal += $totalLigne;
            $totalTaxes += Arrondi::entier($totalLigne * ($taxe?->taux ?? 0) / 100);

            $resolues[] = [
                'produit' => $produit,
                'unite_vente' => $uniteVente,
                'taxe' => $taxe,
                'magasin_source' => $magasinSource,
                'quantite' => $quantite,
                'quantite_pieces' => $quantitePieces,
                'prix_unitaire_applique' => $prixUnitaireApplique,
                'cout_applique' => $this->stockService->coutMoyenPondere($produit, $magasinSource),
                'sous_total_ligne' => $sousTotalLigne,
                'remise_ligne_type' => $ligne['remise_type'] ?? null,
                'remise_ligne_valeur' => $ligne['remise_valeur'] ?? null,
                'remise_ligne_montant' => $remiseLigneMontant,
                // Uniquement informatif pour l'affichage (voir
                // LigneVente::prixUnitaireEffectif()) : la remise a déjà été
                // résolue ci-dessus exactement comme une remise "montant"
                // classique, jamais un vrai changement de prix.
                'prix_personnalise' => ! empty($ligne['prix_personnalise']),
                'total_ligne' => $totalLigne,
            ];
        }

        return [$resolues, $sousTotal, $totalTaxes];
    }

    private function resoudreRemise(?string $type, ?int $valeur, int $base): int
    {
        return Remise::resoudre($type, $valeur, $base);
    }
}
