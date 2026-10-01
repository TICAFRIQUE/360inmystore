<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\TrieListe;
use App\Models\Caisse;
use App\Models\Magasin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CaisseController extends Controller
{
    use TrieListe, ExporteListe;

    private const ENTETES_EXPORT = ['Nom', 'Magasin', 'État', 'Statut'];

    public function index(Request $request): View
    {
        $caisses = $this->requeteListe($request)->paginate(20)->withQueryString();

        return view('caisses.index', ['caisses' => $caisses]);
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe('Caisses', self::ENTETES_EXPORT, $this->lignesExport($request), 'caisses.pdf');
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe('Caisses', self::ENTETES_EXPORT, $this->lignesExport($request), 'caisses.xlsx');
    }

    /**
     * Requête de la liste (filtres + tri), partagée par l'écran et ses exports :
     * un export porte toujours sur ce que l'écran affiche, sans la pagination.
     */
    private function requeteListe(Request $request): Builder
    {
        $query = Caisse::query()
            ->with(['magasin', 'sessionCaisses' => fn ($q) => $q->whereNull('date_fermeture')])
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where('nom', 'like', "%{$recherche}%");
            });

        return $this->appliquerTri($query, $request, ['nom', 'actif', 'created_at']);
    }

    private function lignesExport(Request $request): Collection
    {
        return $this->requeteListe($request)->get()->map(fn (Caisse $caisse) => [
            $caisse->nom,
            $caisse->magasin->nom,
            $caisse->sessionCaisses->isNotEmpty() ? 'Occupée' : 'Libre',
            $caisse->actif ? 'Active' : 'Inactive',
        ]);
    }

    public function create(): View
    {
        return view('caisses.create', ['magasins' => Magasin::magasins()->where('actif', true)->orderBy('nom')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request);

        Caisse::create($donnees);

        return redirect()->route('caisses.index')->with('succes', 'Caisse créée.');
    }

    public function edit(Caisse $caisse): View
    {
        return view('caisses.edit', [
            'caisse' => $caisse,
            'magasins' => Magasin::magasins()->where('actif', true)->orderBy('nom')->get(),
        ]);
    }

    public function update(Request $request, Caisse $caisse): RedirectResponse
    {
        $donnees = $this->valider($request, $caisse);

        $caisse->update($donnees);

        return redirect()->route('caisses.index')->with('succes', 'Caisse mise à jour.');
    }

    public function destroy(Caisse $caisse): RedirectResponse
    {
        if ($caisse->sessionCaisses()->whereNull('date_fermeture')->exists()) {
            return redirect()->route('caisses.index')->with('erreur', 'Cette caisse a une session ouverte, elle ne peut pas être supprimée.');
        }

        $caisse->delete();

        return redirect()->route('caisses.index')->with('succes', 'Caisse supprimée.');
    }

    private function valider(Request $request, ?Caisse $caisse = null): array
    {
        $donnees = $request->validate([
            'magasin_id' => ['required', 'exists:magasins,id'],
            'nom' => ['required', 'string', 'max:255'],
            'actif' => ['boolean'],
        ]);

        $donnees['actif'] = $request->boolean('actif', true);

        return $donnees;
    }
}
