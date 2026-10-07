<?php

namespace App\Models\Plateforme;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lien d'accès direct à un espace (voir la migration et
 * App\Plateforme\LienAcces) : usage unique, courte durée.
 */
#[Fillable(['entreprise_id', 'empreinte', 'motif', 'auteur', 'expire_a', 'utilise_a'])]
class AccesEspace extends ModelePlateforme
{
    const UPDATED_AT = null;

    /** Arrivée du propriétaire dans son espace, juste après son inscription. */
    public const INSCRIPTION = 'inscription';

    /** Entrée de l'éditeur dans l'espace d'un client. */
    public const MAINTENANCE = 'maintenance';

    protected function casts(): array
    {
        return [
            'expire_a' => 'datetime',
            'utilise_a' => 'datetime',
        ];
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }
}
