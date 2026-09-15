<?php

namespace App\Http\Controllers;

use App\Enums\EcritureCompteTresorerieType;
use App\Exceptions\SoldeTresorerieInsuffisantException;
use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\JournalCaisse;
use App\Models\Caisse;
use App\Models\CompteTresorerie;
use App\Models\EcritureCompteTresorerie;
use App\Models\MotifMouvement;
use App\Models\User;
use App\Services\CaisseSessionService;
use App\Services\CompteTresorerieService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Trésorerie de l'entreprise (Caisse Générale + comptes bancaires/autres),
 * volontairement séparée des caisses de vente des caissiers (voir
 * CLAUDE.md, Trésorerie) — cet écran liste les DEUX univers côte à côte
 * pour une vue d'ensemble, mais ne les mélange jamais comptablement. Les
 * mouvements manuels d'une caisse de caissier se saisissent directement sur
 * /sessions/{session} (SessionCaisseController::storeMouvement) — il n'y a
 * plus d'onglet séparé pour ça (voir CLAUDE.md, Mouvements de caisse).
 * Nommé "Comptabilité" en interne (routes/classe) pour rester distinct de
 * ce vocabulaire opérationnel, sans pour autant afficher le mot
 * "comptabilité" à l'écran, qui suggérerait à tort une comptabilité
 * générale (hors périmètre, voir CLAUDE.md).
 */
class ComptabiliteController extends Controller
{
    use ExporteListe, JournalCaisse;

    public function index(CaisseSessionService $caisseSessionService): View
    {
        $comptes = CompteTresorerie::where('actif', true)
            ->orderByRaw("type != 'caisse_generale'")
            ->orderBy('nom')
            ->get();

        $soldesComptes = $comptes->mapWithKeys(fn (CompteTresorerie $c) => [$c->id => $c->solde()]);

        // Toutes les caisses de caissier (pas seulement celles ouvertes en ce
        // moment) : cet écran est un répertoire comptable, pas l'écran
        // opérationnel du jour (voir /caisse) — une caisse actuellement
        // libre doit quand même apparaître, pour accéder à son historique.
        $caisses = Caisse::where('actif', true)
            ->with(['magasin', 'sessionCaisses' => fn ($q) => $q->whereNull('date_fermeture')->whereNull('date_cloture')->with('caissier')])
            ->orderBy('nom')
            ->get();

        $soldesTheoriques = $caisses->mapWithKeys(function (Caisse $c) use ($caisseSessionService) {
            $sessionOuverte = $c->sessionCaisses->first();

            return [$c->id => $sessionOuverte ? $caisseSessionService->calculerTheorique($sessionOuverte)['theorique'] : null];
        });

        return view('comptabilite.index', [
            'comptes' => $comptes,
            'soldesComptes' => $soldesComptes,
            'caisses' => $caisses,
            'soldesTheoriques' => $soldesTheoriques,
        ]);
    }

    public function show(Request $request, CompteTresorerie $compte): View
    {
        $requeteFiltree = $this->requeteEcrituresFiltrees($request, $compte);

        $ecritures = $request->boolean('tout')
            ? (clone $requeteFiltree)->with('auteur')->latest('created_at')->get()
            : (clone $requeteFiltree)->with('auteur')->latest('created_at')->paginate(30)->withQueryString();

        $autresComptes = CompteTresorerie::where('actif', true)
            ->where('id', '!=', $compte->id)
            ->orderBy('nom')
            ->get();

        return view('comptabilite.show', [
            'compte' => $compte,
            'solde' => $compte->solde(),
            'totalEntrees' => (int) (clone $requeteFiltree)->where('montant', '>', 0)->sum('montant'),
            'totalSorties' => (int) abs((clone $requeteFiltree)->where('montant', '<', 0)->sum('montant')),
            'ecritures' => $ecritures,
            'autresComptes' => $autresComptes,
            'motifs' => MotifMouvement::where('actif', true)->orderBy('nom')->get(),
        ]);
    }

