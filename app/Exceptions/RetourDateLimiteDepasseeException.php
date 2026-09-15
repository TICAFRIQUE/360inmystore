<?php

namespace App\Exceptions;

use App\Models\Vente;
use RuntimeException;

/**
 * Un retour client n'est possible que le jour même de la vente d'origine
 * (voir CLAUDE.md, section Retours) — au-delà, même avec une session de
 * caisse ouverte, plus aucun retour n'est possible sur cette facture.
 */
class RetourDateLimiteDepasseeException extends RuntimeException
{
    public function __construct(
        public readonly Vente $vente,
    ) {
        parent::__construct(
            "Cette vente date du {$vente->created_at->format('d/m/Y')} : ".
            'le retour n\'est plus possible après le jour de la vente.'
        );
    }
}
