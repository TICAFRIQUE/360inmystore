<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ConfigurationAbonnement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Limite les tentatives par (identifiant + IP), pas juste par IP : un
     * code à 4 chiffres ne couvre que 10 000 combinaisons — sans ce verrou,
     * un script les épuise en quelques minutes pour un identifiant connu.
     */
    private const MAX_TENTATIVES = 5;

    private const DECOMPTE_SECONDES = 60;

    /**
     * Second verrou, par identifiant SEUL (toutes IP confondues) : le verrou
     * par (identifiant + IP) ci-dessus ne bloque rien pour un attaquant
     * distribué qui change d'IP à chaque tentative — chaque nouvelle IP
     * repart avec un compteur à zéro pour le même identifiant. Seuil plus
     * large et fenêtre plus longue pour ne pas gêner un usage normal
     * (plusieurs postes/IP légitimes sur un même compte au fil de la
     * journée), mais suffisant pour rendre l'épuisement des 10 000 codes
     * possibles impraticable même en rotation d'IP.
     */
    private const MAX_TENTATIVES_COMPTE = 15;

    private const DECOMPTE_COMPTE_SECONDES = 900;

    public function create(): View
    {
        // Coordonnées du support, pour qui a perdu son accès (voir le bloc
        // « Accès perdu » de la page de connexion).
        return view('auth.login', ['support' => ConfigurationAbonnement::actuel()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'username' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $cle = $this->cleLimitation($request, $donnees['username']);
        $cleCompte = $this->cleLimitationCompte($donnees['username']);

        if (RateLimiter::tooManyAttempts($cle, self::MAX_TENTATIVES)
            || RateLimiter::tooManyAttempts($cleCompte, self::MAX_TENTATIVES_COMPTE)) {
            $secondes = max(RateLimiter::availableIn($cle), RateLimiter::availableIn($cleCompte));

            throw ValidationException::withMessages([
                'username' => "Trop de tentatives. Réessayez dans {$secondes} secondes.",
            ]);
        }

        // Le code à 4 chiffres est stocké comme n'importe quel mot de passe
        // (colonne password, hashé) : seul le champ de connexion change.
        if (! Auth::attempt(['username' => $donnees['username'], 'password' => $donnees['code']])) {
            RateLimiter::hit($cle, self::DECOMPTE_SECONDES);
            RateLimiter::hit($cleCompte, self::DECOMPTE_COMPTE_SECONDES);

            throw ValidationException::withMessages([
                'username' => 'Identifiants incorrects.',
            ]);
        }

        RateLimiter::clear($cle);
        RateLimiter::clear($cleCompte);

        if (! Auth::user()->actif) {
            Auth::logout();

            throw ValidationException::withMessages([
                'username' => 'Ce compte est désactivé.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    private function cleLimitation(Request $request, string $username): string
    {
        return Str::transliterate(Str::lower($username)).'|'.$request->ip();
    }

    private function cleLimitationCompte(string $username): string
    {
        return 'compte|'.Str::transliterate(Str::lower($username));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
