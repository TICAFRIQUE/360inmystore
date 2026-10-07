<?php

namespace App\Http\Controllers\Plateforme;

use App\Http\Controllers\Controller;
use App\Models\FormuleAbonnement;
use App\Models\Plateforme\Entreprise;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AccueilController extends Controller
{
    /**
     * Racine du site. Sur le domaine principal : le site vitrine (avec les
     * tarifs, c'est-à-dire les formules actives du catalogue), ou le tableau
     * de bord pour l'éditeur déjà connecté. Dans l'espace d'une entreprise :
     * l'application elle-même.
     */
    public function __invoke(Request $request): View|RedirectResponse
    {
        if (! plateforme()->estCentrale()) {
            return redirect()->route('dashboard');
        }

        if ($request->user()) {
            return redirect()->route('plateforme.console');
        }

        $formules = FormuleAbonnement::where('actif', true)->orderBy('prix')->get();

        return view('plateforme.accueil', [
            'inscriptionOuverte' => (bool) config('plateforme.inscription.ouverte'),
            'formules' => $formules,
            // Formule gratuite du catalogue, s'il y en a une : l'essai.
            'essai' => $formules->first(fn (FormuleAbonnement $formule) => $formule->estGratuite()),
            'domaine' => config('plateforme.domaine'),
        ]);
    }

    /**
     * « Accéder à mon espace » : conduit à la page de connexion du
     * sous-domaine saisi. Dire qu'une adresse n'existe pas ne révèle rien :
     * un sous-domaine est public par nature.
     */
    public function espace(Request $request): RedirectResponse
    {
        $sousDomaine = Str::lower(trim((string) $request->input('sous_domaine')));

        $entreprise = Entreprise::where('sous_domaine', $sousDomaine)->where('statut', '!=', Entreprise::CREATION)->first();

        if (! $entreprise) {
            return back()->withInput()->withErrors(['sous_domaine' => "Aucun espace ne porte cette adresse."]);
        }

        return redirect()->away($entreprise->url('login'));
    }
}
