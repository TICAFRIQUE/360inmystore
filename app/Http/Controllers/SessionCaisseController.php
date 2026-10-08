<?php

namespace App\Http\Controllers;

use App\Enums\MouvementCaisseType;
use App\Exceptions\CaisseNonLibreException;
use App\Exceptions\CaissierDejaEnSessionException;
use App\Exceptions\SoldeCaisseInsuffisantException;
use App\Exceptions\VentesEnAttentePresentesException;
use App\Http\Controllers\Concerns\AutoriseMagasin;
use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\TrieListe;
use App\Models\Caisse;
use App\Models\Magasin;
use App\Models\MotifMouvement;
use App\Models\MouvementCaisse;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use App\Models\ReglementPaiement;
use App\Models\SessionCaisse;
use App\Models\Vente;
use App\Services\CaisseMouvementService;
use App\Services\CaisseSessionService;
use App\Services\DepenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use RuntimeException;

class SessionCaisseController extends Controller
{
    use TrieListe, AutoriseMagasin, ExporteListe;

    public function index(Request $request): View
    {
        $caisses = Caisse::query()
            ->where('actif', true)
            ->when($request->user()->magasin_id, fn ($q, $magasinId) => $q->where('magasin_id', $magasinId))
            ->with(['magasin', 'sessionCaisses' => fn ($q) => $q->whereNull('date_fermeture')->with('caissier')])
            ->withCount(['sessionCaisses as sessions_aujourdhui_count' => fn ($q) => $q->whereDate('date_ouverture', now()->toDateString())])
            ->orderBy('nom')
            ->get();

        [$caissesOccupees, $caissesLibres] = $caisses->partition(fn (Caisse $caisse) => $caisse->sessionCaisses->isNotEmpty());

        $sessionCaissierOuverte = SessionCaisse::where('caissier_id', $request->user()->id)
            ->whereNull('date_fermeture')
            ->with('caisse')
            ->first();

        return view('sessions.index', [
            'caissesOccupees' => $caissesOccupees,
            'caissesLibres' => $caissesLibres,
            'sessionCaissierOuverte' => $sessionCaissierOuverte,
            // Aucune caisse à l'écran : la vue dit quoi faire selon ce qui
            // manque (un magasin d'abord, ou seulement une caisse).
            'aucunMagasin' => $caisses->isEmpty() && Magasin::magasins()->where('actif', true)->doesntExist(),
            // Rappel non bloquant (voir x-alerte-sessions-anciennes, même
            // logique que DashboardController::donneesMagasin()) : cette page
            // liste déjà l'occupation de toutes les caisses du périmètre
            // (magasin, ou tous magasins pour le superadmin), donc pas besoin
            // du pendant singulier ici — la session du caissier connecté, si
            // ancienne, apparaît simplement dans cette même liste.
            'sessionsAnciennes' => $caissesOccupees
                ->map(fn (Caisse $c) => $c->sessionCaisses->first())
                ->filter(fn (?SessionCaisse $s) => $s?->estOuverteDepuisJourPrecedent())
                ->values(),
        ]);
    }

    public function create(Caisse $caisse): View
    {
        $this->assurerMagasin($caisse->magasin_id);

        return view('sessions.create', ['caisse' => $caisse]);
    }

