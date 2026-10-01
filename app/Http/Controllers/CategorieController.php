<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\TrieListe;
use App\Models\Categorie;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CategorieController extends Controller
{
    use TrieListe, ExporteListe;

    private const ENTETES_EXPORT = ['Nom', 'Catégorie parente', 'Produits', 'Statut'];

    public function index(Request $request): View
    {
        $categories = $this->requeteListe($request)->paginate(20)->withQueryString();

        return view('categories.index', ['categories' => $categories]);
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe('Catégories', self::ENTETES_EXPORT, $this->lignesExport($request), 'categories.pdf');
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe('Catégories', self::ENTETES_EXPORT, $this->lignesExport($request), 'categories.xlsx');
    }

    /**
     * Requête de la liste (filtres + tri), partagée par l'écran et ses exports :
     * un export porte toujours sur ce que l'écran affiche, sans la pagination.
     */
    private function requeteListe(Request $request): Builder
    {
        $query = Categorie::query()
            ->withCount('produits')
            ->with('parent')
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where('nom', 'like', "%{$recherche}%");
            });

        return $this->appliquerTri($query, $request, ['nom', 'created_at']);
    }

    private function lignesExport(Request $request): Collection
    {
        return $this->requeteListe($request)->get()->map(fn (Categorie $categorie) => [
            $categorie->nom,
            $categorie->parent->nom ?? '—',
            $categorie->produits_count,
            $categorie->actif ? 'Active' : 'Inactive',
        ]);
    }

    public function create(): View
    {
        return view('categories.create', [
            'categoriesParentes' => Categorie::whereNull('parent_id')->orderBy('nom')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request);

        Categorie::create($donnees);

        return redirect()->route('categories.index')->with('succes', 'Catégorie créée.');
    }

    public function edit(Categorie $categorie): View
    {
        return view('categories.edit', [
            'categorie' => $categorie,
            'categoriesParentes' => Categorie::whereNull('parent_id')
                ->where('id', '!=', $categorie->id)
                ->orderBy('nom')
                ->get(),
            'aDesEnfants' => $categorie->enfants()->exists(),
        ]);
    }

    public function update(Request $request, Categorie $categorie): RedirectResponse
    {
        $donnees = $this->valider($request, $categorie);

        $categorie->update($donnees);

        return redirect()->route('categories.index')->with('succes', 'Catégorie mise à jour.');
    }

    public function destroy(Categorie $categorie): RedirectResponse
    {
        if ($categorie->produits()->exists()) {
            return redirect()->route('categories.index')->with('erreur', 'Cette catégorie contient des produits, elle ne peut pas être supprimée.');
        }

        if ($categorie->enfants()->exists()) {
            return redirect()->route('categories.index')->with('erreur', 'Cette catégorie a des sous-catégories, elle ne peut pas être supprimée.');
        }

        $categorie->delete();

        return redirect()->route('categories.index')->with('succes', 'Catégorie supprimée.');
    }

    private function valider(Request $request, ?Categorie $categorie = null): array
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:255', 'unique:categories,nom,'.($categorie?->id)],
            'parent_id' => [
                'nullable',
                'exists:categories,id',
                function ($attribute, $value, $fail) use ($categorie) {
                    if (blank($value)) {
                        return;
                    }

                    if ($categorie && (int) $value === $categorie->id) {
                        $fail('Une catégorie ne peut pas être sa propre catégorie parente.');

                        return;
                    }

                    $parent = Categorie::find($value);
                    if ($parent && $parent->parent_id !== null) {
                        $fail('Une sous-catégorie ne peut pas elle-même avoir une sous-catégorie (un seul niveau autorisé).');
                    }

                    if ($categorie && $categorie->enfants()->exists()) {
                        $fail('Cette catégorie a déjà des sous-catégories, elle ne peut pas devenir une sous-catégorie.');
                    }
                },
            ],
        ]);

        $donnees['actif'] = $request->boolean('actif', true);

        return $donnees;
    }
}