    public function showPdf(Request $request, CompteTresorerie $compte): Response
    {
        $lignes = $this->requeteEcrituresFiltrees($request, $compte)->with('auteur')->latest('created_at')->get();

        return $this->pdfDepuisListe(
            $compte->nom,
            ['Date', 'Type', 'Motif', 'Auteur', 'Montant'],
            $lignes->map(fn (EcritureCompteTresorerie $e) => [
                $e->created_at->format('d\m\Y H:i'),
                $e->type->libelle(),
                $e->motif ?? '—',
                $e->auteur?->name ?? 'Utilisateur supprimé',
                ($e->montant >= 0 ? '+ ' : '− ').number_format(abs($e->montant), 0, ',', ' ').' F',
            ]),
            'compte-tresorerie.pdf',
            'Solde actuel : '.number_format($compte->solde(), 0, ',', ' ').' F',
            $this->bilanCompte($request, $compte),
        );
    }

    public function showExcel(Request $request, CompteTresorerie $compte): StreamedResponse
    {
        $lignes = $this->requeteEcrituresFiltrees($request, $compte)->with('auteur')->latest('created_at')->get();

        return $this->excelDepuisListe(
            $compte->nom,
            ['Date', 'Type', 'Motif', 'Auteur', 'Montant'],
            $lignes->map(fn (EcritureCompteTresorerie $e) => [
                $e->created_at->format('d\m\Y H:i'),
                $e->type->libelle(),
                $e->motif ?? '—',
                $e->auteur?->name ?? 'Utilisateur supprimé',
                $e->montant,
            ]),
            'compte-tresorerie.xlsx',
            $this->bilanCompte($request, $compte),
        );
    }

    /**
     * Mêmes chiffres que les KPI/le pied de tableau de comptabilite/show.blade.php
     * (voir show() ci-dessus) — jamais un nouveau calcul recopié.
     *
     * @return array<string, string>
     */
    private function bilanCompte(Request $request, CompteTresorerie $compte): array
    {
        $requeteFiltree = $this->requeteEcrituresFiltrees($request, $compte);

        return [
            'Solde actuel (toujours global)' => number_format($compte->solde(), 0, ',', ' ').' F',
            'Total entrées (période filtrée)' => number_format((int) (clone $requeteFiltree)->where('montant', '>', 0)->sum('montant'), 0, ',', ' ').' F',
            'Total sorties (période filtrée)' => number_format((int) abs((clone $requeteFiltree)->where('montant', '<', 0)->sum('montant')), 0, ',', ' ').' F',
        ];
    }

