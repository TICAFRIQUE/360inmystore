<?php

namespace App\Http\Middleware\Plateforme;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Écrans de gestion du commerce (ventes, stock, caisse…) : sans objet sur le
 * domaine principal de la plateforme, dont la base ne contient les données
 * d'aucun commerce. L'éditeur qui s'y connecte est ramené à son tableau de
 * bord (ses propres écrans sont sous plateforme.*).
 */
class ReserveAuxCommerces
{
    private const ROUTES_PLATEFORME = [
        'logout',
        'notifications.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (plateforme()->estCentrale() && ! $request->routeIs(...self::ROUTES_PLATEFORME)) {
            return redirect()->route('plateforme.console');
        }

        return $next($request);
    }
}
