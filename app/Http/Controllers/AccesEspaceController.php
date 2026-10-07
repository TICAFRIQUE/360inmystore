<?php

namespace App\Http\Controllers;

use App\Models\Plateforme\AccesEspace;
use App\Models\User;
use App\Plateforme\LienAcces;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Arrivée dans l'espace d'une entreprise par un lien d'accès direct (voir
 * App\Plateforme\LienAcces) : ouvre la session du propriétaire de l'espace,
 * sans mot de passe.
 *
 *  - après une inscription : le propriétaire arrive connecté, sur le choix de
 *    sa formule ;
 *  - en maintenance : l'éditeur entre dans l'espace d'un client. La session
 *    est marquée (bandeau à l'écran, abonnement expiré non bloquant) et
 *    l'accès est inscrit au journal d'activité du client.
 */
class AccesEspaceController extends Controller
{
    /** Clé de session d'un accès de maintenance : nom de l'éditeur. */
    public const CLE_MAINTENANCE = 'plateforme.maintenance';

    public function __invoke(Request $request, string $jeton, LienAcces $liens): RedirectResponse
    {
        abort_unless(plateforme()->estEntreprise(), 404);

        $acces = $liens->consommer($jeton);

        // Compte du propriétaire : le premier Superadmin de l'espace, créé à
        // l'inscription (voir entreprises:initialiser).
        $proprietaire = $acces ? User::role('Superadmin')->where('actif', true)->orderBy('id')->first() : null;

        if (! $proprietaire) {
            return redirect()->route('login')->withErrors([
                'username' => "Ce lien d'accès n'est plus valable. Connectez-vous avec vos identifiants.",
            ]);
        }

        Auth::login($proprietaire);
        $request->session()->regenerate();

        if ($acces->motif === AccesEspace::MAINTENANCE) {
            $request->session()->put(self::CLE_MAINTENANCE, $acces->auteur ?? 'Éditeur');

            activity()
                ->causedBy($proprietaire)
                ->withProperties(['editeur' => $acces->auteur])
                ->log("Accès de maintenance de l'éditeur à cet espace.");

            return redirect()->route('dashboard');
        }

        return redirect()->route('abonnement.mon');
    }
}
