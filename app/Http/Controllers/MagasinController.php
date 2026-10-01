<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\TrieListe;
use App\Models\Magasin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MagasinController extends Controller
{
    use TrieListe, ExporteListe;

    private const ENTETES_EXPORT = ['Nom', 'Type', 'Adresse', 'Téléphone', 'Statut'];

    public function index(Request $request): View
    {
        $magasins = $this->requeteListe($request)->paginate(20)->withQueryString();

        return view('magasins.index', ['magasins' => $magasins]);
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe('Magasins', self::ENTETES_EXPORT, $this->lignesExport($request), 'magasins.pdf');
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe('Magasins', self::ENTETES_EXPORT, $this->lignesExport($request), 'magasins.xlsx');
    }

    /**
     * Requête de la liste (filtres + tri), partagée par l'écran et ses exports :
     * un export porte toujours sur ce que l'écran affiche, sans la pagination.
     */
    private function requeteListe(Request $request): Builder
    {
        $query = Magasin::query()
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where('nom', 'like', "%{$recherche}%");
            });

        return $this->appliquerTri($query, $request, ['nom', 'actif', 'created_at']);
    }

    private function lignesExport(Request $request): Collection
    {
        return $this->requeteListe($request)->get()->map(fn (Magasin $magasin) => [
            $magasin->nom,
            $magasin->estDepot() ? 'Dépôt' : 'Magasin',
            $magasin->adresse ?? '—',
            $magasin->telephone ?? '—',
            $magasin->actif ? 'Actif' : 'Inactif',
        ]);
    }

    public function create(): View
    {
        return view('magasins.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request);

        Magasin::create($donnees);

        return redirect()->route('magasins.index')->with('succes', 'Magasin créé.');
    }

    public function edit(Magasin $magasin): View
    {
        return view('magasins.edit', ['magasin' => $magasin]);
    }

    public function update(Request $request, Magasin $magasin): RedirectResponse
    {
        $donnees = $this->valider($request, $magasin);

        $magasin->update($donnees);

        return redirect()->route('magasins.index')->with('succes', 'Magasin mis à jour.');
    }

    public function destroy(Magasin $magasin): RedirectResponse
    {
        $magasin->delete();

        return redirect()->route('magasins.index')->with('succes', 'Magasin supprimé.');
    }

    private function valider(Request $request, ?Magasin $magasin = null): array
    {
        return $request->validate([
            'nom' => ['required', 'string', 'max:255', 'unique:magasins,nom,'.($magasin?->id)],
            'type' => ['required', 'in:magasin,depot'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:50'],
            'actif' => ['boolean'],
        ]) + ['actif' => $request->boolean('actif', true)];
    }
}
