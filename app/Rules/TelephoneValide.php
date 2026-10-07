<?php

namespace App\Rules;

use App\Support\Telephone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * À utiliser après Telephone::normaliserDansRequete() : à ce stade, un numéro
 * valide pour le pays choisi a déjà été remplacé par sa forme internationale.
 * Tout ce qui n'est pas dans cette forme est donc un numéro refusé — jamais
 * enregistré tel quel.
 */
class TelephoneValide implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! Telephone::estE164Valide(is_string($value) ? $value : null)) {
            $fail('Ce numéro de téléphone n\'est pas valide pour le pays choisi.');
        }
    }
}
