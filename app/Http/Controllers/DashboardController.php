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
use App\Support\PremiersPas;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Périodes d'analyse proposées sur le tableau de bord (clé d'URL =>
     * libellé). Elles portent sur la courbe, les moyens de paiement, l'aperçu
     * financier, les meilleures ventes et le comparatif par magasin — pas sur
     * les trois chiffres du haut (jour, semaine, mois), qui ont chacun la leur.
     */
    private const PERIODES = [
        'jour' => "Aujourd'hui",
        '7j' => '7 derniers jours',
        '30j' => '30 derniers jours',
        'mois' => 'Ce mois',
        'mois_dernier' => 'Mois dernier',
    ];

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

        // Guide de mise en route, tant qu'il reste une étape avant la première
        // vente (null ensuite) — voir App\Support\PremiersPas.
        $premiersPas = ['premiersPas' => PremiersPas::pour($utilisateur)];

        $periode = $this->periode($request);

        if ($utilisateur->magasin_id) {
            return view('dashboard.gerant', ['utilisateur' => $utilisateur] + $premiersPas + $this->donneesMagasin($utilisateur->magasin_id, $periode));
        }

        return view('dashboard.superadmin', ['utilisateur' => $utilisateur]
            + $premiersPas
            + $this->donneesMagasin(null, $periode)
            + ['comparatifMagasins' => $this->comparatifMagasins($periode['debut'], $periode['fin'])]);
    }

    /**
     * Période demandée (?periode=…), « ce mois » par défaut ou si la valeur
     * est inconnue.
     *
     * @return array{cle: string, libelle: string, debut: Carbon, fin: Carbon}
     */
    private function periode(Request $request): array
    {
        $cle = (string) $request->query('periode');
        $cle = array_key_exists($cle, self::PERIODES) ? $cle : 'mois';
        $maintenant = Carbon::now();

        [$debut, $fin] = match ($cle) {
            'jour' => [Carbon::today(), $maintenant],
            '7j' => [Carbon::today()->subDays(6), $maintenant],
            '30j' => [Carbon::today()->subDays(29), $maintenant],
            'mois_dernier' => [
                $maintenant->copy()->startOfMonth()->subMonthNoOverflow(),
                $maintenant->copy()->startOfMonth()->subSecond(),
            ],
            default => [$maintenant->copy()->startOfMonth(), $maintenant],
        };

        return ['cle' => $cle, 'libelle' => self::PERIODES[$cle], 'debut' => $debut, 'fin' => $fin];
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

    /**
     * @param  array{cle: string, libelle: string, debut: Carbon, fin: Carbon}  $periode
     */
    private function donneesMagasin(?int $magasinId, array $periode): array
    {
        [$debut, $fin] = [$periode['debut'], $periode['fin']];

        $ventesPeriode = Vente::query()
            ->when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))
            ->whereBetween('created_at', [$debut, $fin]);

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

        // Évolution par rapport à la période précédente, arrêtée au même
        // point (même jour et même heure, une semaine ou un mois plus tôt) :
        // comparer une période en cours à une période entière la ferait
        // toujours paraître en baisse. null quand il n'y a rien à comparer.
        $caEntre = fn (Carbon $du, Carbon $au) => (int) Vente::query()
            ->when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))
            ->whereBetween('created_at', [$du, $au])
            ->sum('total_net');
        $evolution = fn (int $actuel, int $precedent) => $precedent > 0
            ? (int) round(($actuel - $precedent) / $precedent * 100)
            : null;

        $caSemaine = $caVentes($debutSemaine);
        $caMois = $caVentes($debutMois);

        return [
            'periode' => $periode,
            'periodes' => self::PERIODES,
            'caPeriode' => (int) (clone $ventesPeriode)->sum('total_net'),
            'caJour' => $caVentes($debutJour),
            'nombreVentesJour' => Vente::query()
                ->when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))
                ->where('created_at', '>=', $debutJour)
                ->count(),
            'caSemaine' => $caSemaine,
            'evolutionSemaine' => $evolution($caSemaine, $caEntre($debutSemaine->copy()->subWeek(), Carbon::now()->subWeek())),
            'caMois' => $caMois,
            'evolutionMois' => $evolution($caMois, $caEntre($debutMois->copy()->subMonthNoOverflow(), Carbon::now()->subMonthNoOverflow())),
            'nombreVentesMois' => $nombreVentesMois,
            'panierMoyenMois' => $nombreVentesMois > 0 ? (int) round((clone $ventesMois)->sum('total_net') / $nombreVentesMois) : 0,
            'topProduits' => $this->topProduits($magasinId, $debut, $fin),
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
            'avoirAppliquePeriode' => (int) (clone $ventesPeriode)->sum('avoir_applique'),
            'totalEspecesPeriode' => $this->totalEspeces($magasinId, $debut, $fin),
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
            'evolutionVentes' => $this->evolutionVentes($magasinId, $debut, $fin),
            'repartitionMoyens' => $this->repartitionMoyensPaiement($magasinId, $debut, $fin),
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
     * Espèces réellement encaissées sur la période (paiements à la vente + règlements
     * clients) — la seule chose qui alimente vraiment un tiroir (règle 10),
     * contrairement au CA (total_net) qui inclut aussi le crédit et l'avoir.
     */
    private function totalEspeces(?int $magasinId, Carbon $debut, Carbon $fin): int
    {
        $ventes = (int) Paiement::query()
            ->join('ventes', 'ventes.id', '=', 'paiements.vente_id')
            ->join('moyen_paiements', 'moyen_paiements.id', '=', 'paiements.moyen_paiement_id')
            ->where('moyen_paiements.est_espece', true)
            ->when($magasinId, fn ($q) => $q->where('ventes.magasin_id', $magasinId))
            ->whereBetween('ventes.created_at', [$debut, $fin])
            ->whereNull('ventes.deleted_at')
            ->sum('paiements.montant');

        $reglements = (int) ReglementPaiement::query()
            ->join('reglement_clients', 'reglement_clients.id', '=', 'reglement_paiements.reglement_client_id')
            ->join('session_caisses', 'session_caisses.id', '=', 'reglement_clients.session_caisse_id')
            ->join('caisses', 'caisses.id', '=', 'session_caisses.caisse_id')
            ->join('moyen_paiements', 'moyen_paiements.id', '=', 'reglement_paiements.moyen_paiement_id')
            ->where('moyen_paiements.est_espece', true)
            ->when($magasinId, fn ($q) => $q->where('caisses.magasin_id', $magasinId))
            ->whereBetween('reglement_clients.created_at', [$debut, $fin])
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

    private function topProduits(?int $magasinId, Carbon $debut, Carbon $fin, int $limite = 5)
    {
        return DB::table('ligne_ventes')
            ->join('ventes', 'ventes.id', '=', 'ligne_ventes.vente_id')
            ->join('produits', 'produits.id', '=', 'ligne_ventes.produit_id')
            ->when($magasinId, fn ($q) => $q->where('ventes.magasin_id', $magasinId))
            ->whereBetween('ventes.created_at', [$debut, $fin])
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

    /**
     * Ventes de la période, jour par jour — ou heure par heure quand la
     * période tient en une seule journée (un seul point ne dirait rien).
     *
     * @return array{labels: list<string>, valeurs: list<int>, unite: string}
     */
    private function evolutionVentes(?int $magasinId, Carbon $debut, Carbon $fin): array
    {
        $parHeure = $debut->isSameDay($fin);

        $totaux = Vente::query()
            ->when($magasinId, fn ($q) => $q->where('magasin_id', $magasinId))
            ->whereBetween('created_at', [$debut, $fin])
            ->selectRaw(($parHeure ? 'HOUR(created_at)' : 'DATE(created_at)').' as tranche, SUM(total_net) as total')
            ->groupBy('tranche')
            ->pluck('total', 'tranche');

        $labels = [];
        $valeurs = [];

        if ($parHeure) {
            // De 8 h (ou de la première vente, si elle est plus tôt) à l'heure en cours.
            $premiere = min(8, (int) ($totaux->keys()->min() ?? 8));

            for ($heure = $premiere; $heure <= max($fin->hour, $premiere); $heure++) {
                $labels[] = $heure.' h';
                $valeurs[] = (int) ($totaux[$heure] ?? 0);
            }
        } else {
            for ($date = $debut->copy()->startOfDay(); $date->lte($fin); $date->addDay()) {
                $labels[] = $date->format('d/m');
                $valeurs[] = (int) ($totaux[$date->toDateString()] ?? 0);
            }
        }

        return ['labels' => $labels, 'valeurs' => $valeurs, 'unite' => $parHeure ? 'heure' : 'jour'];
    }

    /**
     * Répartition espèces/mobile money/… de la période — deux sources, comme
     * SessionCaisseController::paiementsParMoyen() : les paiements encaissés
     * à la vente ET les règlements clients encaissés séparément (règle 10),
     * sinon la ventilation sous-comptait tout règlement hors vente.
     */
    private function repartitionMoyensPaiement(?int $magasinId, Carbon $debut, Carbon $fin)
    {
        $parVente = Paiement::query()
            ->join('ventes', 'ventes.id', '=', 'paiements.vente_id')
            ->when($magasinId, fn ($q) => $q->where('ventes.magasin_id', $magasinId))
            ->whereBetween('ventes.created_at', [$debut, $fin])
            ->whereNull('ventes.deleted_at')
            ->selectRaw('paiements.moyen_paiement_id, SUM(paiements.montant) as total')
            ->groupBy('paiements.moyen_paiement_id')
            ->pluck('total', 'moyen_paiement_id');

        $parReglement = ReglementPaiement::query()
            ->join('reglement_clients', 'reglement_clients.id', '=', 'reglement_paiements.reglement_client_id')
            ->join('session_caisses', 'session_caisses.id', '=', 'reglement_clients.session_caisse_id')
            ->join('caisses', 'caisses.id', '=', 'session_caisses.caisse_id')
            ->when($magasinId, fn ($q) => $q->where('caisses.magasin_id', $magasinId))
            ->whereBetween('reglement_clients.created_at', [$debut, $fin])
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

    private function comparatifMagasins(Carbon $debut, Carbon $fin)
    {
        // Un dépôt ne vend jamais (aucune caisse) : il n'a rien à faire dans
        // un comparatif de ventes.
        return Magasin::query()
            ->magasins()
            ->withSum(['ventes' => fn ($q) => $q->whereBetween('created_at', [$debut, $fin])], 'total_net')
            ->orderByDesc('ventes_sum_total_net')
            ->get();
    }
}