    public function store(Request $request, Caisse $caisse, CaisseSessionService $caisseSessionService): RedirectResponse
    {
        $this->assurerMagasin($caisse->magasin_id);

        $donnees = $request->validate([
            'fond_de_caisse' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $session = $caisseSessionService->ouvrir($caisse, $request->user(), $donnees['fond_de_caisse']);
        } catch (CaisseNonLibreException|CaissierDejaEnSessionException $e) {
            return redirect()->route('sessions.index')->with('erreur', $e->getMessage());
        }

        return redirect()->route('sessions.show', $session)->with('succes', 'Session ouverte.');
    }

    public function show(Request $request, SessionCaisse $session, CaisseSessionService $caisseSessionService): View
    {
        $this->assurerMagasin($session->caisse->magasin_id);

        $session->load(['caisse.magasin', 'caissier', 'mouvementCaisses.auteur']);

        // Solde théorique du tiroir en temps réel : uniquement pour une
        // session encore ouverte (une fois clôturée, les totaux figés
        // sont ceux du bloc "Clôture" plus bas, calculés une fois pour
        // toutes à la clôture — voir CaisseSessionService::cloturer()).
        $detailTheorique = $session->date_cloture === null ? $caisseSessionService->calculerTheorique($session) : null;
        // vente_en_attentes_count : total de la caisse, sert au blocage de
        // clôture (règle 8 — bloque tant qu'il en reste, peu importe qui les
        // a créées). venteEnAttentesVisibles : ce que CE caissier peut
        // effectivement voir/traiter (les siennes, sauf s'il a caisse.gerer)
        // — c'est celui-là qu'affiche le KPI/badge, sinon le chiffre ne
        // correspond pas à ce que le caissier trouve en cliquant dessus.
        $session->loadCount(['ventes', 'venteEnAttentes']);
        $venteEnAttentesVisibles = $session->venteEnAttentes()
            ->when(! $request->user()->can('caisse.gerer'), fn ($q) => $q->where('caissier_id', $request->user()->id))
            ->count();

        $paiementsParMoyen = $this->paiementsParMoyen($session);

        // Décomposition du solde du tiroir ci-dessus : sur TOUTE la session,
        // pas seulement la page affichée par $ventes plus bas (paginée). Une
        // seule requête groupée pour dû/retours, chacun dérivé de la même
        // collection (zéro requête dupliquée).
        $ventesSession = $session->ventes()->with('paiements', 'reglementsClient', 'retours')->get();
        $totalVentes = (int) $ventesSession->sum('total_net');
        $totalDu = (int) $ventesSession->sum(fn (Vente $v) => $v->soldeDuReel());
        $totalRetours = (int) $ventesSession->sum(fn (Vente $v) => $v->retours->sum('montant_total'));
        $nbRetours = $ventesSession->sum(fn (Vente $v) => $v->retours->count());
        $avoirApplique = (int) $session->ventes()->sum('avoir_applique');
        $totalReglementsClient = (int) $session->reglementClients()->sum('montant');

        // paiements/reglementsClient : nécessaires à Vente::montantRegle()/
        // soldeDu() (colonnes Réglé/Reste dû du tableau) — chargées ici pour
        // éviter un N+1 sur chaque ligne de la page.
        $query = $session->ventes()->getQuery()
            ->with(['paiements', 'reglementsClient', 'retours', 'lignes', 'bonsLivraison.lignes'])
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where('numero', 'like', "%{$recherche}%");
            });

        $ventes = $this->appliquerTri($query, $request, ['numero', 'created_at', 'total_net'], 'created_at')
            ->paginate(20)
            ->withQueryString();

        // Signale qu'une même caisse a été rouverte plusieurs fois le même
        // jour — la clôture reste calculée par session (jamais par jour),
        // ceci n'est qu'un lien vers le rapport pour la vue d'ensemble.
        $sessionsAujourdhui = SessionCaisse::where('caisse_id', $session->caisse_id)
            ->whereDate('date_ouverture', $session->date_ouverture->toDateString())
            ->where('id', '!=', $session->id)
            ->count();

