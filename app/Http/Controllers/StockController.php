<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\TrieListe;
use App\Models\Magasin;
use App\Models\Produit;
use App\Models\Stock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockController extends Controller
{
    use TrieListe, ExporteListe;

    public function index(Request $request): View
    {
        $magasinsActifs = Magasin::where('actif', true)->orderBy('nom')->get();
        $magasinsAffiches = $this->magasinsAffiches($request, $magasinsActifs);

        $baseStocks = $this->requeteStocksFiltree($request);

        $kpis = [
            'totalPieces' => (clone $baseStocks)->sum('stocks.quantite'),
            'valeurStock' => (clone $baseStocks)->sum(DB::raw('stocks.quantite * stocks.cout_moyen_pondere')),
            'sousSeuil' => $this->requeteProduitsFiltree($request, sousSeuilUniquement: true)->count(),
            'nbMagasins' => $request->filled('magasin_id') ? 1 : $magasinsActifs->count(),
        ];

        $query = $this->requeteProduitsFiltree($request, sousSeuilUniquement: $request->boolean('sous_seuil'))
            ->with([
                'stocks' => fn ($q) => $q->when(
                    $request->filled('magasin_id'),
                    fn ($qq) => $qq->where('magasin_id', $request->integer('magasin_id')),
                ),
                // .magasin : nécessaire à Produit::coutMoyenPondere() pour
                // exclure les dépôts (colonne CMP, jamais dupliquée par vue).
                'stocks.magasin',
            ]);

        // Ordre alphabétique par défaut, comme avant la refonte (une ligne
        // par produit désormais — trier par quantité n'a plus de sens
        // unique, un produit ayant potentiellement une quantité différente
        // par magasin/dépôt).
        $query = $this->appliquerTri($query, $request, ['nom'], 'nom', 'asc');

        // L'impression (voir x-bouton-imprimer) couvre tout le résultat
        // filtré, pas seulement la page affichée à l'écran.
        $produits = $request->boolean('tout') ? $query->get() : $query->paginate(20)->withQueryString();

        return view('stock.index', [
            'produits' => $produits,
            'magasins' => $magasinsActifs,
            'magasinsAffiches' => $magasinsAffiches,
            'produitsFiltrables' => Produit::where('actif', true)->orderBy('nom')->get(['id', 'sku', 'nom', 'libelle_distinctif']),
            'kpis' => $kpis,
        ]);
    }

    public function pdf(Request $request): Response
    {
        $magasinsActifs = Magasin::where('actif', true)->orderBy('nom')->get();

        $pdf = Pdf::loadView('stock.pdf', [
            'produits' => $this->produitsExport($request),
            'magasinsAffiches' => $this->magasinsAffiches($request, $magasinsActifs),
            'filtres' => $this->libellesFiltres($request),
        ]);

        // ?imprimer=1 (voir x-bouton-imprimer) : ouvre le PDF dans l'iframe
        // caché au lieu de forcer un téléchargement, sinon le navigateur
        // déclenche un téléchargement du fichier au lieu d'imprimer — même
        // mécanisme que CommandeAchatController::pdf()/VenteController::pdf().
        $nomFichier = 'etat-du-stock.pdf';

        return $request->boolean('imprimer') ? $pdf->stream($nomFichier) : $pdf->download($nomFichier);
    }

    public function excel(Request $request): StreamedResponse
    {
        $magasinsActifs = Magasin::where('actif', true)->orderBy('nom')->get();
        $magasinsAffiches = $this->magasinsAffiches($request, $magasinsActifs);
        $produits = $this->produitsExport($request);

        $spreadsheet = new Spreadsheet();
        $feuille = $spreadsheet->getActiveSheet();
        $feuille->setTitle('État du stock');
        $feuille->fromArray(['Produit', 'SKU', 'Stock', 'Seuil d\'alerte', 'Coût moyen pondéré', 'Prix de vente'], null, 'A1');

        $ligne = 2;
        foreach ($produits as $produit) {
            $detail = quantite($produit->totalStock($magasinsAffiches)['quantite']).' '.$produit->unite_base_libelle_complet;

            $feuille->setCellValue("A{$ligne}", $produit->libelle_affichage);
            $feuille->setCellValue("B{$ligne}", $produit->sku);
            $feuille->setCellValue("C{$ligne}", $detail);
            $feuille->setCellValue("D{$ligne}", $produit->seuil_alerte);
            $feuille->setCellValue("E{$ligne}", $produit->coutMoyenPondere());
            $feuille->setCellValue("F{$ligne}", $produit->prix_piece);
            $ligne++;
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $colonne) {
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }

        return $this->telechargerTableur($spreadsheet, 'etat-du-stock.xlsx');
    }

    /**
     * Magasins/dépôts à détailler dans la colonne "Stock" : tous les actifs,
     * ou seulement celui filtré (?magasin_id=) — jamais recalculé ailleurs
     * (écran, PDF, Excel), voir Produit::stockParMagasin().
     */
    private function magasinsAffiches(Request $request, Collection $magasinsActifs): Collection
    {
        return $request->filled('magasin_id')
            ? $magasinsActifs->where('id', $request->integer('magasin_id'))->values()
            : $magasinsActifs;
    }

    /**
     * Requête Stock brute (jointe produits/magasins), utilisée uniquement
     * pour les KPI agrégés (pièces en stock, valeur CMP) — inchangée par la
     * refonte "une ligne par produit" de la liste elle-même.
     */
    private function requeteStocksFiltree(Request $request): Builder
    {
        return Stock::query()
            ->join('produits', 'produits.id', '=', 'stocks.produit_id')
            ->join('magasins', 'magasins.id', '=', 'stocks.magasin_id')
            ->when($request->filled('magasin_id'), fn ($q) => $q->where('stocks.magasin_id', $request->integer('magasin_id')))
            ->when($request->filled('produit_id'), fn ($q) => $q->where('stocks.produit_id', $request->integer('produit_id')));
    }

    /**
     * Requête Produit (une ligne par produit, tous magasins confondus dans
     * la colonne "Stock" — voir Produit::stockParMagasin()) : inclut
     * volontairement les produits sans aucune ligne Stock (quantité 0
     * partout), pour un état de stock complet plutôt qu'une simple liste des
     * mouvements déjà enregistrés.
     *
     * $sousSeuilUniquement filtre aux produits dont AU MOINS un magasin/dépôt
     * (le filtré s'il y en a un, sinon tous) est sous le seuil d'alerte —
     * l'absence de toute ligne Stock à cette destination compte aussi comme
     * "sous seuil" (quantité 0).
     *
     * Filtré par magasin_id (hors case "sous seuil", voir ci-dessous) : ne
     * retourne que les produits réellement disponibles (quantité > 0) à CE
     * magasin/dépôt précis — contrairement à la vue "tous les magasins" qui
     * liste tout le catalogue actif, y compris les produits à 0 partout
     * (état de stock complet).
     */
    private function requeteProduitsFiltree(Request $request, bool $sousSeuilUniquement = false): Builder
    {
        return Produit::query()
            ->when($request->filled('produit_id'), fn ($q) => $q->where('id', $request->integer('produit_id')))
            // Préfixe (nom commençant par ce qui est tapé), pas une recherche
            // "contient" — filtre à la frappe (voir x-recherche-form).
            ->when($request->filled('recherche'), fn ($q) => $q->where('nom', 'like', $request->string('recherche').'%'))
            // Le cas "sous seuil" gère lui-même la disponibilité par magasin
            // ci-dessous (une rupture, quantité 0, doit rester visible comme
            // "sous seuil" — ne pas la masquer ici via la disponibilité).
            ->when($request->filled('magasin_id') && ! $sousSeuilUniquement, fn ($q) => $q->whereHas(
                'stocks',
                fn ($sq) => $sq->where('magasin_id', $request->integer('magasin_id'))->where('quantite', '>', 0),
            ))
            ->when($sousSeuilUniquement, function ($q) use ($request) {
                $q->where(function ($qq) use ($request) {
                    $qq->whereHas('stocks', function ($sq) use ($request) {
                        $sq->whereColumn('stocks.quantite', '<=', 'produits.seuil_alerte')
                            ->when($request->filled('magasin_id'), fn ($s) => $s->where('magasin_id', $request->integer('magasin_id')));
                    })->orWhereDoesntHave('stocks', function ($sq) use ($request) {
                        $sq->when($request->filled('magasin_id'), fn ($s) => $s->where('magasin_id', $request->integer('magasin_id')));
                    });
                });
            });
    }

    /**
     * Même filtre que l'écran (magasin, produit, sous seuil), sans
     * pagination et toujours par ordre alphabétique — utilisé par les deux
     * exports (règle CLAUDE.md : les rapports ne dupliquent pas la logique
     * métier, ils réutilisent la même requête filtrée que l'écran).
     */
    private function produitsExport(Request $request): Collection
    {
        return $this->requeteProduitsFiltree($request, sousSeuilUniquement: $request->boolean('sous_seuil'))
            ->with([
                'stocks' => fn ($q) => $q->when(
                    $request->filled('magasin_id'),
                    fn ($qq) => $qq->where('magasin_id', $request->integer('magasin_id')),
                ),
                'stocks.magasin',
            ])
            ->orderBy('nom')
            ->get();
    }

    private function libellesFiltres(Request $request): array
    {
        return [
            'magasin' => $request->filled('magasin_id') ? Magasin::find($request->integer('magasin_id'))?->nom : null,
            'produit' => $request->filled('produit_id') ? Produit::find($request->integer('produit_id'))?->libelle_affichage : null,
            'recherche' => $request->string('recherche')->toString() ?: null,
            'sousSeuil' => $request->boolean('sous_seuil'),
        ];
    }
}
