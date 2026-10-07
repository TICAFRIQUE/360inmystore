<?php

namespace App\Http\Middleware\Plateforme;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes de la plateforme (accueil, inscription, console de l'éditeur) :
 * elles n'existent que sur le domaine principal. Dans l'espace d'une
 * entreprise, elles
 * répondent 404, comme si elles n'étaient pas déclarées.
 */
class ReserveALaPlateforme
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(plateforme()->estCentrale(), 404);

        return $next($request);
    }
}
