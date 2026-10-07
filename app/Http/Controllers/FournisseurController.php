<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\TrieListe;
use App\Models\CommandeAchat;
use App\Models\EcritureCompteFournisseur;
use App\Models\Fournisseur;
use App\Models\MoyenPaiement;
use App\Models\ReglementFournisseur;
use App\Models\RemboursementAvoirFournisseur;
use App\Models\RetourAchat;
use App\Rules\TelephoneValide;
use App\Support\Telephone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FournisseurController extends Controller
{
    use TrieListe, ExporteListe;

    public function index(Request $request): View
    {
        $query = Fournisseur::query()
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where(function ($sub) use ($recherche) {
                    $sub->where('nom', 'like', "%{$recherche}%")
                        ->when(Telephone::pourRecherche($recherche) !== '', fn ($t) => $t->orWhere('telephone', 'like', '%'.Telephone::pourRecherche($recherche).'%'))
                        ->orWhere('email', 'like', "%{$recherche}%");
                });
            });

        $query = $this->appliquerTri($query, $request, ['nom', 'actif', 'created_at']);

        // L'impression (voir x-bouton-imprimer) couvre tout le résultat
        // filtré, pas seulement la page affichée à l'écran.
        $fournisseurs = $request->boolean('tout') ? $query->get() : $query->paginate(20)->withQueryString();

        // Le solde est dérivé (mirroring ClientController::index()) : calculé
        // en une seule requête groupée plutôt qu'une somme par ligne.
        $soldes = EcritureCompteFournisseur::whereIn('fournisseur_id', $fournisseurs->pluck('id'))
            ->selectRaw('fournisseur_id, SUM(montant) as solde')
            ->groupBy('fournisseur_id')
            ->pluck('solde', 'fournisseur_id');

        return view('fournisseurs.index', ['fournisseurs' => $fournisseurs, 'soldes' => $soldes]);
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe(
            'Fournisseurs',
            ['Code', 'Nom', 'Téléphone', 'E-mail', 'Solde dû', 'Avoir', 'Statut'],
            $this->lignesExport($request),
            'fournisseurs.pdf',
        );
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe(
            'Fournisseurs',
            ['Code', 'Nom', 'Téléphone', 'E-mail', 'Solde dû', 'Avoir', 'Statut'],
            $this->lignesExport($request),
            'fournisseurs.xlsx',
        );
    }

    private function lignesExport(Request $request): \Illuminate\Support\Collection
    {
        $query = Fournisseur::query()
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where(function ($sub) use ($recherche) {
                    $sub->where('nom', 'like', "%{$recherche}%")
                        ->when(Telephone::pourRecherche($recherche) !== '', fn ($t) => $t->orWhere('telephone', 'like', '%'.Telephone::pourRecherche($recherche).'%'))
                        ->orWhere('email', 'like', "%{$recherche}%");
                });
            });

        $fournisseurs = $this->appliquerTri($query, $request, ['nom', 'actif', 'created_at'], 'nom', 'asc')->get();

        $soldes = EcritureCompteFournisseur::whereIn('fournisseur_id', $fournisseurs->pluck('id'))
            ->selectRaw('fournisseur_id, SUM(montant) as solde')
            ->groupBy('fournisseur_id')
            ->pluck('solde', 'fournisseur_id');

        return $fournisseurs->map(function (Fournisseur $f) use ($soldes) {
            $solde = (int) ($soldes[$f->id] ?? 0);

            return [
                $f->code,
                $f->nom,
                telephone($f->telephone) ?: '—',
                $f->email ?? '—',
                montant(max($solde, 0)),
                montant(max(-$solde, 0)),
                $f->actif ? 'Actif' : 'Inactif',
            ];
        });
    }

    public function commandesPdf(Request $request, Fournisseur $fournisseur): Response
    {
        return $this->pdfDepuisListe(
            "Bons de commande — {$fournisseur->nom}",
            ['Numéro', 'Date', 'Destination(s)', 'Statut', 'Réception', 'Retours', 'Montant facturé', 'Réglé', 'Reste dû'],
            $this->lignesExportCommandes($request, $fournisseur),
            "bons-de-commande-{$fournisseur->code}.pdf",
        );
    }

    public function commandesExcel(Request $request, Fournisseur $fournisseur): StreamedResponse
    {
        return $this->excelDepuisListe(
            "Bons de commande {$fournisseur->nom}",
            ['Numéro', 'Date', 'Destination(s)', 'Statut', 'Réception', 'Retours', 'Montant facturé', 'Réglé', 'Reste dû'],
            $this->lignesExportCommandes($request, $fournisseur),
            "bons-de-commande-{$fournisseur->code}.xlsx",
        );
    }

    /**
     * Mêmes lignes/colonnes que le tableau "Bons de commande" de la fiche
     * fournisseur, y compris le filtre réception incomplète — jamais un
     * calcul divergent entre l'écran et l'export (voir CLAUDE.md, rapports).
     */
    private function lignesExportCommandes(Request $request, Fournisseur $fournisseur): \Illuminate\Support\Collection
    {
        $commandes = $fournisseur->commandeAchats()
            ->withTrashed()
            ->when($request->boolean('reception_incomplete'), fn ($q) => $q->receptionIncomplete())
            ->with(['lignes.taxe', 'lignes.magasinDestination', 'lignes.receptions.taxe', 'paiements', 'reglementsFournisseur', 'receptions.lignes.taxe', 'retours'])
            ->latest('created_at')
            ->get();

        return $commandes->map(fn (CommandeAchat $c) => [
            $c->numero,
            $c->created_at->format('d/m/Y H:i'),
            $c->lignes->pluck('magasinDestination.nom')->filter()->unique()->implode(', ') ?: '—',
            $c->trashed() ? 'Annulée' : ($c->statut === 'validee' ? 'Validée' : 'Brouillon'),
            $c->statut === 'validee' ? quantite($c->quantiteRecuePieces()).'/'.quantite($c->quantiteCommandeePieces()).' ('.$c->tauxCompletion().' %)' : '—',
            $c->retours->isNotEmpty() ? $c->retours->count().' · '.montant($c->retours->sum('montant_total')) : '—',
            montant($c->totalTtcReel()),
            $c->statut === 'validee' ? montant($c->montantRegle()) : '—',
            $c->statut === 'validee' ? montant($c->resteDu()) : '—',
        ]);
    }

    public function show(Request $request, Fournisseur $fournisseur): View
    {
        $fournisseur->loadCount('commandeAchats');

        // Colonne "Référence" : une écriture achat_credit référence directement
        // une CommandeAchat ; une écriture reglement référence un
        // ReglementFournisseur, dont on a besoin de la commande éventuellement
        // imputée pour l'afficher (voir CommandeAchat::resteDu() pour le
        // premier cas — évite un N+1).
        $ecritures = $fournisseur->ecritures()
            ->with(['auteur', 'reference' => function ($morphTo) {
                $morphTo->morphWith([
                    CommandeAchat::class => ['lignes', 'paiements', 'reglementsFournisseur'],
                    ReglementFournisseur::class => ['commandeAchat'],
                    RetourAchat::class => ['lignes.produit', 'commandeAchat'],
                    RemboursementAvoirFournisseur::class => ['paiements'],
                ]);
            }])
            ->latest('created_at')
            ->paginate(15, ['*'], 'ecritures_page');

        $commandes = $fournisseur->commandeAchats()
            ->withTrashed()
            ->when($request->boolean('reception_incomplete'), fn ($q) => $q->receptionIncomplete())
            ->with(['lignes.taxe', 'lignes.magasinDestination', 'lignes.receptions.taxe', 'paiements', 'reglementsFournisseur', 'receptions.lignes.taxe', 'retours'])
            ->latest('created_at')
            ->paginate(10, ['*'], 'commandes_page')
            ->withQueryString();

        return view('fournisseurs.show', [
            'fournisseur' => $fournisseur,
            'solde' => $fournisseur->solde(),
            'totalAchats' => $fournisseur->totalAchats(),
            'totalRegle' => $fournisseur->totalRegle(),
            'ecritures' => $ecritures,
            'commandes' => $commandes,
            'receptionIncomplete' => $request->boolean('reception_incomplete'),
            'moyensPaiement' => MoyenPaiement::actifs(),
        ]);
    }

    public function create(): View
    {
        return view('fournisseurs.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request);
        $codeGenere = blank($donnees['code']);

        if ($codeGenere) {
            $donnees['code'] = $this->genererCode();
        }

        $fournisseur = Fournisseur::create($donnees);

        $message = 'Fournisseur créé.'.($codeGenere ? " Code généré automatiquement : {$fournisseur->code}." : '');

        return redirect()->route('fournisseurs.index')->with('succes', $message);
    }

    public function edit(Fournisseur $fournisseur): View
    {
        return view('fournisseurs.edit', ['fournisseur' => $fournisseur]);
    }

    public function update(Request $request, Fournisseur $fournisseur): RedirectResponse
    {
        $donnees = $this->valider($request, $fournisseur);
        $codeGenere = blank($donnees['code']);

        if ($codeGenere) {
            $donnees['code'] = $this->genererCode();
        }

        $fournisseur->update($donnees);

        $message = 'Fournisseur mis à jour.'.($codeGenere ? " Code généré automatiquement : {$fournisseur->code}." : '');

        return redirect()->route('fournisseurs.index')->with('succes', $message);
    }

    public function destroy(Fournisseur $fournisseur): RedirectResponse
    {
        if ($fournisseur->commandeAchats()->exists()) {
            return redirect()->route('fournisseurs.index')->with('erreur', 'Ce fournisseur a des commandes d\'achat, il ne peut pas être supprimé.');
        }

        $fournisseur->delete();

        return redirect()->route('fournisseurs.index')->with('succes', 'Fournisseur supprimé.');
    }

    private function valider(Request $request, ?Fournisseur $fournisseur = null): array
    {
        Telephone::normaliserDansRequete($request);

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('fournisseurs', 'code')->ignore($fournisseur?->id)],
            'telephone' => ['nullable', 'string', 'max:50', new TelephoneValide],
            'email' => ['nullable', 'email', 'max:255'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'actif' => ['boolean'],
        ]);

        $donnees['actif'] = $request->boolean('actif', true);

        return $donnees;
    }

    private function genererCode(): string
    {
        do {
            $code = 'FRN-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (Fournisseur::withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
