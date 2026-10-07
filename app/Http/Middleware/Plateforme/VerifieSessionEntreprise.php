<?php

namespace App\Http\Middleware\Plateforme;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une session ouverte dans l'espace d'une entreprise ne vaut que pour cet
 * espace. Les identifiants d'utilisateur repartent de 1
 * dans chaque base : une session rejouée sur un autre sous-domaine y
 * désignerait le compte n° X d'une AUTRE entreprise.
 *
 * Avec des sessions stockées en base (réglage par défaut), ce cas ne peut pas
 * se produire — chaque entreprise a sa propre table de sessions. Ce contrôle
 * reste le filet de sécurité si l'hébergement passe un jour à un stockage de
 * sessions partagé (fichiers, Redis).
 */
class VerifieSessionEntreprise
{
    private const CLE = 'plateforme.espace';

    public function handle(Request $request, Closure $next): Response
    {
        $plateforme = plateforme();

        if (! $request->hasSession()) {
            return $next($request);
        }

        $espace = $plateforme->estEntreprise() ? 'entreprise:'.$plateforme->entrepriseId() : 'plateforme';
        $espaceDeLaSession = $request->session()->get(self::CLE);

        if ($espaceDeLaSession !== null && $espaceDeLaSession !== $espace) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $request->session()->put(self::CLE, $espace);

        return $next($request);
    }
}
