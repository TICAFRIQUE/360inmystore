<?php

namespace App\Http\Controllers;

use App\Models\Abonnement;
use App\Models\AbonnementActivation;
use App\Models\ConfigurationAbonnement;
use App\Models\FormuleAbonnement;
use App\Rules\TelephoneValide;
use App\Support\Telephone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AbonnementController extends Controller
{
    /**
     * Lecture seule, accessible à tout utilisateur connecté — sert aussi
     * d'écran de redirection quand l'abonnement est expiré (voir
     * AssureAbonnementActif), donc jamais de garde ici.
     */
    public function mon(Request $request): View
    {
        $derniere = AbonnementActivation::query()->with('formule')->latest('date_debut')->latest('id')->first();

        // Vue propre à la plateforme : choix de la formule et paiement par
        // le propriétaire (« abonnement.mon » reste l'écran de la version
        // installée, où l'éditeur active l'abonnement à la main).
        return view('abonnement.espace', [
            'derniere' => $derniere,
            'bloquant' => Abonnement::estBloquant(),
            'joursRestants' => Abonnement::joursRestants(),
            'historique' => AbonnementActivation::query()->with(['formule', 'auteur'])
                ->latest('date_debut')->latest('id')->paginate(15),
            'configuration' => ConfigurationAbonnement::actuel(),
            'estGestionnaire' => (bool) $request->user()?->estGestionnaireAbonnement(),
            // Pour la section "Formules disponibles" (choix indicatif, en
            // attendant un moyen de paiement en ligne — voir la vue).
            // Catalogue de la plateforme. La formule gratuite (l'essai) n'est
            // proposée qu'au démarrage : tant que l'espace n'a jamais eu
            // d'abonnement.
            'formules' => FormuleAbonnement::catalogue()->where('actif', true)
                ->when($derniere !== null, fn ($q) => $q->where('prix', '>', 0))
                ->orderBy('prix')->get(),
            // Le propriétaire de l'espace choisit et paie sa formule lui-même.
            'paiementEnLigne' => plateforme()->estEntreprise() && $request->user()->hasRole('Superadmin'),
        ]);
    }

    public function gestion(): View
    {
        $derniere = AbonnementActivation::query()->with('formule')->latest('date_debut')->latest('id')->first();

        return view('abonnement.gestion', [
            'derniere' => $derniere,
            'joursRestants' => Abonnement::joursRestants(),
            'formules' => FormuleAbonnement::query()->orderByDesc('actif')->orderBy('jours')->get(),
            'historique' => AbonnementActivation::query()->with(['formule', 'auteur'])
                ->latest('date_debut')->latest('id')->paginate(15),
            'configuration' => ConfigurationAbonnement::actuel(),
        ]);
    }

    public function activer(Request $request): RedirectResponse
    {
        // Une case décochée n'envoie aucun champ "illimite" du tout (comportement
        // HTML standard) — jamais valider sa présence, toujours lire via boolean().
        $illimite = $request->boolean('illimite');

        $donnees = $request->validate([
            'formule_id' => ['nullable', 'exists:formule_abonnements,id'],
            'jours' => [$illimite ? 'nullable' : 'required', 'nullable', 'integer', 'min:1'],
            'montant' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $formule = ! empty($donnees['formule_id']) ? FormuleAbonnement::findOrFail($donnees['formule_id']) : null;

        Abonnement::activer(
            formule: $formule,
            montant: $donnees['montant'],
            jours: $illimite ? null : (int) $donnees['jours'],
            illimite: $illimite,
            note: $donnees['note'] ?? null,
            auteur: $request->user(),
            remplacer: $request->boolean('remplacer'),
        );

        return redirect()->route('abonnement.gestion')->with('succes', 'Abonnement activé.');
    }

    public function storeFormule(Request $request): RedirectResponse
    {
        $illimite = $request->boolean('illimite');

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'jours' => [$illimite ? 'nullable' : 'required', 'nullable', 'integer', 'min:1'],
            'prix' => ['required', 'integer', 'min:0'],
        ]);

        FormuleAbonnement::create([
            'nom' => $donnees['nom'],
            'illimite' => $illimite,
            'jours' => $illimite ? null : $donnees['jours'],
            'prix' => $donnees['prix'],
            'actif' => true,
        ]);

        return redirect()->route('abonnement.gestion')->with('succes', 'Formule créée.');
    }

    public function updateFormule(Request $request, FormuleAbonnement $formuleAbonnement): RedirectResponse
    {
        $illimite = $request->boolean('illimite');

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'jours' => [$illimite ? 'nullable' : 'required', 'nullable', 'integer', 'min:1'],
            'prix' => ['required', 'integer', 'min:0'],
        ]);

        $formuleAbonnement->update([
            'nom' => $donnees['nom'],
            'illimite' => $illimite,
            'jours' => $illimite ? null : $donnees['jours'],
            'prix' => $donnees['prix'],
        ]);

        return redirect()->route('abonnement.gestion')->with('succes', 'Formule modifiée.');
    }

    public function toggleFormule(FormuleAbonnement $formuleAbonnement): RedirectResponse
    {
        $formuleAbonnement->update(['actif' => ! $formuleAbonnement->actif]);

        return redirect()->route('abonnement.gestion')->with('succes', 'Formule mise à jour.');
    }

    public function updateConfiguration(Request $request): RedirectResponse
    {
        Telephone::normaliserDansRequete($request);
        Telephone::normaliserDansRequete($request, 'whatsapp');

        $donnees = $request->validate([
            'telephone' => ['nullable', 'string', 'max:50', new TelephoneValide],
            'whatsapp' => ['nullable', 'string', 'max:50', new TelephoneValide],
            'email' => ['nullable', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        ConfigurationAbonnement::actuel()->update($donnees);

        return redirect()->route('abonnement.gestion')->with('succes', 'Coordonnées mises à jour.');
    }
}