        return view('sessions.show', [
            'session' => $session,
            'totalVentes' => $totalVentes,
            'totalDu' => $totalDu,
            'totalRetours' => $totalRetours,
            'nbRetours' => $nbRetours,
            'avoirApplique' => $avoirApplique,
            'totalReglementsClient' => $totalReglementsClient,
            'venteEnAttentesVisibles' => $venteEnAttentesVisibles,
            'paiementsParMoyen' => $paiementsParMoyen,
            'ventes' => $ventes,
            'sessionsAujourdhui' => $sessionsAujourdhui,
            'peutMouvementer' => $request->user()->can('caisse.mouvement'),
            'soldeTheorique' => $detailTheorique['theorique'] ?? null,
            'totalEntreesCaisse' => $detailTheorique['entrees'] ?? $session->total_entrees_especes,
            'totalSortiesCaisse' => $detailTheorique['sorties'] ?? $session->total_sorties_especes,
            // Un motif marqué « dépense » n'est proposé qu'à qui peut
            // enregistrer une dépense (voir storeMouvement()).
            'motifs' => MotifMouvement::where('actif', true)
                ->when(! $request->user()->can('depense.creer'), fn ($q) => $q->where('est_depense', false))
                ->orderBy('nom')->get(),
        ]);
    }

    /**
     * Mouvement de caisse manuel (entrée/sortie) — désormais enregistré
     * directement depuis l'écran de session (voir CLAUDE.md, Mouvements de
     * caisse) : plus d'onglet séparé, une session de caisse ouverte reste
     * l'unique pré-requis (règle 19).
     */
    public function storeMouvement(Request $request, SessionCaisse $session, CaisseMouvementService $mouvementService, DepenseService $depenseService): RedirectResponse
    {
        $this->assurerMagasin($session->caisse->magasin_id);
        $this->assurerProprietaireOuGerant($session);

        $donnees = $request->validate([
            'type' => ['required', Rule::in(['entree', 'sortie'])],
            'montant' => ['required', 'integer', 'min:1'],
            'motif' => ['required', 'string', 'max:255'],
            'beneficiaire' => ['nullable', 'string', 'max:255'],
        ]);

        // Une sortie se fait toujours sous un motif de la liste : c'est lui
        // qui dit si c'est une dépense (voir MotifMouvement). Un texte libre
        // laisserait une dépense échapper au classement.
        if ($donnees['type'] === 'sortie') {
            $motif = MotifMouvement::where('nom', $donnees['motif'])->first();

            if (! $motif) {
                return redirect()->route('sessions.show', $session)->with('erreur', 'Choisissez le motif de la sortie dans la liste (le bouton « + » permet d\'en créer un).');
            }

            if ($motif->est_depense) {
                abort_unless($request->user()->can('depense.creer'), 403, "Vous n'êtes pas autorisé à enregistrer une dépense.");

                try {
                    $depenseService->payerDepuisSession($session, $motif, $donnees['montant'], $request->user(), $donnees['beneficiaire'] ?? null);
                } catch (SoldeCaisseInsuffisantException|InvalidArgumentException|RuntimeException $e) {
                    return redirect()->route('sessions.show', $session)->with('erreur', $e->getMessage());
                }

                return redirect()->route('sessions.show', $session)->with('succes', 'Dépense enregistrée.');
            }
        }

        try {
            $mouvementService->enregistrer(
                session: $session,
                type: MouvementCaisseType::from($donnees['type']),
                montant: $donnees['montant'],
                motif: $donnees['motif'],
                auteur: $request->user(),
            );
        } catch (SoldeCaisseInsuffisantException|InvalidArgumentException|RuntimeException $e) {
            return redirect()->route('sessions.show', $session)->with('erreur', $e->getMessage());
        }

        return redirect()->route('sessions.show', $session)->with('succes', 'Mouvement de caisse enregistré.');
    }

    /**
     * Bloque l'accès direct par URL à la session d'un autre caissier pour
     * l'action d'enregistrer un mouvement — même principe que
     * AutoriseVenteEnAttente::assurerProprietaireOuGerant() (voir CLAUDE.md).
     * show()/index() restent ouverts à tout le magasin (assurerMagasin
     * suffit), seule l'écriture d'un mouvement est réservée au propriétaire
     * de la session ou à un gérant.
     */
    private function assurerProprietaireOuGerant(SessionCaisse $session): void
    {
        abort_if(
            $session->caissier_id !== request()->user()->id && ! request()->user()->can('caisse.gerer'),
            403,
            'Cette caisse est tenue par un autre caissier.'
        );
    }

    public function cloturerForm(SessionCaisse $session, CaisseSessionService $caisseSessionService): View|RedirectResponse
    {
        $this->assurerMagasin($session->caisse->magasin_id);
        abort_if($session->date_cloture, 403, 'Cette session est déjà clôturée.');

        // Filet de sécurité côté UI : la règle est déjà appliquée par le
        // service à la validation, mais on évite de laisser le caissier
        // remplir un formulaire qui sera de toute façon rejeté.
        $nombreEnAttente = $session->venteEnAttentes()->count();
        if ($nombreEnAttente > 0) {
            return redirect()->route('sessions.show', $session)->with(
                'erreur',
                "Impossible de clôturer : {$nombreEnAttente} vente(s) en attente sur cette caisse. Finalisez-les ou annulez-les d'abord."
            );
        }

        // Même décomposition que sessions.show (Retour/avoir) — sert à donner
        // la même "story" financière sur l'écran de clôture, pas seulement
        // un théorique brut sans contexte.
        $ventesSession = $session->ventes()->with('retours')->get();
        $nbVentes = $ventesSession->count();
        $totalVentes = (int) $ventesSession->sum('total_net');
        $totalRetours = (int) $ventesSession->sum(fn (Vente $v) => $v->retours->sum('montant_total'));
        $nbRetours = $ventesSession->sum(fn (Vente $v) => $v->retours->count());
        $detail = $caisseSessionService->calculerTheorique($session);
        $paiementsParMoyen = $this->paiementsParMoyen($session);

        return view('sessions.cloturer', [
            'session' => $session,
            'theorique' => $detail['theorique'],
            'detailTheorique' => $detail,
            'nbVentes' => $nbVentes,
            'totalVentes' => $totalVentes,
            'totalRetours' => $totalRetours,
            'nbRetours' => $nbRetours,
            'paiementsParMoyen' => $paiementsParMoyen,
        ]);
    }

    public function cloturer(Request $request, SessionCaisse $session, CaisseSessionService $caisseSessionService): RedirectResponse
    {
        $this->assurerMagasin($session->caisse->magasin_id);

        $donnees = $request->validate([
            'montant_compte' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $caisseSessionService->cloturer($session, $donnees['montant_compte'], $request->user());
        } catch (VentesEnAttentePresentesException|RuntimeException $e) {
            return redirect()->route('sessions.show', $session)->with('erreur', $e->getMessage());
        }

        return redirect()->route('sessions.show', $session)->with('succes', 'Session clôturée.');
    }

    public function fermer(Request $request, SessionCaisse $session, CaisseSessionService $caisseSessionService): RedirectResponse
    {
        $this->assurerMagasin($session->caisse->magasin_id);

        try {
            $caisseSessionService->fermer($session);
        } catch (VentesEnAttentePresentesException|RuntimeException $e) {
            return redirect()->route('sessions.show', $session)->with('erreur', $e->getMessage());
        }

        return redirect()->route('sessions.index')->with('succes', 'Session fermée.');
    }

    public function rapport(SessionCaisse $session): View
    {
        $this->assurerMagasin($session->caisse->magasin_id);

        $session->load(['caisse.magasin', 'caissier', 'clotureePar', 'mouvementCaisses.auteur']);

        return view('sessions.rapport', [
            'session' => $session,
            'bilan' => $this->bilanRapportSession($session),
            // Mêmes colonnes que sessions.show (règlement, avoir, livraison,
            // retours) — un rapport n'est qu'une vue figée/imprimable de la
            // même session, jamais une liste appauvrie par rapport à l'écran
            // de travail du caissier.
            'ventes' => $session->ventes()
                ->with(['paiements', 'reglementsClient', 'retours', 'lignes', 'bonsLivraison.lignes'])
                ->orderBy('created_at')
                ->get(),
            // Liste détaillée en complément du total agrégé déjà affiché
            // (total_reglements_especes, voir bilanRapportSession()) — sans
            // ça, impossible de savoir depuis ce rapport quel client/quelle
            // facture précise a été réglée pendant la session.
            'reglements' => $session->reglementClients()->with(['client', 'vente', 'paiements.moyenPaiement'])->orderBy('created_at')->get(),
        ]);
    }

    public function rapportPdf(SessionCaisse $session): Response
    {
        $this->assurerMagasin($session->caisse->magasin_id);
        $session->load(['caisse.magasin', 'caissier']);

        return $this->pdfDepuisListe(
            "Rapport de caisse — {$session->caisse->nom}",
            // Libellés abrégés : 8 colonnes sur une feuille imprimée, pas de
            // place pour des en-têtes complets comme à l'écran.
            ['N°', 'Date/heure', 'Montant dû', 'Réglé', 'Avoir', 'Reste', 'Livraison', 'Retours'],
            $this->lignesVentesRapport($session),
            'rapport-caisse.pdf',
            'Caissier : '.$session->caissier->name.' — Ouverte le '.$session->date_ouverture->format('d/m/Y à H:i'),
            $this->bilanRapportSession($session),
        );
    }

    public function rapportExcel(SessionCaisse $session): StreamedResponse
    {
        $this->assurerMagasin($session->caisse->magasin_id);
        $session->load(['caisse.magasin', 'caissier']);

        return $this->excelDepuisListe(
            "Rapport de caisse — {$session->caisse->nom}",
            // Excel n'a pas la contrainte de largeur d'une page imprimée
            // (colonnes auto-dimensionnées) : en-têtes complets, pas abrégés.
            ['Numéro', 'Date et heure', 'Montant dû', 'Déjà réglé', 'Avoir appliqué', 'Reste à payer', 'Livraison', 'Retours'],
            $this->lignesVentesRapport($session, brut: true),
            'rapport-caisse.xlsx',
            $this->bilanRapportSession($session),
        );
    }

    /**
     * Lignes de la liste des ventes du rapport de caisse (PDF/Excel) — mêmes
     * colonnes que sessions/rapport.blade.php (voir rapport() ci-dessus),
     * jamais une liste appauvrie par rapport à l'écran. $brut : montants en
     * entier exploitable/calculable (Excel) plutôt qu'en texte formaté (PDF,
     * lecture seule) — Retours reste toujours un résumé texte (nombre +
     * montant), une seule cellule composite n'a pas besoin d'être sommable.
     *
     * @return iterable<int, array<int, string|int>>
     */
    private function lignesVentesRapport(SessionCaisse $session, bool $brut = false): iterable
    {
        $mont = fn (int $valeur) => $brut ? $valeur : montant($valeur);

        return $session->ventes()
            ->with(['lignes', 'bonsLivraison.lignes', 'retours'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (Vente $v) => [
                $v->numero,
                $v->created_at->format('d/m/Y H:i'),
                $mont($v->total_net),
                $mont($v->montantRegle()),
                $v->avoir_applique > 0 ? $mont($v->avoir_applique) : ($brut ? 0 : '—'),
                $mont($v->soldeDuReel()),
                $v->livraisonEngagee()
                    ? quantite($v->quantiteLivreePieces()).'/'.quantite($v->lignes->sum('quantite_pieces'))
                    : '—',
                $v->retours->isNotEmpty()
                    ? $v->retours->count().' · '.montant($v->retours->sum('montant_total'))
                    : '—',
            ]);
    }

    /**
     * Mêmes chiffres que les blocs compacts de sessions/rapport.blade.php
     * (voir rapport() ci-dessus) — jamais un nouveau calcul recopié.
     *
     * @return array<string, string>
     */
    private function bilanRapportSession(SessionCaisse $session): array
    {
        // Même décomposition Retour/avoir que sessions.show/cloturer (voir
        // CLAUDE.md — Mouvements de caisse) : jamais un nouveau calcul, la
        // même "story" financière partout où une session est résumée.
        $ventesSession = $session->ventes()->with('retours')->get();
        $totalRetours = (int) $ventesSession->sum(fn (Vente $v) => $v->retours->sum('montant_total'));
        $nbRetours = $ventesSession->sum(fn (Vente $v) => $v->retours->count());

        $bilan = [
            'Fond de caisse' => montant($session->fond_de_caisse),
            'Total ventes' => (string) $ventesSession->count(),
            'Montant vente' => montant((int) $ventesSession->sum('total_net')),
            'Retour/avoir' => montant($totalRetours).($nbRetours > 0 ? " ({$nbRetours})" : ''),
        ];

        foreach ($this->paiementsParMoyen($session) as $paiement) {
            $bilan[$paiement->moyenPaiement->nom] = montant($paiement->total);
        }

        if ($session->date_cloture) {
            $theorique = $session->fond_de_caisse + $session->total_ventes_especes + $session->total_reglements_especes
                + $session->total_entrees_especes - $session->total_sorties_especes;

            if ($session->total_reglements_especes > 0) {
                $bilan['Règlements clients (espèces)'] = montant($session->total_reglements_especes);
            }

            $bilan += [
                'Entrée' => montant($session->total_entrees_especes),
                'Sortie' => montant($session->total_sorties_especes),
                'Total en caisse' => montant($theorique),
                'Compté' => montant($session->montant_compte),
                'Écart' => ($session->ecart > 0 ? '+' : '').montant($session->ecart),
            ];
        }

        return $bilan;
    }

    /**
     * Répartition du total encaissé par moyen de paiement (espèces, mobile
     * money…) — factorisé pour ne pas dupliquer cette agrégation entre la
     * page de session, la clôture et le rapport de caisse.
     *
     * Deux sources alimentent le même tiroir (règle 10/14) : les paiements
     * encaissés à la vente ET les règlements clients encaissés plus tard
     * dans CETTE session — un règlement ignoré ici sous-comptait le total
     * réellement encaissé par moyen (visible dès qu'un règlement a lieu dans
     * la session).
     */
    private function paiementsParMoyen(SessionCaisse $session)
    {
        $parPaiement = Paiement::query()
            ->whereHas('vente', fn ($q) => $q->where('session_caisse_id', $session->id))
            ->selectRaw('moyen_paiement_id, sum(montant) as total')
            ->groupBy('moyen_paiement_id')
            ->pluck('total', 'moyen_paiement_id');

        $parReglement = ReglementPaiement::query()
            ->whereHas('reglementClient', fn ($q) => $q->where('session_caisse_id', $session->id))
            ->selectRaw('moyen_paiement_id, sum(montant) as total')
            ->groupBy('moyen_paiement_id')
            ->pluck('total', 'moyen_paiement_id');

        $totaux = collect();
        foreach ([$parPaiement, $parReglement] as $source) {
            foreach ($source as $moyenId => $total) {
                $totaux[$moyenId] = ($totaux[$moyenId] ?? 0) + $total;
            }
        }

        // Une sortie de caisse (manuelle ou remboursement d'avoir client,
        // voir CaisseMouvementService) est toujours en espèces (règle 19) —
        // déduite ici du total espèces pour que cette répartition raconte la
        // même histoire que "Total en caisse"
        // (CaisseSessionService::calculerTheorique()) : sans ça, "Espèces"
        // pouvait afficher un montant qui n'est plus du tout dans le tiroir.
        $especeId = MoyenPaiement::where('est_espece', true)->value('id');
        if ($especeId && $totaux->has($especeId)) {
            $sorties = (int) MouvementCaisse::where('session_caisse_id', $session->id)
                ->where('type', MouvementCaisseType::Sortie)
                ->sum('montant');
            $totaux[$especeId] = max(0, $totaux[$especeId] - $sorties);
        }

        $moyens = MoyenPaiement::whereIn('id', $totaux->keys())->get()->keyBy('id');

        return $totaux->map(fn ($total, $moyenId) => (object) [
            'moyenPaiement' => $moyens[$moyenId],
            'total' => $total,
        ])->values();
    }
}
