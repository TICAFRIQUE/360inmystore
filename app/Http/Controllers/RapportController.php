<?php

namespace App\Http\Controllers;

use App\Enums\MouvementStockType;
use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\JournalCaisse;
use App\Models\Caisse;
use App\Models\CompteTresorerie;
use App\Models\EcritureCompteTresorerie;
use App\Models\Inventaire;
use App\Models\Magasin;
use App\Models\MouvementStock;
use App\Models\Paiement;
use App\Models\ReglementPaiement;
use App\Models\SessionCaisse;
use App\Models\Stock;
use App\Models\User;
use App\Models\Vente;
use App\Support\Arrondi;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RapportController extends Controller
{
    use ExporteListe, JournalCaisse;

    public function index(): View
    {
        return view('rapports.index');
    }

    public function ventes(Request $request): View
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        $requeteVentes = $this->requeteVentesFiltree($request, $debut, $fin, $magasinId);

        // L'impression doit couvrir tout le résultat filtré, pas seulement la
        // page affichée à l'écran — reste borné par la période, jamais un
        // scan de table complet (voir CLAUDE.md, pagination).
        $ventes = $request->boolean('tout')
            ? $requeteVentes->get()
            : $requeteVentes->paginate(25)->withQueryString();

        $requeteTotaux = Vente::query()
            ->when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))
            ->when($request->filled('caissier_id'), fn ($q) => $q->where('caissier_id', $request->integer('caissier_id')))
            ->when($request->filled('caisse_id'), fn ($q) => $q->whereHas('sessionCaisse', fn ($sc) => $sc->where('caisse_id', $request->integer('caisse_id'))))
            ->whereBetween('created_at', [$debut, $fin]);

        // Reste dû réel : nécessite paiements/reglementsClient par vente
        // (voir Vente::soldeDuReel()), donc une collection plutôt qu'un
        // simple sum() SQL — même périmètre exact que $requeteTotaux.
        $totalDu = (int) (clone $requeteTotaux)->with('paiements', 'reglementsClient', 'retours')->get()
            ->sum(fn (Vente $v) => $v->soldeDuReel());

        return view('rapports.ventes', [
            'ventes' => $ventes,
            'totalNet' => (int) (clone $requeteTotaux)->sum('total_net'),
            'totalDu' => $totalDu,
            // Informationnel uniquement : l'avoir appliqué n'entre jamais dans
            // le comptage du tiroir (règle 10), contrairement aux espèces —
            // ce KPI répond juste au besoin de voir combien d'avoirs ont
            // servi à régler des factures sur la période.
            'totalAvoirApplique' => (int) (clone $requeteTotaux)->sum('avoir_applique'),
            'totalEspeces' => $this->totalEspecesFiltre($request, $debut, $fin, $magasinId),
            'nombre' => (clone $requeteTotaux)->count(),
            'magasins' => Magasin::orderBy('nom')->get(),
            'caissiers' => User::when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))->orderBy('name')->get(),
            'caisses' => Caisse::when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))->orderBy('nom')->get(),
            'debut' => $debut,
            'fin' => $fin,
        ]);
    }

    /**
     * Espèces réellement encaissées sur le périmètre filtré (paiements à la
     * vente + règlements clients, règle 10) — mêmes filtres que
     * requeteVentesFiltree()/$requeteTotaux, jamais confondu avec totalNet
     * (qui inclut crédit et avoir, jamais encaissés).
     */
    private function totalEspecesFiltre(Request $request, Carbon $debut, Carbon $fin, ?int $magasinId): int
    {
        $especesVente = (int) Paiement::query()
            ->join('ventes', 'ventes.id', '=', 'paiements.vente_id')
            ->join('moyen_paiements', 'moyen_paiements.id', '=', 'paiements.moyen_paiement_id')
            ->join('session_caisses', 'session_caisses.id', '=', 'ventes.session_caisse_id')
            ->where('moyen_paiements.est_espece', true)
            ->when($magasinId, fn ($q) => $q->where('ventes.magasin_id', $magasinId))
            ->when($request->filled('caissier_id'), fn ($q) => $q->where('ventes.caissier_id', $request->integer('caissier_id')))
            ->when($request->filled('caisse_id'), fn ($q) => $q->where('session_caisses.caisse_id', $request->integer('caisse_id')))
            ->whereBetween('ventes.created_at', [$debut, $fin])
            ->whereNull('ventes.deleted_at')
            ->sum('paiements.montant');

        $especesReglement = (int) ReglementPaiement::query()
            ->join('reglement_clients', 'reglement_clients.id', '=', 'reglement_paiements.reglement_client_id')
            ->join('session_caisses', 'session_caisses.id', '=', 'reglement_clients.session_caisse_id')
            ->join('caisses', 'caisses.id', '=', 'session_caisses.caisse_id')
            ->join('moyen_paiements', 'moyen_paiements.id', '=', 'reglement_paiements.moyen_paiement_id')
            ->where('moyen_paiements.est_espece', true)
            ->when($magasinId, fn ($q) => $q->where('caisses.magasin_id', $magasinId))
            ->when($request->filled('caissier_id'), fn ($q) => $q->where('reglement_clients.caissier_id', $request->integer('caissier_id')))
            ->when($request->filled('caisse_id'), fn ($q) => $q->where('session_caisses.caisse_id', $request->integer('caisse_id')))
            ->whereBetween('reglement_clients.created_at', [$debut, $fin])
            ->sum('reglement_paiements.montant');

        return $especesVente + $especesReglement;
    }

    public function ventesPdf(Request $request): Response
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        return $this->pdfDepuisListe(
            'Rapport des ventes',
            ['Numéro', 'Date', 'Magasin', 'Caisse', 'Caissier', 'Total net', 'Avoir appliqué', 'Livraison'],
            $this->requeteVentesFiltree($request, $debut, $fin, $magasinId)->get()->map(fn (Vente $v) => [
                $v->numero,
                $v->created_at->format('d/m/Y H:i'),
                $v->magasin->nom,
                $v->sessionCaisse->caisse->nom,
                $v->caissier->name,
                montant($v->total_net),
                $v->avoir_applique > 0 ? montant($v->avoir_applique) : '—',
                $this->statutLivraisonLibelle($v),
            ]),
            'rapport-ventes.pdf',
            'Du '.$debut->format('d/m/Y').' au '.$fin->format('d/m/Y'),
            $this->bilanVentes($request, $debut, $fin, $magasinId),
        );
    }

    public function ventesExcel(Request $request): StreamedResponse
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        return $this->excelDepuisListe(
            'Rapport des ventes',
            ['Numéro', 'Date', 'Magasin', 'Caisse', 'Caissier', 'Total net', 'Avoir appliqué', 'Livraison'],
            $this->requeteVentesFiltree($request, $debut, $fin, $magasinId)->get()->map(fn (Vente $v) => [
                $v->numero,
                $v->created_at->format('d/m/Y H:i'),
                $v->magasin->nom,
                $v->sessionCaisse->caisse->nom,
                $v->caissier->name,
                $v->total_net,
                $v->avoir_applique,
                $this->statutLivraisonLibelle($v),
            ]),
            'rapport-ventes.xlsx',
            $this->bilanVentes($request, $debut, $fin, $magasinId),
        );
    }

    /**
     * "—" si la vente n'a jamais eu de bon de livraison (feature non
     * engagée, voir Vente::livraisonEngagee()) : pas de "non livrée"
     * trompeur sur une vente comptoir classique. Même libellé utilisé par
     * l'écran, le PDF et l'Excel — jamais un calcul recopié à trois endroits.
     */
    private function statutLivraisonLibelle(Vente $vente): string
    {
        if (! $vente->livraisonEngagee()) {
            return '—';
        }

        return $vente->entierementLivree()
            ? 'Entièrement livrée'
            : quantite($vente->quantiteLivreePieces()).'/'.quantite($vente->lignes->sum('quantite_pieces')).' pièce(s)';
    }

    private function requeteVentesFiltree(Request $request, Carbon $debut, Carbon $fin, ?int $magasinId): \Illuminate\Database\Eloquent\Builder
    {
        return Vente::query()
            ->with(['magasin', 'caissier', 'sessionCaisse.caisse', 'lignes', 'bonsLivraison.lignes'])
            ->when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))
            ->when($request->filled('caissier_id'), fn ($q) => $q->where('caissier_id', $request->integer('caissier_id')))
            ->when($request->filled('caisse_id'), fn ($q) => $q->whereHas('sessionCaisse', fn ($sc) => $sc->where('caisse_id', $request->integer('caisse_id'))))
            ->whereBetween('created_at', [$debut, $fin])
            ->orderByDesc('created_at');
    }

    /**
     * Mêmes chiffres que les KPI de rapports/ventes.blade.php (voir ventes()
     * ci-dessus) — jamais un nouveau calcul recopié, pour ne jamais laisser
     * le PDF/Excel afficher un bilan différent de l'écran.
     *
     * @return array<string, string>
     */
    private function bilanVentes(Request $request, Carbon $debut, Carbon $fin, ?int $magasinId): array
    {
        $requeteTotaux = Vente::query()
            ->when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))
            ->when($request->filled('caissier_id'), fn ($q) => $q->where('caissier_id', $request->integer('caissier_id')))
            ->when($request->filled('caisse_id'), fn ($q) => $q->whereHas('sessionCaisse', fn ($sc) => $sc->where('caisse_id', $request->integer('caisse_id'))))
            ->whereBetween('created_at', [$debut, $fin]);

        $totalDu = (int) (clone $requeteTotaux)->with('paiements', 'reglementsClient', 'retours')->get()
            ->sum(fn (Vente $v) => $v->soldeDuReel());

        return [
            'Nombre de ventes' => (string) (clone $requeteTotaux)->count(),
            'Total net' => montant((int) (clone $requeteTotaux)->sum('total_net')),
            'Total dû (crédit)' => montant($totalDu),
            'Avoirs appliqués' => montant((int) (clone $requeteTotaux)->sum('avoir_applique')),
            'Total en caisse (espèces)' => montant($this->totalEspecesFiltre($request, $debut, $fin, $magasinId)),
        ];
    }

    public function marge(Request $request): View
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        $lignes = $this->ligneMargeFiltree($request, $debut, $fin, $magasinId);

        return view('rapports.marge', [
            'lignes' => $lignes,
            'totalVentes' => (int) $lignes->sum('ventes_total'),
            'totalCout' => (int) $lignes->sum('cout_total'),
            'totalMarge' => (int) $lignes->sum('marge'),
            'magasins' => Magasin::orderBy('nom')->get(),
            'debut' => $debut,
            'fin' => $fin,
        ]);
    }

    public function margePdf(Request $request): Response
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        return $this->pdfDepuisListe(
            'Rapport de marge',
            ['Produit', 'SKU', 'Pièces vendues', 'Ventes', 'Coût', 'Marge'],
            $this->ligneMargeFiltree($request, $debut, $fin, $magasinId)->map(fn ($l) => [
                $l->nom, $l->sku, $l->pieces,
                montant($l->ventes_total),
                montant($l->cout_total),
                montant($l->marge),
            ]),
            'rapport-marge.pdf',
            'Du '.$debut->format('d/m/Y').' au '.$fin->format('d/m/Y'),
        );
    }

    public function margeExcel(Request $request): StreamedResponse
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        return $this->excelDepuisListe(
            'Rapport de marge',
            ['Produit', 'SKU', 'Pièces vendues', 'Ventes', 'Coût', 'Marge'],
            $this->ligneMargeFiltree($request, $debut, $fin, $magasinId)->map(fn ($l) => [
                $l->nom, $l->sku, $l->pieces, $l->ventes_total, $l->cout_total, $l->marge,
            ]),
            'rapport-marge.xlsx',
        );
    }

    private function ligneMargeFiltree(Request $request, Carbon $debut, Carbon $fin, ?int $magasinId): \Illuminate\Support\Collection
    {
        return \App\Models\LigneVente::query()
            ->join('ventes', 'ventes.id', '=', 'ligne_ventes.vente_id')
            ->join('produits', 'produits.id', '=', 'ligne_ventes.produit_id')
            ->when($magasinId, fn ($q) => $q->where('ventes.magasin_id', $magasinId))
            ->whereBetween('ventes.created_at', [$debut, $fin])
            ->whereNull('ventes.deleted_at')
            ->selectRaw('produits.nom as nom, produits.sku as sku,
                SUM(ligne_ventes.quantite_pieces) as pieces,
                SUM(ligne_ventes.total_ligne) as ventes_total,
                SUM(ligne_ventes.cout_applique * ligne_ventes.quantite_pieces) as cout_total')
            ->groupBy('produits.id', 'produits.nom', 'produits.sku')
            ->orderByDesc('ventes_total')
            ->get()
            ->map(function ($ligne) {
                $ligne->marge = $ligne->ventes_total - $ligne->cout_total;

                return $ligne;
            });
    }

    public function stock(Request $request): View
    {
        $parMagasin = $this->parMagasinFiltre($request);

        return view('rapports.stock', [
            'parMagasin' => $parMagasin,
            'valeurGlobale' => Arrondi::entier((float) $parMagasin->sum('valeur_totale')),
        ]);
    }

    public function stockPdf(Request $request): Response
    {
        return $this->pdfDepuisListe(
            'Valeur du stock',
            ['Destination', 'Quantité (pièces)', 'Valeur (CMP)'],
            $this->parMagasinFiltre($request)->map(fn ($l) => [
                $l->magasin_nom, quantite($l->quantite_totale), montant($l->valeur_totale),
            ]),
            'rapport-stock.pdf',
        );
    }

    public function stockExcel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe(
            'Valeur du stock',
            ['Destination', 'Quantité (pièces)', 'Valeur (CMP)'],
            $this->parMagasinFiltre($request)->map(fn ($l) => [
                $l->magasin_nom, (float) $l->quantite_totale, (float) $l->valeur_totale,
            ]),
            'rapport-stock.xlsx',
        );
    }

    private function parMagasinFiltre(Request $request): \Illuminate\Support\Collection
    {
        $magasinId = $this->resoudreMagasinId($request);

        return Stock::query()
            ->join('magasins', 'magasins.id', '=', 'stocks.magasin_id')
            ->when($magasinId, fn ($q) => $q->where('stocks.magasin_id', $magasinId))
            ->selectRaw('magasins.id as magasin_id, magasins.nom as magasin_nom,
                SUM(stocks.quantite) as quantite_totale,
                SUM(stocks.quantite * stocks.cout_moyen_pondere) as valeur_totale')
            ->groupBy('magasins.id', 'magasins.nom')
            ->orderBy('magasins.nom')
            ->get();
    }

    public function ecartsCaisse(Request $request): View
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        $requeteSessions = $this->requeteSessionsFiltree($request, $debut, $fin, $magasinId);

        $sessions = $request->boolean('tout')
            ? $requeteSessions->get()
            : $requeteSessions->paginate(25)->withQueryString();

        return view('rapports.ecarts-caisse', [
            'sessions' => $sessions,
            'magasins' => Magasin::orderBy('nom')->get(),
            'debut' => $debut,
            'fin' => $fin,
        ]);
    }

    public function ecartsCaissePdf(Request $request): Response
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        return $this->pdfDepuisListe(
            'Écarts de caisse',
            ['Caisse', 'Magasin', 'Caissier', 'Clôturée le', 'Écart'],
            $this->requeteSessionsFiltree($request, $debut, $fin, $magasinId)->get()->map(fn (SessionCaisse $s) => [
                $s->caisse->nom,
                $s->caisse->magasin->nom,
                $s->caissier->name,
                $s->date_cloture->format('d/m/Y H:i'),
                montant($s->ecart ?? 0),
            ]),
            'rapport-ecarts-caisse.pdf',
            'Du '.$debut->format('d/m/Y').' au '.$fin->format('d/m/Y'),
        );
    }

    public function ecartsCaisseExcel(Request $request): StreamedResponse
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        return $this->excelDepuisListe(
            'Écarts de caisse',
            ['Caisse', 'Magasin', 'Caissier', 'Clôturée le', 'Écart'],
            $this->requeteSessionsFiltree($request, $debut, $fin, $magasinId)->get()->map(fn (SessionCaisse $s) => [
                $s->caisse->nom,
                $s->caisse->magasin->nom,
                $s->caissier->name,
                $s->date_cloture->format('d/m/Y H:i'),
                $s->ecart ?? 0,
            ]),
            'rapport-ecarts-caisse.xlsx',
        );
    }

    private function requeteSessionsFiltree(Request $request, Carbon $debut, Carbon $fin, ?int $magasinId): \Illuminate\Database\Eloquent\Builder
    {
        return SessionCaisse::query()
            ->with(['caisse.magasin', 'caissier'])
            ->whereNotNull('date_cloture')
            ->when($magasinId, fn ($q) => $q->whereHas('caisse', fn ($c) => $c->where('magasin_id', $magasinId)))
            ->whereBetween('date_cloture', [$debut, $fin])
            ->orderByDesc('date_cloture');
    }

    public function mouvementsCaisse(Request $request): View
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);
        $caisseId = $request->filled('caisse_id') ? $request->integer('caisse_id') : null;
        $caissierId = $request->filled('caissier_id') ? $request->integer('caissier_id') : null;
        $type = $request->filled('type') ? $request->string('type')->toString() : null;

        $requeteMouvements = $this->requeteJournalCaisse($debut, $fin, magasinId: $magasinId, caisseId: $caisseId, caissierId: $caissierId, type: $type);

        $mouvements = $request->boolean('tout')
            ? $requeteMouvements->get()
            : $requeteMouvements->paginate(25)->withQueryString();

        $lignes = $mouvements instanceof LengthAwarePaginator ? $mouvements->getCollection() : $mouvements;
        $lignes->transform(fn ($l) => $this->decorerLigneJournal($l));

        // KPI sur le même périmètre filtré (date/magasin/caisse/caissier),
        // mais toujours tous types confondus — la répartition entrées/
        // sorties/ventes ne doit pas dépendre du filtre "type" qui ne
        // pilote que la table affichée.
        $toutesLesLignes = $this->requeteJournalCaisse($debut, $fin, magasinId: $magasinId, caisseId: $caisseId, caissierId: $caissierId)->get();
        $totalEntrees = (int) $toutesLesLignes->where('type', 'entree')->sum('montant');
        $totalSorties = (int) $toutesLesLignes->where('type', 'sortie')->sum('montant');
        $totalVentes = (int) $toutesLesLignes->where('type', 'vente')->sum('montant');
        $totalRetours = (int) $toutesLesLignes->where('type', 'retour')->sum('montant');

        return view('rapports.mouvements-caisse', [
            'mouvements' => $mouvements,
            'magasins' => Magasin::orderBy('nom')->get(),
            'caisses' => Caisse::when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))->orderBy('nom')->get(),
            'caissiers' => User::when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))->orderBy('name')->get(),
            'debut' => $debut,
            'fin' => $fin,
            'nombre' => $request->boolean('tout') ? $mouvements->count() : $mouvements->total(),
            'totalEntrees' => $totalEntrees,
            'totalSorties' => $totalSorties,
            'totalVentes' => $totalVentes,
            'totalRetours' => $totalRetours,
        ]);
    }

    public function mouvementsCaissePdf(Request $request): Response
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        return $this->pdfDepuisListe(
            'Mouvements de caisse',
            ['Date', 'Caisse', 'Magasin', 'Type', 'Motif', 'Montant', 'Auteur'],
            $this->requeteJournalCaissePourExport($request, $debut, $fin, $magasinId)->map(fn (object $l) => [
                Carbon::parse($l->created_at)->format('d/m/Y H:i'),
                $l->caisse_nom,
                $l->magasin_nom,
                $this->decorerLigneJournal($l)->type_libelle,
                $l->motif,
                ($l->type === 'retour' ? '' : ($l->type === 'sortie' ? '− ' : '+ ')).montant($l->montant),
                $l->auteur_nom ?? 'Utilisateur supprimé',
            ]),
            'rapport-mouvements-caisse.pdf',
            'Du '.$debut->format('d/m/Y').' au '.$fin->format('d/m/Y'),
            $this->bilanMouvementsCaisse($request, $debut, $fin, $magasinId),
        );
    }

    public function mouvementsCaisseExcel(Request $request): StreamedResponse
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        return $this->excelDepuisListe(
            'Mouvements de caisse',
            ['Date', 'Caisse', 'Magasin', 'Type', 'Motif', 'Montant', 'Auteur'],
            $this->requeteJournalCaissePourExport($request, $debut, $fin, $magasinId)->map(fn (object $l) => [
                Carbon::parse($l->created_at)->format('d/m/Y H:i'),
                $l->caisse_nom,
                $l->magasin_nom,
                $this->decorerLigneJournal($l)->type_libelle,
                $l->motif,
                $l->type === 'sortie' ? -$l->montant : $l->montant,
                $l->auteur_nom ?? 'Utilisateur supprimé',
            ]),
            'rapport-mouvements-caisse.xlsx',
            $this->bilanMouvementsCaisse($request, $debut, $fin, $magasinId),
        );
    }

    private function requeteJournalCaissePourExport(Request $request, Carbon $debut, Carbon $fin, ?int $magasinId): Collection
    {
        return $this->requeteJournalCaisse(
            $debut, $fin,
            magasinId: $magasinId,
            caisseId: $request->filled('caisse_id') ? $request->integer('caisse_id') : null,
            caissierId: $request->filled('caissier_id') ? $request->integer('caissier_id') : null,
            type: $request->filled('type') ? $request->string('type')->toString() : null,
        )->get();
    }

    /**
     * Mêmes chiffres que les KPI de rapports/mouvements-caisse.blade.php (voir
     * mouvementsCaisse() ci-dessus, toujours tous types confondus) — jamais
     * un nouveau calcul recopié.
     *
     * @return array<string, string>
     */
    private function bilanMouvementsCaisse(Request $request, Carbon $debut, Carbon $fin, ?int $magasinId): array
    {
        $toutesLesLignes = $this->requeteJournalCaisse(
            $debut, $fin,
            magasinId: $magasinId,
            caisseId: $request->filled('caisse_id') ? $request->integer('caisse_id') : null,
            caissierId: $request->filled('caissier_id') ? $request->integer('caissier_id') : null,
        )->get();

        return [
            'Ventes en espèces' => montant((int) $toutesLesLignes->where('type', 'vente')->sum('montant')),
            'Total entrées' => montant((int) $toutesLesLignes->where('type', 'entree')->sum('montant')),
            'Total sorties' => montant((int) $toutesLesLignes->where('type', 'sortie')->sum('montant')),
            // Jamais additionné aux lignes ci-dessus (règle 18, hors tiroir) —
            // juste informatif, comme la carte KPI "Retours" à l'écran.
            'Retours (avoir, hors tiroir)' => montant((int) $toutesLesLignes->where('type', 'retour')->sum('montant')),
        ];
    }

    /**
     * Mouvements de la trésorerie de l'entreprise (Caisse Générale + comptes
     * bancaires/autres — voir CLAUDE.md, Trésorerie) : univers volontairement
     * séparé de mouvementsCaisse() ci-dessus, qui reste scopé aux tiroirs des
     * caissiers.
     */
    public function tresorerie(Request $request): View
    {
        [$debut, $fin] = $this->periode($request);

        $requeteEcritures = $this->requeteTresorerieFiltree($request, $debut, $fin);

        $ecritures = $request->boolean('tout')
            ? $requeteEcritures->get()
            : $requeteEcritures->paginate(25)->withQueryString();

        // KPI sur le même périmètre filtré, mais toujours tous types
        // confondus (voir mouvementsCaisse(), même raisonnement).
        $requeteTotaux = $this->requeteTresorerieFiltree($request, $debut, $fin, avecType: false);
        $totalEntrees = (int) (clone $requeteTotaux)->where('montant', '>', 0)->sum('montant');
        $totalSorties = (int) (clone $requeteTotaux)->where('montant', '<', 0)->sum('montant');

        return view('rapports.tresorerie', [
            'ecritures' => $ecritures,
            'comptes' => CompteTresorerie::orderByRaw("type != 'caisse_generale'")->orderBy('nom')->get(),
            'debut' => $debut,
            'fin' => $fin,
            'nombre' => $request->boolean('tout') ? $ecritures->count() : $ecritures->total(),
            'totalEntrees' => $totalEntrees,
            'totalSorties' => abs($totalSorties),
            'soldeNet' => $totalEntrees + $totalSorties,
        ]);
    }

    public function tresoreriePdf(Request $request): Response
    {
        [$debut, $fin] = $this->periode($request);

        return $this->pdfDepuisListe(
            'Mouvements de trésorerie',
            ['Date', 'Compte', 'Type', 'Motif', 'Montant', 'Auteur'],
            $this->requeteTresorerieFiltree($request, $debut, $fin)->get()->map(fn (EcritureCompteTresorerie $e) => [
                $e->created_at->format('d/m/Y H:i'),
                $e->compteTresorerie->nom,
                $e->type->libelle(),
                $e->motif ?? '—',
                ($e->montant >= 0 ? '+ ' : '− ').montant(abs($e->montant)),
                $e->auteur?->name ?? 'Utilisateur supprimé',
            ]),
            'rapport-tresorerie.pdf',
            'Du '.$debut->format('d/m/Y').' au '.$fin->format('d/m/Y'),
            $this->bilanTresorerie($request, $debut, $fin),
        );
    }

    public function tresorerieExcel(Request $request): StreamedResponse
    {
        [$debut, $fin] = $this->periode($request);

        return $this->excelDepuisListe(
            'Mouvements de trésorerie',
            ['Date', 'Compte', 'Type', 'Motif', 'Montant', 'Auteur'],
            $this->requeteTresorerieFiltree($request, $debut, $fin)->get()->map(fn (EcritureCompteTresorerie $e) => [
                $e->created_at->format('d/m/Y H:i'),
                $e->compteTresorerie->nom,
                $e->type->libelle(),
                $e->motif ?? '—',
                $e->montant,
                $e->auteur?->name ?? 'Utilisateur supprimé',
            ]),
            'rapport-tresorerie.xlsx',
            $this->bilanTresorerie($request, $debut, $fin),
        );
    }

    /**
     * Mêmes chiffres que les KPI de rapports/tresorerie.blade.php (voir
     * tresorerie() ci-dessus, toujours tous types confondus).
     *
     * @return array<string, string>
     */
    private function bilanTresorerie(Request $request, Carbon $debut, Carbon $fin): array
    {
        $requeteTotaux = $this->requeteTresorerieFiltree($request, $debut, $fin, avecType: false);
        $totalEntrees = (int) (clone $requeteTotaux)->where('montant', '>', 0)->sum('montant');
        $totalSorties = (int) (clone $requeteTotaux)->where('montant', '<', 0)->sum('montant');

        return [
            'Total entrées' => montant($totalEntrees),
            'Total sorties' => montant(abs($totalSorties)),
            'Solde net' => montant($totalEntrees + $totalSorties),
        ];
    }

    private function requeteTresorerieFiltree(Request $request, Carbon $debut, Carbon $fin, bool $avecType = true): \Illuminate\Database\Eloquent\Builder
    {
        return EcritureCompteTresorerie::query()
            ->with(['compteTresorerie', 'auteur'])
            ->when($request->filled('compte_tresorerie_id'), fn ($q) => $q->where('compte_tresorerie_id', $request->integer('compte_tresorerie_id')))
            ->when($avecType && $request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->whereBetween('created_at', [$debut, $fin])
            ->orderByDesc('created_at');
    }

    public function inventaires(Request $request): View
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        $requeteInventaires = $this->requeteInventairesFiltree($request, $debut, $fin, $magasinId);

        $inventaires = $request->boolean('tout')
            ? $requeteInventaires->get()
            : $requeteInventaires->paginate(25)->withQueryString();

        return view('rapports.inventaires', [
            'inventaires' => $inventaires,
            'magasins' => Magasin::orderBy('nom')->get(),
            'debut' => $debut,
            'fin' => $fin,
        ]);
    }

    public function inventairesPdf(Request $request): Response
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        return $this->pdfDepuisListe(
            'Rapport des inventaires',
            ['Date', 'Destination', 'Statut', 'Lignes', 'Écart total'],
            $this->requeteInventairesFiltree($request, $debut, $fin, $magasinId)->get()->map(fn (Inventaire $i) => [
                $i->date->format('d/m/Y'),
                $i->magasin->nom,
                $i->statut === 'valide' ? 'Validé' : 'Brouillon',
                $i->lignes_count,
                $i->ecart_total ?? 0,
            ]),
            'rapport-inventaires.pdf',
            'Du '.$debut->format('d/m/Y').' au '.$fin->format('d/m/Y'),
        );
    }

    public function inventairesExcel(Request $request): StreamedResponse
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        return $this->excelDepuisListe(
            'Rapport des inventaires',
            ['Date', 'Destination', 'Statut', 'Lignes', 'Écart total'],
            $this->requeteInventairesFiltree($request, $debut, $fin, $magasinId)->get()->map(fn (Inventaire $i) => [
                $i->date->format('d/m/Y'),
                $i->magasin->nom,
                $i->statut === 'valide' ? 'Validé' : 'Brouillon',
                $i->lignes_count,
                $i->ecart_total ?? 0,
            ]),
            'rapport-inventaires.xlsx',
        );
    }

    private function requeteInventairesFiltree(Request $request, Carbon $debut, Carbon $fin, ?int $magasinId): \Illuminate\Database\Eloquent\Builder
    {
        return Inventaire::query()
            ->with(['magasin', 'auteur', 'validateur'])
            ->withCount('lignes')
            ->withSum('lignes as ecart_total', 'ecart')
            ->when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))
            ->whereBetween('date', [$debut->toDateString(), $fin->toDateString()])
            ->orderByDesc('date');
    }

    public function casse(Request $request): View
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        $lignes = $this->ligneCasseFiltree($request, $debut, $fin, $magasinId);

        return view('rapports.casse', [
            'lignes' => $lignes,
            'totalPieces' => (int) $lignes->sum('pieces_perdues'),
            'magasins' => Magasin::orderBy('nom')->get(),
            'debut' => $debut,
            'fin' => $fin,
        ]);
    }

    public function cassePdf(Request $request): Response
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        return $this->pdfDepuisListe(
            'Casse / pertes',
            ['Produit', 'SKU', 'Magasin', 'Pièces perdues'],
            $this->ligneCasseFiltree($request, $debut, $fin, $magasinId)->map(fn ($l) => [
                $l->nom, $l->sku, $l->magasin_nom, $l->pieces_perdues,
            ]),
            'rapport-casse.pdf',
            'Du '.$debut->format('d/m/Y').' au '.$fin->format('d/m/Y'),
        );
    }

    public function casseExcel(Request $request): StreamedResponse
    {
        [$debut, $fin] = $this->periode($request);
        $magasinId = $this->resoudreMagasinId($request);

        return $this->excelDepuisListe(
            'Casse / pertes',
            ['Produit', 'SKU', 'Magasin', 'Pièces perdues'],
            $this->ligneCasseFiltree($request, $debut, $fin, $magasinId)->map(fn ($l) => [
                $l->nom, $l->sku, $l->magasin_nom, $l->pieces_perdues,
            ]),
            'rapport-casse.xlsx',
        );
    }

    private function ligneCasseFiltree(Request $request, Carbon $debut, Carbon $fin, ?int $magasinId): \Illuminate\Support\Collection
    {
        return MouvementStock::query()
            ->join('produits', 'produits.id', '=', 'mouvement_stocks.produit_id')
            ->join('magasins', 'magasins.id', '=', 'mouvement_stocks.magasin_id')
            ->where('mouvement_stocks.type', MouvementStockType::Casse->value)
            ->when($magasinId, fn ($q) => $q->where('mouvement_stocks.magasin_id', $magasinId))
            ->whereBetween('mouvement_stocks.created_at', [$debut, $fin])
            ->selectRaw('produits.nom as nom, produits.sku as sku, magasins.nom as magasin_nom,
                SUM(-mouvement_stocks.quantite) as pieces_perdues')
            ->groupBy('produits.id', 'produits.nom', 'produits.sku', 'magasins.id', 'magasins.nom')
            ->orderByDesc('pieces_perdues')
            ->get();
    }

    /**
     * Un utilisateur rattaché à un magasin (gérant, caissier) ne doit voir
     * que les données de son propre magasin dans les rapports, même en
     * manipulant le filtre — contrairement au tableau de bord (déjà scopé),
     * les rapports laissaient jusqu'ici n'importe quel magasin_id passer.
     * Un superadmin (sans magasin_id) garde le filtre libre, "Tous les
     * magasins" compris.
     */
    private function resoudreMagasinId(Request $request): ?int
    {
        return $request->user()->magasin_id ?: ($request->integer('magasin_id') ?: null);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periode(Request $request): array
    {
        $debut = $request->filled('debut')
            ? Carbon::parse($request->string('debut'))->startOfDay()
            : Carbon::now()->startOfMonth();

        $fin = $request->filled('fin')
            ? Carbon::parse($request->string('fin'))->endOfDay()
            : Carbon::now()->endOfDay();

        return [$debut, $fin];
    }
}
