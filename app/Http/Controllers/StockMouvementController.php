<?php

namespace App\Http\Controllers;

use App\Enums\MouvementStockType;
use App\Exceptions\StockInsuffisantException;
use App\Http\Controllers\Concerns\AutoriseMagasin;
use App\Http\Controllers\Concerns\ExporteListe;
use App\Models\Magasin;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\Stock;
use App\Services\StockService;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockMouvementController extends Controller
{
    use AutoriseMagasin, ExporteListe;

    private const ENTETES_EXPORT = ['Date', 'Type', 'Produit', 'SKU', 'Destination', 'Quantité', 'Auteur', 'Motif'];

    public function index(Request $request): View
    {
        [$debut, $fin, $type] = $this->filtresListe($request);

        return view('stock.mouvements-index', [
            'mouvements' => $this->requeteListe($request)->paginate(30)->withQueryString(),
            'dateDebut' => $debut->toDateString(),
            'dateFin' => $fin->toDateString(),
            'type' => $type,
            'types' => MouvementStockType::cases(),
        ]);
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe('Mouvements de stock', self::ENTETES_EXPORT, $this->lignesExport($request), 'mouvements-de-stock.pdf', $this->sousTitreFiltres($request));
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe('Mouvements de stock', self::ENTETES_EXPORT, $this->lignesExport($request), 'mouvements-de-stock.xlsx');
    }

    /**
     * Filtres affichés par la liste : mois en cours tant qu'aucune date
     * n'est choisie, tous types confondus par défaut.
     *
     * @return array{0: Carbon, 1: Carbon, 2: ?MouvementStockType}
     */
    private function filtresListe(Request $request): array
    {
        return [
            $request->filled('date_debut') ? Carbon::parse($request->string('date_debut')) : now()->startOfMonth(),
            $request->filled('date_fin') ? Carbon::parse($request->string('date_fin')) : now()->endOfMonth(),
            MouvementStockType::tryFrom($request->string('type')->toString()),
        ];
    }

    private function sousTitreFiltres(Request $request): string
    {
        [$debut, $fin, $type] = $this->filtresListe($request);

        return 'Du '.$debut->format('d/m/Y').' au '.$fin->format('d/m/Y').($type ? ' · Type : '.$type->libelle() : '');
    }

    /**
     * Requête de la liste (filtres + tri), partagée par l'écran et ses exports :
     * un export porte toujours sur ce que l'écran affiche, sans la pagination.
     */
    private function requeteListe(Request $request): Builder
    {
        [$debut, $fin, $type] = $this->filtresListe($request);

        return MouvementStock::query()
            ->with(['produit', 'magasin', 'auteur'])
            ->when($request->user()->magasin_id, fn ($q, $magasinId) => $q->where('magasin_id', $magasinId))
            ->whereBetween('created_at', [$debut->copy()->startOfDay(), $fin->copy()->endOfDay()])
            ->when($type, fn ($q) => $q->where('type', $type->value))
            ->orderByDesc('created_at');
    }

    private function lignesExport(Request $request): Collection
    {
        return $this->requeteListe($request)->get()->map(fn (MouvementStock $mouvement) => [
            $mouvement->created_at->format('d/m/Y H:i'),
            $mouvement->type->libelle(),
            $mouvement->produit->libelle_affichage,
            $mouvement->produit->sku,
            $mouvement->magasin->nom,
            ($mouvement->quantite >= 0 ? '+' : '').quantite($mouvement->quantite),
            $mouvement->auteur->name,
            $mouvement->motif ?? '—',
        ]);
    }

    public function create(Request $request): View
    {
        $magasinId = $request->integer('magasin_id') ?: null;

        $produits = Produit::where('actif', true)->orderBy('nom')->get(['id', 'sku', 'nom', 'libelle_distinctif']);

        if ($magasinId) {
            $stocksParProduit = Stock::where('magasin_id', $magasinId)->pluck('quantite', 'produit_id');
            $produits->each(fn (Produit $p) => $p->stock_magasin = $stocksParProduit[$p->id] ?? 0);
        }

        return view('stock.mouvement-create', [
            'produits' => $produits,
            'magasins' => Magasin::where('actif', true)->orderBy('nom')->get(),
            'magasinId' => $magasinId,
        ]);
    }

    public function store(Request $request, StockService $stockService): RedirectResponse
    {
        $request->merge(['quantite' => Decimal::normaliser($request->input('quantite'))]);

        $donnees = $request->validate([
            'type' => ['required', 'in:casse,ajustement'],
            'direction' => ['required_if:type,ajustement', 'in:entree,sortie'],
            'produit_id' => ['required', 'exists:produits,id'],
            'magasin_id' => ['required', 'exists:magasins,id'],
            'quantite' => ['required', 'numeric', 'min:0.001'],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);

        // Un ajustement/casse est mono-magasin (contrairement à un transfert,
        // qui vise par nature deux magasins différents) : un gérant local ne
        // doit pouvoir en poser que sur son propre magasin.
        $this->assurerMagasin((int) $donnees['magasin_id']);

        $estCasse = $donnees['type'] === 'casse';
        $type = $estCasse ? MouvementStockType::Casse : MouvementStockType::Ajustement;
        $signe = $estCasse || $donnees['direction'] === 'sortie' ? -1 : 1;

        try {
            $stockService->enregistrerMouvement(
                produit: Produit::findOrFail($donnees['produit_id']),
                magasin: Magasin::findOrFail($donnees['magasin_id']),
                quantite: $signe * (float) $donnees['quantite'],
                type: $type,
                auteur: $request->user(),
                motif: $donnees['motif'] ?: ($estCasse ? 'Casse' : 'Ajustement manuel'),
            );
        } catch (StockInsuffisantException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        return redirect()->route('stock.index')->with('succes', 'Mouvement enregistré.');
    }
}
