<?php

namespace App\Http\Middleware\Plateforme;

use App\Plateforme\Plateforme;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Arrête toute requête adressée à un sous-domaine qui
 * n'est l'espace d'aucune entreprise, ou dont l'espace est fermé (suspendu,
 * en cours de création). Premier middleware de la pile, avant les sessions et
 * l'authentification : rien ne doit s'exécuter pour un espace indisponible —
 * ni connexion, ni lecture de base (voir PlateformeServiceProvider).
 */
class BloqueEspaceIndisponible
{
    public function handle(Request $request, Closure $next): Response
    {
        $plateforme = plateforme();

        if (! $plateforme->estBloquee()) {
            return $next($request);
        }

        $introuvable = $plateforme->etat() === Plateforme::INTROUVABLE;

        return response()->view('plateforme.espace-indisponible', [
            'introuvable' => $introuvable,
            'sousDomaine' => $plateforme->sousDomaine(),
        ], $introuvable ? 404 : 403);
    }
}
