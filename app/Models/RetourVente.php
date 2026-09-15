<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Retour client, immuable comme une vente ou un règlement : pas d'édition ni
 * de suppression applicative, une correction se fait par une nouvelle vente
 * (voir CLAUDE.md, section Retours).
 */
#[Fillable(['numero', 'vente_id', 'client_id', 'session_caisse_id', 'motif', 'montant_total', 'created_by'])]
class RetourVente extends Model
{
    use HasFactory, LogsActivity;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'montant_total' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable();
    }

    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class)->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Caisse où le retour est réellement traité (pas forcément celle de la
     * vente d'origine, voir RetourVenteService::retourner()) — sert à
     * rattacher ce retour au bon journal de caisse (JournalCaisse). Nullable :
     * absent sur les retours créés avant l'exigence de session.
     */
    public function sessionCaisse(): BelongsTo
    {
        return $this->belongsTo(SessionCaisse::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneRetourVente::class);
    }
}
