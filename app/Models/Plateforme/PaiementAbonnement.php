<?php

namespace App\Models\Plateforme;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registre immuable des paiements d'abonnement (voir la migration) : une
 * ligne par tentative, réussie ou refusée — jamais modifiée ni supprimée.
 */
#[Fillable(['entreprise_id', 'formule_nom', 'jours', 'illimite', 'montant', 'moyen', 'passerelle', 'reference', 'statut', 'payeur'])]
class PaiementAbonnement extends ModelePlateforme
{
    const UPDATED_AT = null;

    public const REUSSI = 'reussi';

    public const REFUSE = 'refuse';

    protected function casts(): array
    {
        return [
            'jours' => 'integer',
            'illimite' => 'boolean',
            'montant' => 'integer',
        ];
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }

    public function estSimule(): bool
    {
        return $this->passerelle === 'simulation';
    }

    /** D'où vient le paiement : saisi par l'éditeur, simulé, ou payé en ligne. */
    public function libelleOrigine(): string
    {
        return match ($this->passerelle) {
            'manuel' => 'Enregistré à la main',
            'simulation' => 'Simulation',
            default => 'En ligne',
        };
    }

    public function libelleMoyen(): string
    {
        return [
            'especes' => 'Espèces',
            'mobile_money' => 'Mobile Money',
            'carte' => 'Carte bancaire',
            'virement' => 'Virement',
            'cheque' => 'Chèque',
            'offert' => 'Offert',
        ][$this->moyen] ?? ucfirst(str_replace('_', ' ', $this->moyen));
    }
}
