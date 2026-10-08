<?php

namespace App\Http\Controllers\Plateforme;

use App\Http\Controllers\Controller;
use App\Models\ConfigurationAbonnement;
use App\Models\FormuleAbonnement;
use App\Rules\TelephoneValide;
use App\Support\Telephone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Formules d'abonnement de la plateforme, gérées par l'éditeur : ce sont
 * elles — et elles seules — qu'affichent la page des tarifs du site vitrine
 * et le choix de formule dans l'espace de chaque entreprise. Une formule à
 * 0 F est l'essai gratuit, proposé une fois au démarrage d'un espace.
 *
 * Une formule déjà souscrite ne se supprime pas, elle se désactive : chaque
 * entreprise garde de toute façon sa propre copie des conditions souscrites.
 */
class FormuleController extends Controller
{
    public function index(): View
    {
        return view('plateforme.formules', [
            'formules' => FormuleAbonnement::query()->orderByDesc('actif')->orderBy('prix')->get(),
            'contact' => ConfigurationAbonnement::actuel(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        FormuleAbonnement::create($this->valider($request) + ['actif' => true]);

        return back()->with('succes', 'Formule créée : elle apparaît sur la page des tarifs.');
    }

    public function update(Request $request, FormuleAbonnement $formule): RedirectResponse
    {
        $formule->update($this->valider($request));

        return back()->with('succes', 'Formule mise à jour.');
    }

    public function basculer(FormuleAbonnement $formule): RedirectResponse
    {
        $formule->update(['actif' => ! $formule->actif]);

        return back()->with('succes', $formule->actif ? 'Formule de nouveau proposée.' : 'Formule retirée de la page des tarifs.');
    }

    /** Contact affiché aux entreprises dont l'abonnement a expiré. */
    public function contact(Request $request): RedirectResponse
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

        return back()->with('succes', 'Coordonnées mises à jour.');
    }

    /**
     * @return array{nom: string, illimite: bool, jours: ?int, prix: int, description: ?string, avantages: ?string, mise_en_avant: bool}
     */
    private function valider(Request $request): array
    {
        // Une case décochée n'envoie aucun champ : toujours lire via boolean().
        $illimite = $request->boolean('illimite');

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'jours' => [$illimite ? 'nullable' : 'required', 'nullable', 'integer', 'min:1'],
            'prix' => ['required', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:255'],
            'avantages' => ['nullable', 'string', 'max:2000'],
            // Limites de la formule : vide = sans limite.
            'max_magasins' => ['nullable', 'integer', 'min:1'],
            'max_caisses' => ['nullable', 'integer', 'min:1'],
            'max_utilisateurs' => ['nullable', 'integer', 'min:1'],
            'max_produits' => ['nullable', 'integer', 'min:1'],
        ]);

        return [
            'nom' => $donnees['nom'],
            'illimite' => $illimite,
            'jours' => $illimite ? null : (int) $donnees['jours'],
            'prix' => (int) $donnees['prix'],
            'description' => $donnees['description'] ?? null,
            'avantages' => $donnees['avantages'] ?? null,
            'max_magasins' => $donnees['max_magasins'] ?? null,
            'max_caisses' => $donnees['max_caisses'] ?? null,
            'max_utilisateurs' => $donnees['max_utilisateurs'] ?? null,
            'max_produits' => $donnees['max_produits'] ?? null,
            'mise_en_avant' => $request->boolean('mise_en_avant'),
        ];
    }
}
