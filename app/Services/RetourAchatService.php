<?php

namespace App\Services;

use App\Enums\MouvementStockType;
use App\Exceptions\QuantiteRetourInvalideException;
use App\Models\CommandeAchat;
use App\Models\LigneRetourAchat;
use App\Models\Magasin;
use App\Models\RetourAchat;
use App\Models\User;
use App\Support\Arrondi;
use App\Support\NumeroDocument;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Retour fournisseur, symétrique de RetourVenteService : toujours lié à une
 * commande d'achat validée, ligne par ligne. Indépendant de la caisse, comme
 * ReglementFournisseur (voir CLAUDE.md).
 *
 * Le plafond et le prix de référence (pour l'avoir crédité) se calculent par
 * (ligne de commande × magasin), pas par ligne seule : une commande sans
 * réception (ancien modèle) n'a qu'un seul magasin possible — celui de la
 * ligne — donc une seule "ligne retournable" par produit, exactement comme
 * avant. Une commande avec réceptions (nouveau modèle) peut avoir reçu une
 * même ligne à plusieurs destinations (magasin choisi à chaque réception,
 * voir ReceptionAchatService) : chaque destination effectivement reçue
 * devient sa propre ligne retournable, avec son propre plafond et son
 * propre prix réel de référence — jamais retourner vers/depuis un magasin
 * qui n'a rien reçu.
 */
class RetourAchatService
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly CompteFournisseurService $compteFournisseurService,
    ) {}

    /**
     * @param  array<int, array{ligne_commande_achat_id:int, magasin_id:int, quantite_pieces:int|float}>  $lignes
     */
    public function retourner(CommandeAchat $commandeAchat, array $lignes, User $auteur, ?string $motif = null): RetourAchat
    {
        if ($commandeAchat->trashed()) {
            throw new InvalidArgumentException("Impossible d'enregistrer un retour sur une commande annulée.");
        }

        if ($commandeAchat->statut !== 'validee') {
            throw new InvalidArgumentException(
                "Seule une commande validée a mouvementé du stock : rien à retourner sur un brouillon."
            );
        }

        $lignes = array_values(array_filter($lignes, fn (array $l) => ($l['quantite_pieces'] ?? 0) > 0));

        if (empty($lignes)) {
            throw new InvalidArgumentException('Un retour doit comporter au moins une ligne avec une quantité positive.');
        }

        $commandeAchat->loadMissing('lignes.produit', 'lignes.magasinDestination', 'lignes.taxe', 'fournisseur', 'receptions.lignes');

        $hasReceptions = $commandeAchat->receptions->isNotEmpty();

        // Une commande validée mais jamais réceptionnée (cas normal depuis
        // que la réception est différée, receptions() reste vide comme en
        // legacy) n'a réellement rien reçu : seul un vrai MouvementStock
        // direct distingue un ancien modèle (tout reçu à la validation) de
        // ce cas — voir CommandeAchat::aDesMouvementsStockDirects().
        if (! $hasReceptions && ! $commandeAchat->aDesMouvementsStockDirects()) {
            throw new InvalidArgumentException(
                "Cette commande n'a encore rien reçu : rien à retourner. Réceptionnez-la d'abord."
            );
        }

        // Déjà retourné, groupé par (ligne × magasin) — magasin_id est déjà
        // stocké sur chaque LigneRetourAchat, jamais recalculé après coup.
        $dejaRetourne = LigneRetourAchat::whereIn('ligne_commande_achat_id', $commandeAchat->lignes->pluck('id'))
            ->selectRaw('ligne_commande_achat_id, magasin_id, SUM(quantite_pieces) as total')
            ->groupBy('ligne_commande_achat_id', 'magasin_id')
            ->get()
            ->keyBy(fn ($r) => "{$r->ligne_commande_achat_id}-{$r->magasin_id}")
            ->map(fn ($r) => (float) $r->total);

        // Reçu par (ligne × magasin), uniquement pertinent en nouveau modèle
        // (whereHas() du chargement receptions.lignes exclut déjà tout ce
        // qui viendrait d'une réception — il n'y en a pas en legacy).
        $recuParClef = $commandeAchat->receptions->flatMap->lignes
            ->groupBy(fn ($l) => "{$l->ligne_commande_achat_id}-{$l->magasin_id}");

        $magasinIds = collect($lignes)->pluck('magasin_id')->filter()->unique();
        $magasinsParId = $magasinIds->isNotEmpty()
            ? Magasin::whereIn('id', $magasinIds)->get()->keyBy('id')
            : collect();

        return DB::transaction(function () use ($commandeAchat, $lignes, $auteur, $motif, $dejaRetourne, $hasReceptions, $recuParClef, $magasinsParId) {
            $retour = RetourAchat::create([
                'numero' => $this->genererNumero(),
                'commande_achat_id' => $commandeAchat->id,
                'fournisseur_id' => $commandeAchat->fournisseur_id,
                'motif' => $motif,
                'montant_total' => 0,
                'created_by' => $auteur->id,
            ]);

            $montantTotal = 0;

            foreach ($lignes as $demande) {
                $ligne = $commandeAchat->lignes->firstWhere('id', $demande['ligne_commande_achat_id']);

                if (! $ligne) {
                    throw new InvalidArgumentException("Ligne de commande introuvable sur ce bon d'achat.");
                }

                $magasinId = (int) ($demande['magasin_id'] ?? 0);
                $clef = "{$ligne->id}-{$magasinId}";

                if ($hasReceptions) {
                    $magasin = $magasinsParId->get($magasinId);
                    if ($magasin === null) {
                        throw new InvalidArgumentException('Destination introuvable pour une ligne de retour.');
                    }
                    $lignesRecues = $recuParClef->get($clef, collect());
                    $quantiteRecue = (float) $lignesRecues->sum('quantite_pieces');
                    $totalLigneTtcRef = (int) $lignesRecues->sum(fn ($lr) => $lr->montantTtc());
                } else {
                    // Ancien modèle : la garde en tête de méthode
                    // (aDesMouvementsStockDirects()) garantit qu'on n'atteint
                    // cette branche que pour une commande réellement reçue en
                    // bloc à la validation — magasin_destination_id y est
                    // donc forcément rempli. Un seul magasin possible, celui
                    // fixé sur la ligne de commande (jamais un autre — le
                    // stock n'a bougé qu'à cet endroit à la validation).
                    if ($magasinId !== $ligne->magasin_destination_id) {
                        throw new InvalidArgumentException('Destination invalide pour cette ligne.');
                    }
                    $magasin = $ligne->magasinDestination;
                    $quantiteRecue = (float) $ligne->quantite_pieces;
                    // Ancien modèle uniquement (aDesMouvementsStockDirects()) :
                    // prix_achat de la ligne EST déjà le montant réellement
                    // facturé (pas d'indicatif décimal distinct à cette
                    // époque) — reste entier comme le reste de l'avoir.
                    $totalLigneTtcRef = (int) round($ligne->montantTtc());
                }

                $quantiteAvant = (float) ($dejaRetourne[$clef] ?? 0);
                $quantiteRestante = $quantiteRecue - $quantiteAvant;
                $quantiteRetour = (float) $demande['quantite_pieces'];

                if ($quantiteRetour > $quantiteRestante) {
                    throw new QuantiteRetourInvalideException($ligne, $quantiteRetour, $quantiteRestante);
                }

                $montantLigne = $this->montantTelescope($totalLigneTtcRef, $quantiteRecue, $quantiteAvant, $quantiteRetour);

                $retour->lignes()->create([
                    'ligne_commande_achat_id' => $ligne->id,
                    'produit_id' => $ligne->produit_id,
                    'magasin_id' => $magasin->id,
                    'quantite_pieces' => $quantiteRetour,
                    'montant' => $montantLigne,
                ]);

                // Sortie de stock : peut échouer (StockInsuffisantException)
                // si une partie a déjà été revendue/transférée ailleurs.
                $this->stockService->enregistrerMouvement(
                    produit: $ligne->produit,
                    magasin: $magasin,
                    quantite: -$quantiteRetour,
                    type: MouvementStockType::RetourFournisseur,
                    auteur: $auteur,
                    reference: $retour,
                    motif: $motif,
                );

                $montantTotal += $montantLigne;
                $dejaRetourne[$clef] = $quantiteAvant + $quantiteRetour;
            }

            $retour->update(['montant_total' => $montantTotal]);

            if ($montantTotal > 0) {
                $this->compteFournisseurService->crediterRetour($commandeAchat->fournisseur, $montantTotal, $retour, $auteur);
            }

            return $retour->fresh('lignes');
        });
    }

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
        return NumeroDocument::genererUnique('RF', RetourAchat::class);
    }
}
