<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\TrieListe;
use App\Models\Taxe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaxeController extends Controller
{
    use TrieListe, ExporteListe;

    private const ENTETES_EXPORT = ['Nom', 'Taux', 'Statut'];

    public function index(Request $request): View
    {
        $taxes = $this->requeteListe($request)->paginate(20)->withQueryString();

        return view('taxes.index', ['taxes' => $taxes]);
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe('Taxes', self::ENTETES_EXPORT, $this->lignesExport($request), 'taxes.pdf');
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe('Taxes', self::ENTETES_EXPORT, $this->lignesExport($request), 'taxes.xlsx');
    }

    /**
     * Requête de la liste (filtres + tri), partagée par l'écran et ses exports :
     * un export porte toujours sur ce que l'écran affiche, sans la pagination.
     */
    private function requeteListe(Request $request): Builder
    {
        $query = Taxe::query()
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where('nom', 'like', "%{$recherche}%");
            });

        return $this->appliquerTri($query, $request, ['nom', 'taux', 'actif', 'created_at']);
    }

    private function lignesExport(Request $request): Collection
    {
        return $this->requeteListe($request)->get()->map(fn (Taxe $taxe) => [
            $taxe->nom,
            $taxe->taux.' %',
            $taxe->actif ? 'Active' : 'Inactive',
        ]);
    }

    public function create(): View
    {
        return view('taxes.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request);

        Taxe::create($donnees);

        return redirect()->route('taxes.index')->with('succes', 'Taxe créée.');
    }

    public function edit(Taxe $taxe): View
    {
        return view('taxes.edit', ['taxe' => $taxe]);
    }

    public function update(Request $request, Taxe $taxe): RedirectResponse
    {
        $donnees = $this->valider($request, $taxe);

        $taxe->update($donnees);

        return redirect()->route('taxes.index')->with('succes', 'Taxe mise à jour.');
    }

    public function destroy(Taxe $taxe): RedirectResponse
    {
        if ($taxe->lignesCommandeAchat()->exists()) {
            return redirect()->route('taxes.index')->with('erreur', 'Cette taxe est utilisée sur des lignes d\'achat, elle ne peut pas être supprimée.');
        }

        $taxe->delete();

        return redirect()->route('taxes.index')->with('succes', 'Taxe supprimée.');
    }

    private function valider(Request $request, ?Taxe $taxe = null): array
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:255', 'unique:taxes,nom,'.($taxe?->id)],
            'taux' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $donnees['actif'] = $request->boolean('actif', true);

        return $donnees;
    }
}
