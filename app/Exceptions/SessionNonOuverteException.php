<?php

namespace App\Exceptions;

use RuntimeException;

class SessionNonOuverteException extends RuntimeException
{
    public function __construct(string $action = 'vendre')
    {
        // Élision de "de" devant une voyelle ("d'enregistrer un retour"),
        // sinon "de" tel quel ("de vendre", "de rembourser un avoir").
        $de = preg_match('/^[aeiouyhàâéèêëîïôûù]/i', $action) ? "d'" : 'de ';
        parent::__construct("Aucune session de caisse ouverte : impossible {$de}{$action}.");
    }
}
