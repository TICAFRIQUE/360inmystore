<?php

namespace App\Http\Controllers;

use App\Models\Caisse;
use App\Models\EcritureCompteClient;
use App\Models\EcritureCompteFournisseur;
use App\Models\Magasin;
use App\Models\MouvementCaisse;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use App\Models\ReglementPaiement;
use App\Models\SessionCaisse;
use App\Models\Stock;
use App\Models\User;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $utilisateur = $request->user();

        // Dashboard adapté au rôle Spatie, pas à l'état de session (CLAUDE.md
        // « Interface ») : un gérant/superadmin garde toujours son dashboard
        // habituel (CA, stock, créances...) même s'il ouvre lui-même une
        // session de caisse — le suivi de cette session reste accessible via
        // le menu Vente → Facture/BL (sessionCaissierOuverteMenu), pas besoin
        // de remplacer tout le dashboard.
        if ($utilisateur->hasRole('Caissier')) {
            return view('dashboard.caissier', ['utilisateur' => $utilisateur] + $this->donneesCaissier($utilisateur));
        }

        if ($utilisateur->magasin_id) {
            return view('dashboard.gerant', ['utilisateur' => $utilisateur] + $this->donneesMagasin($utilisateur->magasin_id));
        }

        return view('dashboard.superadmin', ['utilisateur' => $utilisateur]
            + $this->donneesMagasin(null)
            + ['comparatifMagasins' => $this->comparatifMagasins()]);
    }

    private function donneesCaissier(User $utilisateur): array
    {
        $session = SessionCaisse::where('caissier_id', $utilisateur->id)
            ->whereNull('date_fermeture')
            ->with('caisse.magasin')
            ->first();

        if (! $session) {
            return ['session' => null];
        }

        $ventesSession = $session->ventes()->with('paiements', 'reglementsClient', 'retours')->get();
        $nombreVentes = $ventesSession->count();
        $totalVentes = (int) $ventesSession->sum('total_net');

        // Deux sources alimentent le tiroir de cette session (règle 10/14) :
        // les paiements encaissés à la vente ET les règlements clients
        // encaissés séparément dans la même session — un règlement ignoré
        // ici sous-comptait la répartition par moyen affichée au caissier.
        $parPaiement = Paiement::query()
            ->join('ventes', 'ventes.id', '=', 'paiements.vente_id')
            ->where('ventes.session_caisse_id', $session->id)
            ->whereNull('ventes.deleted_at')
            ->selectRaw('paiements.moyen_paiement_id, SUM(paiements.montant) as total')
            ->groupBy('paiements.moyen_paiement_id')
            ->pluck('total', 'moyen_paiement_id');

        $parReglement = ReglementPaiement::query()
            ->join('reglement_clients', 'reglement_clients.id', '=', 'reglement_paiements.reglement_client_id')
            ->where('reglement_clients.session_caisse_id', $session->id)
            ->selectRaw('reglement_paiements.moyen_paiement_id, SUM(reglement_paiements.montant) as total')
            ->groupBy('reglement_paiements.moyen_paiement_id')
            ->pluck('total', 'moyen_paiement_id');

        $totauxParMoyen = collect();
        foreach ([$parPaiement, $parReglement] as $source) {
            foreach ($source as $moyenId => $total) {
                $totauxParMoyen[$moyenId] = ($totauxParMoyen[$moyenId] ?? 0) + $total;
            }
        }
        $moyens = MoyenPaiement::whereIn('id', $totauxParMoyen->keys())->get()->keyBy('id');
        $parMoyen = $totauxParMoyen->map(fn ($total, $moyenId) => (object) [
            'nom' => $moyens[$moyenId]->nom,
            'total' => $total,
        ])->values();

        // Espèces uniquement (règle 10) : seul chiffre qui alimente vraiment
        // le tiroir, jamais confondu avec le chiffre d'affaires ci-dessous
        // (qui inclut crédit et avoir, jamais encaissés).
        $totalEspeces = (int) $moyens->filter(fn ($m) => $m->est_espece)
            ->sum(fn ($m) => $totauxParMoyen[$m->id] ?? 0);

        return [
            'session' => $session,
            'nombreVentes' => $nombreVentes,
            'totalVentes' => $totalVentes,
            'totalDu' => (int) $ventesSession->sum(fn (Vente $v) => $v->soldeDuReel()),
            'avoirApplique' => (int) $ventesSession->sum('avoir_applique'),
            'totalEspeces' => $totalEspeces,
            'panierMoyen' => $nombreVentes > 0 ? (int) round($totalVentes / $nombreVentes) : 0,
            'parMoyen' => $parMoyen,
            // Le tableau de bord du caissier ne montre que ses propres
            // ventes en attente, jamais celles d'un autre caissier sur la
            // même caisse.
            'venteEnAttenteCount' => $session->venteEnAttentes()->where('caissier_id', $utilisateur->id)->count(),
        ];
    }

    private function donneesMagasin(?int $magasinId): array
    {
        $debutJour = Carbon::today();
        $debutSemaine = Carbon::now()->startOfWeek();
        $debutMois = Carbon::now()->startOfMonth();

        $caVentes = fn (Carbon $depuis) => (int) Vente::query()
            ->when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))
            ->where('created_at', '>=', $depuis)
            ->sum('total_net');

        $ventesMois = Vente::query()
            ->when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))
            ->where('created_at', '>=', $debutMois);

        $nombreVentesMois = (clone $ventesMois)->count();

        return [
            'caJour' => $caVentes($debutJour),
            'caSemaine' => $caVentes($debutSemaine),
            'caMois' => $caVentes($debutMois),
            'panierMoyenMois' => $nombreVentesMois > 0 ? (int) round((clone $ventesMois)->sum('total_net') / $nombreVentesMois) : 0,
            'topProduits' => $this->topProduits($magasinId, $debutMois),
            'produitsSousSeuil' => $this->produitsSousSeuil($magasinId),
            'produitsSousSeuilCount' => $this->produitsSousSeuilCount($magasinId),
            'valeurStock' => $this->valeurStock($magasinId),
            // Le fournisseur n'est pas rattaché à un magasin (référentiel
            // central, comme le catalogue) : la dette totale est la même
            // pour un gérant que pour le superadmin, jamais filtrée.
            'detteFournisseurs' => $this->detteFournisseurs(),
            // Même logique côté client (référentiel central, pas rattaché à
            // un magasin) : la créance totale n'est jamais filtrée non plus.
            // Solde par client, jamais net entre clients — l'avoir de l'un ne
            // doit jamais compenser la dette d'un autre (règle 12).
            'creancesClients' => $this->creancesClients(),
            'avoirAppliqueMois' => (int) (clone $ventesMois)->sum('avoir_applique'),
            'totalEspecesMois' => $this->totalEspecesMois($magasinId, $debutMois),
            'ecartsCaisse' => $this->ecartsCaisseRecents($magasinId),
            'caissesOuvertes' => $caissesOuvertes = $this->caissesOuvertes($magasinId),
            // Rappel non bloquant (voir x-alerte-sessions-anciennes) : contrairement
            // au dashboard caissier (qui ne montre QUE sa propre session), le
            // gérant/superadmin doit voir TOUTE session encore ouverte depuis un
            // jour précédent dans son périmètre — il a l'autorité pour la
            // clôturer même s'il ne l'a pas ouverte lui-même (règle caisse.gerer).
            'sessionsAnciennes' => $caissesOuvertes
                ->map(fn (Caisse $c) => $c->sessionCaisses->first())
                ->filter(fn (?SessionCaisse $s) => $s?->estOuverteDepuisJourPrecedent())
                ->values(),
            'evolutionVentes' => $this->evolutionVentes($magasinId),
            'repartitionMoyens' => $this->repartitionMoyensPaiement($magasinId, $debutMois),
            'mouvementsCaisseJour' => $this->mouvementsCaisseParMotif($magasinId, $debutJour),
        ];
    }

    /**
     * Total dû par les clients (créances), tous magasins confondus — somme
     * des soldes POSITIFS uniquement : un avoir sur le compte d'un client ne
     * doit jamais compenser la dette d'un autre (règle 12, solde dérivé par
     * client, jamais entre clients).
     */
    private function creancesClients(): int
    {
        return (int) EcritureCompteClient::query()
            ->select('client_id', DB::raw('SUM(montant) as solde'))
            ->groupBy('client_id')
            ->havingRaw('SUM(montant) > 0')
            ->get()
            ->sum('solde');
    }

    /**
     * Espèces réellement encaissées ce mois (paiements à la vente + règlements
     * clients) — la seule chose qui alimente vraiment un tiroir (règle 10),
     * contrairement au CA (total_net) qui inclut aussi le crédit et l'avoir.
     */
    private function totalEspecesMois(?int $magasinId, Carbon $depuis): int
    {
        $ventes = (int) Paiement::query()
            ->join('ventes', 'ventes.id', '=', 'paiements.vente_id')
            ->join('moyen_paiements', 'moyen_paiements.id', '=', 'paiements.moyen_paiement_id')
            ->where('moyen_paiements.est_espece', true)
            ->when($magasinId, fn ($q) => $q->where('ventes.magasin_id', $magasinId))
            ->where('ventes.created_at', '>=', $depuis)
            ->whereNull('ventes.deleted_at')
            ->sum('paiements.montant');

        $reglements = (int) ReglementPaiement::query()
            ->join('reglement_clients', 'reglement_clients.id', '=', 'reglement_paiements.reglement_client_id')
            ->join('session_caisses', 'session_caisses.id', '=', 'reglement_clients.session_caisse_id')
            ->join('caisses', 'caisses.id', '=', 'session_caisses.caisse_id')
            ->join('moyen_paiements', 'moyen_paiements.id', '=', 'reglement_paiements.moyen_paiement_id')
            ->where('moyen_paiements.est_espece', true)
            ->when($magasinId, fn ($q) => $q->where('caisses.magasin_id', $magasinId))
            ->where('reglement_clients.created_at', '>=', $depuis)
            ->sum('reglement_paiements.montant');

        return $ventes + $reglements;
    }

    /**
     * Sorties/entrées de caisse manuelles du jour, ventilées par motif — pour
     * repérer vite une sortie inhabituelle (voir CLAUDE.md, Mouvements de
     * caisse). Scopé au magasin du gérant comme les autres KPI de ce tableau
     * de bord.
     */
    private function mouvementsCaisseParMotif(?int $magasinId, Carbon $depuis)
    {
        return MouvementCaisse::query()
            ->join('session_caisses', 'session_caisses.id', '=', 'mouvement_caisses.session_caisse_id')
            ->join('caisses', 'caisses.id', '=', 'session_caisses.caisse_id')
            ->when($magasinId, fn ($q) => $q->where('caisses.magasin_id', $magasinId))
            ->where('mouvement_caisses.created_at', '>=', $depuis)
            ->selectRaw('mouvement_caisses.type as type, mouvement_caisses.motif as motif, SUM(mouvement_caisses.montant) as total')
            ->groupBy('mouvement_caisses.type', 'mouvement_caisses.motif')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($ligne) => (object) [
                // $ligne->type est déjà casté en MouvementCaisseType par le
                // modèle (Eloquent applique les casts même sur une colonne
                // ramenée via selectRaw) : pas de ::from() ici, ça lèverait
                // un TypeError (l'enum n'est pas une string/int).
                'type' => $ligne->type,
                'motif' => $ligne->motif,
                'total' => (int) $ligne->total,
            ]);
    }

    private function topProduits(?int $magasinId, Carbon $depuis, int $limite = 5)
    {
        return DB::table('ligne_ventes')
            ->join('ventes', 'ventes.id', '=', 'ligne_ventes.vente_id')
            ->join('produits', 'produits.id', '=', 'ligne_ventes.produit_id')
            ->when($magasinId, fn ($q) => $q->where('ventes.magasin_id', $magasinId))
            ->where('ventes.created_at', '>=', $depuis)
            ->whereNull('ventes.deleted_at')
            ->selectRaw('produits.nom as nom, produits.libelle_distinctif as libelle_distinctif, produits.sku as sku, SUM(ligne_ventes.quantite_pieces) as pieces_vendues, SUM(ligne_ventes.total_ligne) as total')
            ->groupBy('produits.id', 'produits.nom', 'produits.libelle_distinctif', 'produits.sku')
            ->orderByDesc('total')
            ->limit($limite)
            ->get();
    }

    private function produitsSousSeuil(?int $magasinId, int $limite = 5)
    {
        return Stock::query()
            ->join('produits', 'produits.id', '=', 'stocks.produit_id')
            ->when($magasinId, fn ($q) => $q->where('stocks.magasin_id', $magasinId))
            ->whereColumn('stocks.quantite', '<=', 'produits.seuil_alerte')
            ->select('stocks.*')
            ->with(['produit', 'magasin'])
            ->orderBy('stocks.quantite')
            ->limit($limite)
            ->get();
    }

    private function produitsSousSeuilCount(?int $magasinId): int
    {
        return Stock::query()
            ->join('produits', 'produits.id', '=', 'stocks.produit_id')
            ->when($magasinId, fn ($q) => $q->where('stocks.magasin_id', $magasinId))
            ->whereColumn('stocks.quantite', '<=', 'produits.seuil_alerte')
            ->count();
    }

    private function valeurStock(?int $magasinId): int
    {
        return (int) Stock::query()
            ->when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))
            ->selectRaw('COALESCE(SUM(quantite * cout_moyen_pondere), 0) as valeur')
            ->value('valeur');
    }

    /**
     * Somme des écritures de tous les comptes fournisseurs (solde dérivé,
     * jamais stocké — même principe que le solde d'un compte client).
     */
    private function detteFournisseurs(): int
    {
        return (int) EcritureCompteFournisseur::sum('montant');
    }

    private function ecartsCaisseRecents(?int $magasinId, int $limite = 5)
    {
        return SessionCaisse::query()
            ->join('caisses', 'caisses.id', '=', 'session_caisses.caisse_id')
            ->when($magasinId, fn ($q) => $q->where('caisses.magasin_id', $magasinId))
            ->whereNotNull('session_caisses.date_cloture')
            ->where('session_caisses.ecart', '<>', 0)
            ->select('session_caisses.*')
            ->with(['caisse', 'caissier'])
            ->orderByDesc('session_caisses.date_cloture')
            ->limit($limite)
            ->get();
    }

    private function caissesOuvertes(?int $magasinId)
    {
        return Caisse::query()
            ->when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))
            ->whereHas('sessionCaisses', fn ($q) => $q->whereNull('date_fermeture'))
            ->with(['magasin', 'sessionCaisses' => fn ($q) => $q->whereNull('date_fermeture')->with('caissier')])
            ->get();
    }

    private function evolutionVentes(?int $magasinId, int $jours = 14): array
    {
        $depuis = Carbon::today()->subDays($jours - 1);

        $parJour = Vente::query()
            ->when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))
            ->where('created_at', '>=', $depuis)
            ->selectRaw('DATE(created_at) as jour, SUM(total_net) as total')
            ->groupBy('jour')
            ->pluck('total', 'jour');

        $labels = [];
        $valeurs = [];

        for ($i = 0; $i < $jours; $i++) {
            $date = $depuis->copy()->addDays($i);
            $labels[] = $date->format('d/m');
            $valeurs[] = (int) ($parJour[$date->toDateString()] ?? 0);
        }

        return ['labels' => $labels, 'valeurs' => $valeurs];
    }

    /**
     * Répartition espèces/mobile money/… du mois — deux sources, comme
     * SessionCaisseController::paiementsParMoyen() : les paiements encaissés
     * à la vente ET les règlements clients encaissés séparément (règle 10),
     * sinon la ventilation sous-comptait tout règlement hors vente.
     */
    private function repartitionMoyensPaiement(?int $magasinId, Carbon $depuis)
    {
        $parVente = Paiement::query()
            ->join('ventes', 'ventes.id', '=', 'paiements.vente_id')
            ->when($magasinId, fn ($q) => $q->where('ventes.magasin_id', $magasinId))
            ->where('ventes.created_at', '>=', $depuis)
            ->whereNull('ventes.deleted_at')
            ->selectRaw('paiements.moyen_paiement_id, SUM(paiements.montant) as total')
            ->groupBy('paiements.moyen_paiement_id')
            ->pluck('total', 'moyen_paiement_id');

        $parReglement = ReglementPaiement::query()
            ->join('reglement_clients', 'reglement_clients.id', '=', 'reglement_paiements.reglement_client_id')
            ->join('session_caisses', 'session_caisses.id', '=', 'reglement_clients.session_caisse_id')
            ->join('caisses', 'caisses.id', '=', 'session_caisses.caisse_id')
            ->when($magasinId, fn ($q) => $q->where('caisses.magasin_id', $magasinId))
            ->where('reglement_clients.created_at', '>=', $depuis)
            ->selectRaw('reglement_paiements.moyen_paiement_id, SUM(reglement_paiements.montant) as total')
            ->groupBy('reglement_paiements.moyen_paiement_id')
            ->pluck('total', 'moyen_paiement_id');

        $totaux = collect();
        foreach ([$parVente, $parReglement] as $source) {
            foreach ($source as $moyenId => $total) {
                $totaux[$moyenId] = ($totaux[$moyenId] ?? 0) + $total;
            }
        }

        $moyens = MoyenPaiement::whereIn('id', $totaux->keys())->pluck('nom', 'id');

        return $totaux->map(fn ($total, $moyenId) => (object) [
            'nom' => $moyens[$moyenId] ?? '—',
            'total' => $total,
        ])->values();
    }

    private function comparatifMagasins()
    {
        $debutMois = Carbon::now()->startOfMonth();

        return Magasin::query()
            ->withSum(['ventes' => fn ($q) => $q->where('created_at', '>=', $debutMois)], 'total_net')
            ->orderByDesc('ventes_sum_total_net')
            ->get();
    }
}
