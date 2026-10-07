<?php

namespace App\Http\Middleware;

use App\Models\Abonnement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirige tout utilisateur non exempté (voir User::estGestionnaireAbonnement())
 * vers "Mon abonnement" tant que l'abonnement est expiré — pour TOUTES les
 * routes du groupe protégé, quelle que soit la route demandée. Appliqué sur
 * le groupe `auth` de routes/web.php, jamais sur abonnement.mon elle-même
 * (sinon boucle de redirection).
 */
class AssureAbonnementActif
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Abonnement::estBloquant()) {
            return $next($request);
        }

        if ($request->user()?->estGestionnaireAbonnement()) {
            return $next($request);
        }

        // Accès de maintenance de l'éditeur (voir AccesEspaceController) :
        // il doit pouvoir entrer dans un espace même expiré.
        if ($request->session()->has(\App\Http\Controllers\AccesEspaceController::CLE_MAINTENANCE)) {
            return $next($request);
        }

        // Déjà sur l'écran de blocage (ou en train de se déconnecter) :
        // laisser passer, sinon boucle de redirection infinie. Le paiement
        // en ligne reste lui aussi accessible : c'est
        // l'écran qui permet de lever le blocage.
        if ($request->routeIs('abonnement.mon', 'abonnement.paiement.*', 'abonnement.essai', 'logout')) {
            return $next($request);
        }

        return redirect()->route('abonnement.mon');
    }
}
