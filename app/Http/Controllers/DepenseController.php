<?php

namespace App\Http\Controllers;

use App\Exceptions\SoldeCaisseInsuffisantException;
use App\Exceptions\SoldeTresorerieInsuffisantException;
use App\Http\Controllers\Concerns\AutoriseMagasin;
use App\Http\Controllers\Concerns\ExporteListe;
use App\Models\CompteTresorerie;
use App\Models\Depense;
use App\Models\Magasin;
use App\Models\MotifMouvement;
use App\Models\SessionCaisse;
use App\Services\DepenseService;
use App\Support\ImageProduit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dépenses (voir App\Models\Depense) : la liste filtrée et son récapitulatif
 * par motif, la saisie d'une dépense, son annulation. Une dépense se saisit
 * aussi depuis le formulaire de sortie d'une session de caisse ou d'un compte
 * de trésorerie, dès que le motif choisi est marqué « dépense » : dans tous
 * les cas, l'écriture passe par DepenseService.
 *
 * Un utilisateur rattaché à un magasin ne voit que les dépenses de ce magasin.
 */
class DepenseController extends Controller
{
    use AutoriseMagasin, ExporteListe;

    private const ENTETES_EXPORT = ['Date', 'Motif', 'Bénéficiaire', 'Magasin', 'Payée depuis', 'Saisie par', 'État', 'Montant'];

    public function index(Request $request): View
    {
        [$debut, $fin] = $this->periode($request);
        $effectives = (clone $this->requeteListe($request))->reorder()->effectives();

        return view('depenses.index', [
            'depenses' => $this->requeteListe($request)->with(['magasin', 'sessionCaisse.caisse', 'compteTresorerie', 'auteur', 'media'])->paginate(25)->withQueryString(),
            'total' => (int) (clone $effectives)->sum('montant'),
            'nombre' => (clone $effectives)->count(),
            'parMotif' => (clone $effectives)->selectRaw('motif, SUM(montant) as total')->groupBy('motif')->orderByDesc('total')->get(),
            'debut' => $debut,
            'fin' => $fin,
            'motifs' => MotifMouvement::where('est_depense', true)->orderBy('nom')->get(),
            'magasins' => $request->user()->magasin_id ? collect() : Magasin::orderBy('nom')->get(),
        ]);
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe('Dépenses', self::ENTETES_EXPORT, $this->lignesExport($request), 'depenses.pdf', $this->sousTitre($request), $this->bilan($request));
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe('Dépenses', self::ENTETES_EXPORT, $this->lignesExport($request), 'depenses.xlsx', $this->bilan($request));
    }

    public function create(Request $request): View|RedirectResponse
    {
        $sources = $this->sources($request);

        if ($sources->isEmpty()) {
            return redirect()->route('depenses.index')
                ->with('erreur', "Pour enregistrer une dépense, il faut de l'argent à sortir : ouvrez d'abord une session de caisse.");
        }

        return view('depenses.create', [
            'sources' => $sources,
            'motifs' => MotifMouvement::where('est_depense', true)->where('actif', true)->orderBy('nom')->get(),
            'magasins' => $request->user()->magasin_id ? collect() : Magasin::where('actif', true)->orderBy('nom')->get(),
            'peutCreerMotif' => $request->user()->can('motif.gerer'),
        ]);
    }

