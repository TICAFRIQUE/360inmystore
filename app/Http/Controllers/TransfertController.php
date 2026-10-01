<?php

namespace App\Http\Controllers;

use App\Exceptions\StockInsuffisantException;
use App\Http\Controllers\Concerns\ExporteListe;
use App\Models\Magasin;
use App\Models\Produit;
use App\Models\Stock;
use App\Models\Transfert;
use App\Services\TransfertService;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransfertController extends Controller
{
    use ExporteListe;

    private const ENTETES_EXPORT = ['Date', 'Produit', 'SKU', 'De', 'Vers', 'Quantité', 'Par'];

    public function index(): View
    {
        $transferts = $this->requeteListe()->paginate(20);

        return view('transferts.index', ['transferts' => $transferts]);
    }

    public function pdf(): Response
    {
        return $this->pdfDepuisListe('Transferts de stock', self::ENTETES_EXPORT, $this->lignesExport(), 'transferts-de-stock.pdf');
    }

    public function excel(): StreamedResponse
    {
        return $this->excelDepuisListe('Transferts de stock', self::ENTETES_EXPORT, $this->lignesExport(), 'transferts-de-stock.xlsx');
    }

    /**
     * Requête de la liste, partagée par l'écran et ses exports.
     */
    private function requeteListe(): Builder
    {
        return Transfert::query()
            ->with(['produit', 'magasinSource', 'magasinDestination', 'auteur'])
            ->orderByDesc('created_at');
    }

    private function lignesExport(): Collection
    {
        return $this->requeteListe()->get()->map(fn (Transfert $transfert) => [
            $transfert->created_at->format('d/m/Y H:i'),
            $transfert->produit->libelle_affichage,
            $transfert->produit->sku,
            $transfert->magasinSource->nom,
            $transfert->magasinDestination->nom,
            quantite($transfert->quantite),
            $transfert->auteur->name,
        ]);
    }

    public function create(Request $request): View
    {
        $magasinSourceId = $request->integer('magasin_source_id') ?: null;

        $produits = collect();

        if ($magasinSourceId) {
            $stocksParProduit = Stock::where('magasin_id', $magasinSourceId)->where('quantite', '>', 0)->pluck('quantite', 'produit_id');

            $produits = Produit::where('actif', true)
                ->whereIn('id', $stocksParProduit->keys())
                ->orderBy('nom')
                ->get(['id', 'sku', 'nom', 'libelle_distinctif'])
                ->each(fn (Produit $p) => $p->stock_magasin = $stocksParProduit[$p->id]);
        }

        return view('transferts.create', [
            'produits' => $produits,
            'magasins' => Magasin::where('actif', true)->orderBy('nom')->get(),
            'magasinSourceId' => $magasinSourceId,
        ]);
    }

    public function store(Request $request, TransfertService $transfertService): RedirectResponse
    {
        $request->merge(['quantite' => Decimal::normaliser($request->input('quantite'))]);

        $donnees = $request->validate([
            'produit_id' => ['required', 'exists:produits,id'],
            'magasin_source_id' => ['required', 'exists:magasins,id', 'different:magasin_destination_id'],
            'magasin_destination_id' => ['required', 'exists:magasins,id'],
            'quantite' => ['required', 'numeric', 'min:0.001'],
        ]);

        try {
            $transfertService->transferer(
                Produit::findOrFail($donnees['produit_id']),
                Magasin::findOrFail($donnees['magasin_source_id']),
                Magasin::findOrFail($donnees['magasin_destination_id']),
                (float) $donnees['quantite'],
                $request->user(),
            );
        } catch (StockInsuffisantException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        return redirect()->route('transferts.index')->with('succes', 'Transfert effectué.');
    }
}
