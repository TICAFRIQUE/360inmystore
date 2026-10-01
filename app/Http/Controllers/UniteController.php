<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Models\Unite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UniteController extends Controller
{
    use ExporteListe;

    private const ENTETES_EXPORT = ['Nom', 'Abréviation', 'Produits', 'Unités de vente', 'Statut'];

    public function index(): View
    {
        $unites = $this->requeteListe()->get();

        return view('unites.index', ['unites' => $unites]);
    }

    public function pdf(): Response
    {
        return $this->pdfDepuisListe('Unités', self::ENTETES_EXPORT, $this->lignesExport(), 'unites.pdf');
    }

    public function excel(): StreamedResponse
    {
        return $this->excelDepuisListe('Unités', self::ENTETES_EXPORT, $this->lignesExport(), 'unites.xlsx');
    }

    /**
     * Requête de la liste, partagée par l'écran et ses exports.
     */
    private function requeteListe(): Builder
    {
        return Unite::withCount(['produits', 'uniteVentes'])->orderBy('nom');
    }

    private function lignesExport(): Collection
    {
        return $this->requeteListe()->get()->map(fn (Unite $unite) => [
            $unite->nom,
            $unite->abbreviation ?? '—',
            $unite->produits_count,
            $unite->unite_ventes_count,
            $unite->actif ? 'Actif' : 'Inactif',
        ]);
    }

    public function create(): View
    {
        return view('unites.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:100', 'unique:unites,nom'],
            'abbreviation' => ['nullable', 'string', 'max:10'],
        ]);

        Unite::create($donnees + ['actif' => true]);

        return redirect()->route('unites.index')->with('succes', 'Unité créée.');
    }

    public function edit(Unite $unite): View
    {
        return view('unites.edit', ['unite' => $unite]);
    }

    public function update(Request $request, Unite $unite): RedirectResponse
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:100', 'unique:unites,nom,'.$unite->id],
            'abbreviation' => ['nullable', 'string', 'max:10'],
            'actif' => ['boolean'],
        ]);
        $donnees['actif'] = $request->boolean('actif');

        $unite->update($donnees);

        return redirect()->route('unites.index')->with('succes', 'Unité mise à jour.');
    }

    public function destroy(Unite $unite): RedirectResponse
    {
        if ($unite->produits()->exists() || $unite->uniteVentes()->exists()) {
            return redirect()->route('unites.index')->with('erreur', 'Cette unité est utilisée par au moins un produit, elle ne peut pas être supprimée. Désactivez-la à la place.');
        }

        $unite->delete();

        return redirect()->route('unites.index')->with('succes', 'Unité supprimée.');
    }
}