    public function store(Request $request, DepenseService $service): RedirectResponse
    {
        $sources = $this->sources($request);

        $donnees = $request->validate([
            'source' => ['required', 'string', 'in:'.$sources->keys()->implode(',')],
            'motif_mouvement_id' => ['required', 'integer', 'exists:motif_mouvements,id'],
            'montant' => ['required', 'integer', 'min:1'],
            'beneficiaire' => ['nullable', 'string', 'max:255'],
            'magasin_id' => ['nullable', 'integer', 'exists:magasins,id'],
            'recu' => ['nullable', 'image', 'max:'.ImageProduit::ENVOI_MAX_KO],
        ], [
            'source.in' => "Cette source d'argent n'est plus disponible : rechargez la page.",
            'recu.max' => 'La photo du reçu ne doit pas dépasser 8 Mo.',
        ]);

        $motif = MotifMouvement::findOrFail($donnees['motif_mouvement_id']);
        $source = $sources[$donnees['source']]['modele'];

        try {
            if ($source instanceof SessionCaisse) {
                $service->payerDepuisSession($source, $motif, $donnees['montant'], $request->user(), $donnees['beneficiaire'] ?? null, $request->file('recu'));
            } else {
                $magasin = isset($donnees['magasin_id']) ? Magasin::find($donnees['magasin_id']) : null;
                $service->payerDepuisCompte($source, $motif, $donnees['montant'], $request->user(), $donnees['beneficiaire'] ?? null, $magasin, $request->file('recu'));
            }
        } catch (SoldeCaisseInsuffisantException|SoldeTresorerieInsuffisantException|InvalidArgumentException|RuntimeException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        return redirect()->route('depenses.index')->with('succes', 'Dépense enregistrée.');
    }

    public function annuler(Request $request, Depense $depense, DepenseService $service): RedirectResponse
    {
        $this->assurerMagasin($depense->magasin_id);

        $donnees = $request->validate(['raison' => ['required', 'string', 'max:255']], ['raison.required' => "Indiquez pourquoi cette dépense est annulée."]);

        try {
            $service->annuler($depense, $request->user(), $donnees['raison']);
        } catch (SoldeCaisseInsuffisantException|SoldeTresorerieInsuffisantException|InvalidArgumentException|RuntimeException $e) {
            return back()->with('erreur', $e->getMessage());
        }

        return back()->with('succes', "Dépense annulée : l'argent a été remis en caisse.");
    }

    /**
     * D'où l'utilisateur peut sortir de l'argent, par clé de formulaire : sa
     * propre session de caisse ouverte, et — s'il gère la trésorerie — la
     * Caisse Générale et les comptes.
     *
     * @return Collection<string, array{libelle: string, modele: SessionCaisse|CompteTresorerie}>
     */
    private function sources(Request $request): Collection
    {
        $sources = collect();

        $session = SessionCaisse::where('caissier_id', $request->user()->id)
            ->whereNull('date_fermeture')->whereNull('date_cloture')
            ->with('caisse')
            ->first();

        if ($session) {
            $sources['session'] = ['libelle' => 'Ma caisse — '.$session->caisse->nom, 'modele' => $session];
        }

        if ($request->user()->can('tresorerie.gerer')) {
            foreach (CompteTresorerie::where('actif', true)->orderByRaw("type = 'caisse_generale' desc")->orderBy('nom')->get() as $compte) {
                $sources['compte:'.$compte->id] = ['libelle' => $compte->nom, 'modele' => $compte];
            }
        }

        return $sources;
    }

    /**
     * Requête de la liste, partagée par l'écran et ses exports.
     */
    private function requeteListe(Request $request): Builder
    {
        [$debut, $fin] = $this->periode($request);
        $magasinUtilisateur = $request->user()->magasin_id;

        return Depense::query()
            ->whereBetween('created_at', [$debut, $fin])
            ->when($magasinUtilisateur, fn ($q) => $q->where('magasin_id', $magasinUtilisateur))
            ->when(! $magasinUtilisateur && $request->filled('magasin_id'), fn ($q) => $q->where('magasin_id', $request->integer('magasin_id')))
            ->when($request->filled('motif'), fn ($q) => $q->where('motif', $request->string('motif')->toString()))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche')->toString();
                $q->where(fn ($q) => $q->where('motif', 'like', "%{$recherche}%")->orWhere('beneficiaire', 'like', "%{$recherche}%"));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Période demandée (?du=…&au=…), le mois en cours par défaut.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periode(Request $request): array
    {
        $date = function (string $cle, Carbon $defaut): Carbon {
            try {
                return request()->filled($cle) ? Carbon::parse(request()->string($cle)->toString()) : $defaut;
            } catch (\Throwable) {
                return $defaut;
            }
        };

        return [$date('du', now()->startOfMonth())->startOfDay(), $date('au', now())->endOfDay()];
    }

    private function lignesExport(Request $request): Collection
    {
        return $this->requeteListe($request)->with(['magasin', 'sessionCaisse.caisse', 'compteTresorerie', 'auteur'])->get()->map(fn (Depense $depense) => [
            $depense->created_at->format('d/m/Y H:i'),
            $depense->motif,
            $depense->beneficiaire ?? '',
            $depense->magasin?->nom ?? "Toute l'entreprise",
            $depense->sourceLibelle(),
            $depense->auteur?->name ?? '',
            $depense->estAnnulee() ? 'Annulée' : 'Payée',
            $depense->montant,
        ]);
    }

    private function sousTitre(Request $request): string
    {
        [$debut, $fin] = $this->periode($request);

        return 'Du '.$debut->format('d/m/Y').' au '.$fin->format('d/m/Y');
    }

    /** @return array<string, string> */
    private function bilan(Request $request): array
    {
        $effectives = (clone $this->requeteListe($request))->reorder()->effectives();

        return [
            'Dépenses payées' => (string) (clone $effectives)->count(),
            'Total' => montant((int) (clone $effectives)->sum('montant')),
        ];
    }
}
