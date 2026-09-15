<?php

namespace App\Models;

use App\Support\Arrondi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * quantite_pieces n'est volontairement pas fillable : c'est un champ dérivé,
 * calculé côté serveur (voir booted()), jamais saisi directement.
 */
#[Fillable(['commande_achat_id', 'produit_id', 'unite_vente_id', 'taxe_id', 'magasin_destination_id', 'quantite', 'prix_achat'])]
class LigneCommandeAchat extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:3',
            'quantite_pieces' => 'decimal:3',
            // Prix indicatif, jamais arrondi à l'entier (voir Arrondi::prixAchat()) —
            // contrairement au prix RÉELLEMENT facturé à la réception
            // (LigneReceptionAchat::prix_achat_reel), resté entier.
            'prix_achat' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LigneCommandeAchat $ligne) {
            $ligne->quantite_pieces = $ligne->quantite * $ligne->facteurPieces();
        });
    }

    public function commandeAchat(): BelongsTo
    {
        return $this->belongsTo(CommandeAchat::class);
    }

    /**
     * withTrashed() : ligne de commande historique, doit rester affichable
     * même si le produit a été supprimé (soft delete) depuis.
     */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class)->withTrashed();
    }

    /**
     * withTrashed() : ligne de commande historique, doit rester affichable
     * même si l'unité de vente a été désactivée/supprimée depuis — même
     * référentiel que la vente/le devis (voir UniteVente).
     */
    public function uniteVente(): BelongsTo
    {
        return $this->belongsTo(UniteVente::class)->withTrashed();
    }

    /**
     * withTrashed() : ligne historique, doit rester affichable même si la
     * taxe a été désactivée/supprimée depuis.
     */
    public function taxe(): BelongsTo
    {
        return $this->belongsTo(Taxe::class)->withTrashed();
    }

    /**
     * withTrashed() : ligne historique, doit rester affichable même si le
     * magasin/dépôt destinataire a été supprimé depuis.
     */
    public function magasinDestination(): BelongsTo
    {
        return $this->belongsTo(Magasin::class, 'magasin_destination_id')->withTrashed();
    }

    public function retours(): HasMany
    {
        return $this->hasMany(LigneRetourAchat::class);
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(LigneReceptionAchat::class);
    }

    /**
     * Nombre de pièces contenues dans l'unité d'achat choisie (1 si achetée
     * à la pièce/unité de base, le facteur de l'UniteVente sinon).
     */
    public function facteurPieces(): int
    {
        return $this->uniteVente?->facteur ?? 1;
    }

    /**
     * prix_achat porte sur l'unité d'achat saisie (le groupe entier le cas
     * échéant) : ramené au prix par pièce, indicatif comme prix_achat
     * lui-même (voir Arrondi::prixAchat()) — arrondi à l'entier seulement là
     * où le résultat alimente réellement une réception (prix_achat_reel),
     * jamais ici.
     */
    public function prixAchatParPiece(): float
    {
        $facteur = $this->facteurPieces();

        return $facteur > 1 ? Arrondi::prixAchat((float) $this->prix_achat / $facteur) : (float) $this->prix_achat;
    }

    /**
     * prix_achat est HT (voir CLAUDE.md) : total HT de la ligne = prix
     * unitaire de l'unité d'achat choisie × quantité de cette unité.
     * Indicatif, jamais arrondi à l'entier (voir Arrondi::prixAchat()).
     */
    public function montantHt(): float
    {
        return Arrondi::prixAchat((float) $this->prix_achat * (float) $this->quantite);
    }

    public function montantTtc(): float
    {
        $ht = $this->montantHt();

        return Arrondi::prixAchat($ht + $ht * ($this->taxe?->taux ?? 0) / 100);
    }
}