    /**
     * Écritures d'un compte filtrées par debut/fin/type (`show()`,
     * `showPdf()`, `showExcel()` doivent tous voir EXACTEMENT la même
     * liste) — jamais de période par défaut : contrairement à
     * rapports.tresorerie (qui retombe sur le mois en cours), l'absence de
     * filtre ici veut dire tout l'historique, cohérent avec le solde
     * "toujours global" affiché à côté (voir show()).
     */
    private function requeteEcrituresFiltrees(Request $request, CompteTresorerie $compte): HasMany
    {
        return $compte->ecritures()
            ->when($request->filled('debut'), fn ($q) => $q->where('created_at', '>=', Carbon::parse($request->string('debut'))->startOfDay()))
            ->when($request->filled('fin'), fn ($q) => $q->where('created_at', '<=', Carbon::parse($request->string('fin'))->endOfDay()))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()));
    }

    /**
     * Rapport dédié à UNE caisse de caissier, toutes sessions confondues
     * (contrairement à /sessions/{session}, qui reste scopé à une session
     * précise) — réutilise le même journal (ventes + mouvements manuels)
     * que rapports.mouvements-caisse, juste pré-filtré sur cette caisse.
     */
    public function showCaisseVente(Request $request, Caisse $caisse): View
    {
        $debut = $request->filled('debut') ? Carbon::parse($request->string('debut'))->startOfDay() : Carbon::now()->startOfMonth();
        $fin = $request->filled('fin') ? Carbon::parse($request->string('fin'))->endOfDay() : Carbon::now()->endOfDay();
        $caissierId = $request->filled('caissier_id') ? $request->integer('caissier_id') : null;
        $type = $request->filled('type') ? $request->string('type')->toString() : null;

        $requete = $this->requeteJournalCaisse($debut, $fin, caisseId: $caisse->id, caissierId: $caissierId, type: $type);

        $mouvements = $request->boolean('tout')
            ? $requete->get()
            : $requete->paginate(25)->withQueryString();

        $lignes = $mouvements instanceof LengthAwarePaginator ? $mouvements->getCollection() : $mouvements;
        $lignes->transform(fn ($l) => $this->decorerLigneJournal($l));

        // KPI toujours tous types confondus, comme rapports.mouvements-caisse.
        $toutesLesLignes = $this->requeteJournalCaisse($debut, $fin, caisseId: $caisse->id, caissierId: $caissierId)->get();

        return view('comptabilite.caisse-vente', [
            'caisse' => $caisse->loadMissing('magasin'),
            'mouvements' => $mouvements,
            'caissiers' => User::whereHas('sessionCaisses', fn ($q) => $q->where('caisse_id', $caisse->id))->orderBy('name')->get(),
            'debut' => $debut,
            'fin' => $fin,
            'nombre' => $request->boolean('tout') ? $mouvements->count() : $mouvements->total(),
            'totalVentes' => (int) $toutesLesLignes->where('type', 'vente')->sum('montant'),
            'totalEntrees' => (int) $toutesLesLignes->where('type', 'entree')->sum('montant'),
            'totalSorties' => (int) $toutesLesLignes->where('type', 'sortie')->sum('montant'),
            'totalRetours' => (int) $toutesLesLignes->where('type', 'retour')->sum('montant'),
        ]);
    }

    public function storeMouvement(Request $request, CompteTresorerie $compte, CompteTresorerieService $service): RedirectResponse
    {
        $donnees = $request->validate([
            'type' => ['required', Rule::in(['entree', 'sortie'])],
            'montant' => ['required', 'integer', 'min:1'],
            'motif' => ['required', 'string', 'max:255'],
        ]);

        try {
            if ($donnees['type'] === 'entree') {
                $service->crediter($compte, $donnees['montant'], EcritureCompteTresorerieType::EntreeManuelle, $request->user(), motif: $donnees['motif']);
            } else {
                $service->debiter($compte, $donnees['montant'], EcritureCompteTresorerieType::SortieManuelle, $request->user(), motif: $donnees['motif']);
            }
        } catch (SoldeTresorerieInsuffisantException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        return back()->with('succes', 'Mouvement enregistré.');
    }

    public function virer(Request $request, CompteTresorerie $compte, CompteTresorerieService $service): RedirectResponse
    {
        $donnees = $request->validate([
            'destination_id' => ['required', 'integer', 'exists:compte_tresoreries,id'],
            'montant' => ['required', 'integer', 'min:1'],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);

        $destination = CompteTresorerie::findOrFail($donnees['destination_id']);

        try {
            $service->virer($compte, $destination, $donnees['montant'], $request->user(), $donnees['motif'] ?? null);
        } catch (SoldeTresorerieInsuffisantException|InvalidArgumentException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        return back()->with('succes', "Virement effectué vers {$destination->nom}.");
    }

    public function storeCompte(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['banque', 'autre'])],
        ]);

        CompteTresorerie::create($donnees + ['actif' => true]);

        return back()->with('succes', 'Compte créé.');
    }

    public function updateCompte(Request $request, CompteTresorerie $compte): RedirectResponse
    {
        // La Caisse Générale est un singleton créé par CompteTresorerieSeeder
        // (voir CLAUDE.md, Trésorerie) : jamais renommable/désactivable
        // depuis l'UI.
        abort_if($compte->type === 'caisse_generale', 403, 'La Caisse Générale ne peut pas être modifiée.');

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'actif' => ['required', 'boolean'],
        ]);

        $compte->update($donnees);

        return back()->with('succes', 'Compte mis à jour.');
    }
}
