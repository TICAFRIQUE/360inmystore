<?php

namespace App\Http\Middleware\Plateforme;

use App\Plateforme\LimitesFormule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse la création d'un magasin, d'une caisse, d'un utilisateur ou d'un
 * produit de plus que ne le permet la formule de l'entreprise (voir
 * LimitesFormule). Posé sur les routes par leur nom plutôt que dans chaque
 * contrôleur : ces contrôleurs sont partagés avec la version installée, qui
 * n'a pas de formule à limites.
 *
 * Le formulaire de création est refusé d'emblée (inutile de le remplir pour
 * rien), l'enregistrement aussi (seule vérification qui compte vraiment).
 */
class AppliqueLimitesFormule
{
    /** Nom de route => ressource limitée. */
    private const ROUTES = [
        'magasins.store' => 'magasins',
        'caisses.create' => 'caisses',
        'caisses.store' => 'caisses',
        'utilisateurs.create' => 'utilisateurs',
        'utilisateurs.store' => 'utilisateurs',
        'produits.create' => 'produits',
        'produits.store' => 'produits',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route()?->getName();
        $ressource = self::ROUTES[$route] ?? null;

        // Un dépôt n'est pas un magasin : seule la création d'un point de
        // vente compte (le type n'est connu qu'à l'enregistrement, d'où
        // l'absence de magasins.create ci-dessus).
        if ($ressource === null || ($ressource === 'magasins' && $request->input('type', 'magasin') !== 'magasin')) {
            return $next($request);
        }

        $refus = LimitesFormule::refus($ressource);

        if ($refus === null) {
            return $next($request);
        }

        return $request->isMethod('GET')
            ? redirect()->route(str_replace('.create', '.index', $route))->with('erreur', $refus)
            : back()->withInput()->with('erreur', $refus);
    }
}
