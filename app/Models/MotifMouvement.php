<?php

namespace App\Models;

use App\Models\Concerns\MetEnFormePhrase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Référentiel des motifs proposés pour un mouvement d'entrée/sortie —
 * caisse de caissier (MouvementCaisse) ou trésorerie (EcritureCompteTresorerie).
 * Les deux modèles gardent leur propre colonne `motif` en texte (règle 19) :
 * ce référentiel harmonise les libellés proposés.
 *
 * est_depense : une SORTIE faite sous ce motif est une dépense de
 * fonctionnement (loyer, salaires, électricité…) et laisse une Depense —
 * voir DepenseService. C'est le motif, et lui seul, qui en décide : il n'y a
 * pas deux saisies concurrentes, « sortie » d'un côté et « dépense » de
 * l'autre. La nature est figée à la saisie : cocher ou décocher la case plus
 * tard ne reclasse jamais les sorties passées.
 */
#[Fillable(['nom', 'est_depense', 'actif'])]
class MotifMouvement extends Model
{
    use HasFactory, LogsActivity, SoftDeletes, MetEnFormePhrase;

    protected function casts(): array
    {
        return [
            'est_depense' => 'boolean',
            'actif' => 'boolean',
        ];
    }

    protected function nom(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => static::casseEnPhrase($value));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
