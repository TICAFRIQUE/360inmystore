<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Dépense de fonctionnement (loyer, salaires, électricité…) : de l'argent
 * réellement sorti d'un tiroir de caisse ou d'un compte de trésorerie, sous
 * un motif marqué « dépense ». Écrite uniquement par DepenseService, dans la
 * même transaction que le mouvement de caisse ou l'écriture de trésorerie
 * qui porte la sortie (elle en est la « référence ») : la dépense ne compte
 * jamais l'argent une seconde fois, elle dit seulement ce qu'il a payé.
 *
 * Immuable, comme un mouvement : une erreur se corrige par une annulation
 * (voir DepenseService::annuler), qui rend l'argent par un mouvement inverse
 * et garde la dépense, marquée annulée. Jamais de dépense à crédit : seule
 * une somme réellement payée en est une.
 *
 * Un achat de marchandise n'est PAS une dépense : il entre en stock, et son
 * coût est déjà compté dans la marge au moment de la vente.
 */
#[Fillable(['motif', 'motif_mouvement_id', 'montant', 'beneficiaire', 'magasin_id', 'session_caisse_id', 'compte_tresorerie_id',
    'created_by', 'created_at', 'annulee_at', 'annulee_par', 'motif_annulation'])]
class Depense extends Model implements HasMedia
{
    use InteractsWithMedia;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'montant' => 'integer',
            'created_at' => 'datetime',
            'annulee_at' => 'datetime',
        ];
    }

    /** Photo du reçu, facultative (une seule). */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('recu')->singleFile();
    }

    public function estAnnulee(): bool
    {
        return $this->annulee_at !== null;
    }

    /** Dépenses qui comptent : celles qui n'ont pas été annulées. */
    public function scopeEffectives(Builder $query): Builder
    {
        return $query->whereNull('annulee_at');
    }

    /** D'où l'argent est sorti, en clair. */
    public function sourceLibelle(): string
    {
        return $this->session_caisse_id
            ? ($this->sessionCaisse?->caisse?->nom ?? 'Caisse')
            : ($this->compteTresorerie?->nom ?? 'Trésorerie');
    }

    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class)->withTrashed();
    }

    public function sessionCaisse(): BelongsTo
    {
        return $this->belongsTo(SessionCaisse::class);
    }

    public function compteTresorerie(): BelongsTo
    {
        return $this->belongsTo(CompteTresorerie::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function annulateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annulee_par');
    }
}
