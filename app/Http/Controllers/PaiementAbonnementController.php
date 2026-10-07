<?php

namespace App\Http\Controllers;

use App\Models\FormuleAbonnement;
use App\Plateforme\Paiement\AbonnementEnLigne;
use App\Plateforme\Paiement\PaiementRefuseException;
use App\Plateforme\Paiement\PasserellePaiement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Souscription d'une formule depuis l'espace d'une entreprise : essai gratuit
 * de démarrage ou paiement en ligne (voir AbonnementEnLigne).
 *
 * Réservé au propriétaire de l'espace (rôle Superadmin de l'entreprise), et
 * accessible même abonnement expiré : c'est précisément l'écran qui permet de
 * le renouveler (voir AssureAbonnementActif).
 */
class PaiementAbonnementController extends Controller
{
    public function create(Request $request, int $formule, PasserellePaiement $passerelle): View
    {
        $this->autoriser($request);

        return view('abonnement.paiement', [
            'formule' => $this->formule($formule),
            'moyens' => $passerelle->moyens(),
            'simulation' => $passerelle->estSimulation(),
        ]);
    }

    public function store(Request $request, int $formule, AbonnementEnLigne $abonnement, PasserellePaiement $passerelle): RedirectResponse
    {
        $this->autoriser($request);

        $donnees = $request->validate([
            'moyen' => ['required', Rule::in(array_keys($passerelle->moyens()))],
            // Issue choisie par le testeur — ignorée par un vrai prestataire.
            'resultat' => ['nullable', Rule::in(['reussi', 'refuse'])],
        ]);

        try {
            $paiement = $abonnement->payer(
                plateforme()->entreprise(),
                $this->formule($formule),
                $donnees['moyen'],
                $request->user(),
                ['resultat' => $donnees['resultat'] ?? 'reussi'],
            );
        } catch (PaiementRefuseException $refus) {
            return back()->withInput()->with('erreur', $refus->getMessage());
        }

        return redirect()->route('dashboard')
            ->with('succes', "Paiement {$paiement->reference} enregistré : votre abonnement est activé.");
    }

    /**
     * Formule gratuite du catalogue : l'essai, sans paiement, proposé une
     * seule fois — tant que l'espace n'a jamais eu d'abonnement.
     */
    public function essai(Request $request, int $formule, AbonnementEnLigne $abonnement): RedirectResponse
    {
        $this->autoriser($request);

        $gratuite = FormuleAbonnement::catalogue()->where('actif', true)->where('prix', 0)->findOrFail($formule);

        try {
            $abonnement->demarrerEssai(plateforme()->entreprise(), $gratuite, $request->user());
        } catch (RuntimeException $erreur) {
            return redirect()->route('abonnement.mon')->with('erreur', $erreur->getMessage());
        }

        return redirect()->route('dashboard')->with('succes', "Votre essai gratuit a commencé. Bienvenue !");
    }

    private function autoriser(Request $request): void
    {
        abort_unless(plateforme()->estEntreprise(), 404);
        abort_unless($request->user()->hasRole('Superadmin'), 403);
    }

    /**
     * Formule du catalogue de la plateforme — jamais une liaison de route
     * implicite, qui chercherait dans la base de l'entreprise. Une formule
     * gratuite ne se « paie » pas : l'essai est accordé une fois, à
     * l'inscription.
     */
    private function formule(int $id): FormuleAbonnement
    {
        return FormuleAbonnement::catalogue()->where('actif', true)->where('prix', '>', 0)->findOrFail($id);
    }
}
