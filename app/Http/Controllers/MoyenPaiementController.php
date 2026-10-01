<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Models\MoyenPaiement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MoyenPaiementController extends Controller
{
    use ExporteListe;

    private const ENTETES_EXPORT = ['Nom', 'Espèces (tiroir)', 'Statut'];

    public function index(): View
    {
        $moyensPaiement = $this->requeteListe()->get();

        return view('moyens-paiement.index', ['moyensPaiement' => $moyensPaiement]);
    }

    public function pdf(): Response
    {
        return $this->pdfDepuisListe('Moyens de paiement', self::ENTETES_EXPORT, $this->lignesExport(), 'moyens-de-paiement.pdf');
    }

    public function excel(): StreamedResponse
    {
        return $this->excelDepuisListe('Moyens de paiement', self::ENTETES_EXPORT, $this->lignesExport(), 'moyens-de-paiement.xlsx');
    }

    /**
     * Requête de la liste, partagée par l'écran et ses exports.
     */
    private function requeteListe(): Builder
    {
        return MoyenPaiement::query()->orderBy('nom');
    }

    private function lignesExport(): Collection
    {
        return $this->requeteListe()->get()->map(fn (MoyenPaiement $moyenPaiement) => [
            $moyenPaiement->nom,
            $moyenPaiement->est_espece ? 'Oui' : 'Non',
            $moyenPaiement->actif ? 'Actif' : 'Inactif',
        ]);
    }

    public function create(): View
    {
        return view('moyens-paiement.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:255', 'unique:moyen_paiements,nom'],
        ]);

        // est_espece n'est jamais modifiable depuis l'UI : un seul moyen de paiement
        // (« Espèces », seedé) alimente le comptage du tiroir.
        MoyenPaiement::create($donnees + ['actif' => true, 'est_espece' => false]);

        return redirect()->route('moyens-paiement.index')->with('succes', 'Moyen de paiement créé.');
    }

    public function edit(MoyenPaiement $moyenPaiement): View
    {
        return view('moyens-paiement.edit', ['moyenPaiement' => $moyenPaiement]);
    }

    public function update(Request $request, MoyenPaiement $moyenPaiement): RedirectResponse
    {
        if ($moyenPaiement->est_espece) {
            return redirect()->route('moyens-paiement.index')->with('erreur', 'Le moyen de paiement espèces ne peut pas être modifié.');
        }

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:255', 'unique:moyen_paiements,nom,'.$moyenPaiement->id],
            'actif' => ['boolean'],
        ]);
        $donnees['actif'] = $request->boolean('actif');

        $moyenPaiement->update($donnees);

        return redirect()->route('moyens-paiement.index')->with('succes', 'Moyen de paiement mis à jour.');
    }

    public function destroy(MoyenPaiement $moyenPaiement): RedirectResponse
    {
        if ($moyenPaiement->est_espece) {
            return redirect()->route('moyens-paiement.index')->with('erreur', 'Le moyen de paiement espèces ne peut pas être supprimé.');
        }

        $moyenPaiement->delete();

        return redirect()->route('moyens-paiement.index')->with('succes', 'Moyen de paiement supprimé.');
    }
}
