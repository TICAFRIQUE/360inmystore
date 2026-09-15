<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\TrieListe;
use App\Models\Categorie;
use App\Models\Magasin;
use App\Models\Produit;
use App\Models\Unite;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProduitController extends Controller
{
    use TrieListe, ExporteListe;

    /**
     * Disponibilité d'un produit par magasin/dépôt — alimente le sélecteur
     * de source à la vente (voir CLAUDE.md), interrogé à la demande quand le
     * caissier veut prélever ailleurs qu'au magasin de sa caisse.
     */
    public function stockParMagasin(Produit $produit, StockService $stockService): JsonResponse
    {
        return response()->json($stockService->disponibiliteParMagasin($produit)->values());
    }

    public function index(Request $request): View
    {
        $query = Produit::query()
            ->with(['categorie', 'stocks'])
            ->when($request->filled('recherche'), function ($query) use ($request) {
                $recherche = $request->string('recherche');
                $query->where(function ($q) use ($recherche) {
                    $q->where('sku', 'like', "%{$recherche}%")
                        ->orWhere('nom', 'like', "%{$recherche}%")
                        ->orWhere('code_barre', 'like', "%{$recherche}%");
                });
            });

        $query = $this->appliquerTri($query, $request, ['nom', 'sku', 'prix_piece', 'actif', 'created_at']);

        // L'impression (voir x-bouton-imprimer) couvre tout le résultat
        // filtré, pas seulement la page affichée à l'écran.
        $produits = $request->boolean('tout') ? $query->get() : $query->paginate(20)->withQueryString();

        return view('produits.index', [
            'produits' => $produits,
            // Tous les magasins/dépôts actifs : la colonne "Stock" affiche
            // leur total (voir Produit::totalStock()).
            'magasinsActifs' => Magasin::where('actif', true)->orderBy('nom')->get(),
        ]);
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe(
            'Produits',
            ['SKU', 'Produit', 'Catégorie', 'Stock', 'Prix pièce', 'Statut'],
            $this->lignesExport($request),
            'produits.pdf',
        );
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe(
            'Produits',
            ['SKU', 'Produit', 'Catégorie', 'Stock', 'Prix pièce', 'Statut'],
            $this->lignesExport($request),
            'produits.xlsx',
        );
    }

    /**
     * Même filtre de recherche que l'écran, sans pagination (voir CLAUDE.md
     * — les exports réutilisent la requête filtrée de l'écran).
     */
    private function lignesExport(Request $request): \Illuminate\Support\Collection
    {
        $magasinsActifs = Magasin::where('actif', true)->orderBy('nom')->get();

        $query = Produit::query()
            ->with(['categorie', 'stocks'])
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where(function ($sub) use ($recherche) {
                    $sub->where('sku', 'like', "%{$recherche}%")
                        ->orWhere('nom', 'like', "%{$recherche}%")
                        ->orWhere('code_barre', 'like', "%{$recherche}%");
                });
            });

        return $this->appliquerTri($query, $request, ['nom', 'sku', 'prix_piece', 'actif', 'created_at'], 'nom', 'asc')
            ->get()
            ->map(fn (Produit $p) => [
                $p->sku,
                $p->libelle_affichage,
                $p->categorie->nom,
                quantite($p->totalStock($magasinsActifs)['quantite']).' '.$p->unite_base_libelle_complet,
                montant($p->prix_piece),
                $p->actif ? 'Actif' : 'Inactif',
            ]);
    }

    public function create(): View
    {
        abort_unless($this->peut('produit.creer'), 403);

        return view('produits.create', [
            'categories' => Categorie::orderBy('nom')->get(),
            'unites' => Unite::where('actif', true)->orderBy('nom')->get(),
            'nomsExistants' => Produit::where('actif', true)->pluck('nom'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->peut('produit.creer'), 403);

        $donnees = $this->valider($request);
        $skuGenere = blank($donnees['sku']);

        if ($skuGenere) {
            $donnees['sku'] = $this->genererSku();
        }

        $lots = $this->validerLots($request);

        $produit = DB::transaction(function () use ($donnees, $lots) {
            $produit = Produit::create($donnees);

            foreach ($lots as $lot) {
                $produit->uniteVentes()->create($lot + ['actif' => true]);
            }

            return $produit;
        });

        if ($request->hasFile('image')) {
            $produit->addMediaFromRequest('image')->toMediaCollection('image');
        }

        $message = 'Produit créé.'.($skuGenere ? " SKU généré automatiquement : {$produit->sku}." : '');
        if (count($lots) > 0) {
            $message .= ' '.count($lots).' unité(s) de vente ajoutée(s).';
        }

        return redirect()->route('produits.index')->with('succes', $message);
    }

    public function edit(Produit $produit): View
    {
        $produit->load(['categorie', 'uniteBase', 'uniteVentes' => fn ($q) => $q->orderBy('facteur'), 'uniteVentes.unite']);

        return view('produits.edit', [
            'produit' => $produit,
            'categories' => Categorie::orderBy('nom')->get(),
            // Pré-sélectionne le bon parent dans le select "Catégorie" pour
            // faire apparaître le select "Sous-catégorie" dès l'ouverture,
            // que le produit soit rattaché à une catégorie racine ou à une
            // sous-catégorie.
            'categorieParentInitiale' => $produit->categorie?->parent_id ?? $produit->categorie_id,
            'unites' => Unite::where('actif', true)->orderBy('nom')->get(),
            'nomsExistants' => Produit::where('actif', true)->where('id', '!=', $produit->id)->pluck('nom'),
            'peutModifier' => $this->peut('produit.modifier'),
            'peutSupprimer' => $this->peut('produit.supprimer'),
        ]);
    }

    public function update(Request $request, Produit $produit): RedirectResponse
    {
        abort_unless($this->peut('produit.modifier'), 403);

        $donnees = $this->valider($request, $produit);
        $skuGenere = blank($donnees['sku']);

        if ($skuGenere) {
            $donnees['sku'] = $this->genererSku();
        }

        $produit->update($donnees);

        if ($request->hasFile('image')) {
            $produit->addMediaFromRequest('image')->toMediaCollection('image');
        }

        $message = 'Produit mis à jour.'.($skuGenere ? " SKU généré automatiquement : {$produit->sku}." : '');

        return redirect()->route('produits.edit', $produit)->with('succes', $message);
    }

    public function destroy(Produit $produit): RedirectResponse
    {
        abort_unless($this->peut('produit.supprimer'), 403);

        $produit->delete();

        return redirect()->route('produits.index')->with('succes', 'Produit supprimé.');
    }

    private function peut(string $permission): bool
    {
        return request()->user()->can($permission);
    }

    private function genererSku(): string
    {
        do {
            $sku = 'PRD-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (Produit::withTrashed()->where('sku', $sku)->exists());

        return $sku;
    }

    /**
     * @return array<int, array{unite_id: int, facteur: int, prix: int}>
     */
    private function validerLots(Request $request): array
    {
        $vus = [];

        $donnees = $request->validate([
            'unites_vente' => ['nullable', 'array'],
            'unites_vente.*.unite_id' => ['required', 'exists:unites,id'],
            'unites_vente.*.facteur' => [
                'required', 'integer', 'min:2',
                // Deux variantes de la même unité avec le même facteur
                // seraient indiscernables à la vente (ex. deux "Carton de
                // 24") — la contrainte unique en base le refuserait de toute
                // façon, mieux vaut un message clair ici.
                function (string $attribute, mixed $value, \Closure $fail) use ($request, &$vus) {
                    preg_match('/unites_vente\.(\d+)\.facteur/', $attribute, $m);
                    $index = (int) $m[1];
                    $uniteId = $request->input("unites_vente.{$index}.unite_id");
                    $cle = $uniteId.'|'.$value;

                    if (isset($vus[$cle])) {
                        $fail('Cette combinaison unité + facteur est déjà utilisée pour une autre variante.');
                    }
                    $vus[$cle] = true;
                },
            ],
            'unites_vente.*.prix' => ['required', 'integer', 'min:0'],
        ]);

        return $donnees['unites_vente'] ?? [];
    }

    private function valider(Request $request, ?Produit $produit = null): array
    {
        // Deux produits peuvent porter le même nom (CLAUDE.md — le SKU
        // identifie le produit) ; dans ce cas le libellé distinctif devient
        // obligatoire pour ne pas les confondre à la caisse.
        $nomEnDouble = Produit::where('actif', true)
            ->when($produit, fn ($q) => $q->where('id', '!=', $produit->id))
            ->where('nom', $request->input('nom'))
            ->exists();

        $donnees = $request->validate([
            'sku' => ['nullable', 'string', 'max:255', 'unique:produits,sku,'.($produit?->id)],
            'nom' => ['required', 'string', 'max:255'],
            'libelle_distinctif' => [Rule::requiredIf($nomEnDouble), 'nullable', 'string', 'max:255'],
            'code_barre' => ['nullable', 'string', 'max:255', 'unique:produits,code_barre,'.($produit?->id)],
            'categorie_id' => ['required', 'exists:categories,id'],
            'prix_piece' => ['required', 'integer', 'min:0'],
            'unite_base_id' => ['required', 'exists:unites,id'],
            'seuil_alerte' => ['required', 'integer', 'min:0'],
            'actif' => ['boolean'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);

        $donnees['actif'] = $request->boolean('actif', true);
        unset($donnees['image']);

        return $donnees;
    }
}
