<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\TrieListe;
use App\Models\Client;
use App\Models\EcritureCompteClient;
use App\Models\MoyenPaiement;
use App\Models\RemboursementAvoirClient;
use App\Models\RetourVente;
use App\Models\SessionCaisse;
use App\Models\TypeClient;
use App\Models\Vente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientController extends Controller
{
    use TrieListe, ExporteListe;

    public function index(Request $request): View
    {
        $query = Client::query()
            ->with('typeClient')
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where(function ($sub) use ($recherche) {
                    $sub->where('nom', 'like', "%{$recherche}%")
                        ->orWhere('telephone', 'like', "%{$recherche}%");
                });
            });

        $query = $this->appliquerTri($query, $request, ['nom', 'actif', 'created_at']);

        // L'impression (voir x-bouton-imprimer) couvre tout le résultat
        // filtré, pas seulement la page affichée à l'écran.
        $clients = $request->boolean('tout') ? $query->get() : $query->paginate(20)->withQueryString();

        // Le solde est dérivé (règle 12) : calculé en une seule requête
        // groupée plutôt qu'une somme par ligne (évite un N+1 sur la page).
        $soldes = EcritureCompteClient::whereIn('client_id', $clients->pluck('id'))
            ->selectRaw('client_id, SUM(montant) as solde')
            ->groupBy('client_id')
            ->pluck('solde', 'client_id');

        return view('clients.index', ['clients' => $clients, 'soldes' => $soldes]);
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe(
            'Clients',
            ['Code', 'Nom', 'Type', 'Téléphone', 'Solde dû', 'Avoir', 'Limite de crédit', 'Statut'],
            $this->lignesExport($request),
            'clients.pdf',
        );
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe(
            'Clients',
            ['Code', 'Nom', 'Type', 'Téléphone', 'Solde dû', 'Avoir', 'Limite de crédit', 'Statut'],
            $this->lignesExport($request),
            'clients.xlsx',
        );
    }

    private function lignesExport(Request $request): \Illuminate\Support\Collection
    {
        $query = Client::query()
            ->with('typeClient')
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where(function ($sub) use ($recherche) {
                    $sub->where('nom', 'like', "%{$recherche}%")
                        ->orWhere('telephone', 'like', "%{$recherche}%");
                });
            });

        $clients = $this->appliquerTri($query, $request, ['nom', 'actif', 'created_at'], 'nom', 'asc')->get();

        $soldes = EcritureCompteClient::whereIn('client_id', $clients->pluck('id'))
            ->selectRaw('client_id, SUM(montant) as solde')
            ->groupBy('client_id')
            ->pluck('solde', 'client_id');

        return $clients->map(function (Client $c) use ($soldes) {
            $solde = (int) ($soldes[$c->id] ?? 0);

            return [
                $c->code,
                $c->nom,
                $c->typeClient->nom ?? '—',
                $c->telephone ?? '—',
                montant(max($solde, 0)),
                montant(max(-$solde, 0)),
                $c->limite_credit !== null ? montant($c->limite_credit) : 'Illimitée',
                $c->actif ? 'Actif' : 'Inactif',
            ];
        });
    }

    public function create(): View
    {
        return view('clients.create', ['typesClient' => TypeClient::where('actif', true)->orderBy('nom')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request);
        $codeGenere = blank($donnees['code']);

        if ($codeGenere) {
            $donnees['code'] = $this->genererCode();
        }

        $client = Client::create($donnees);

        $message = 'Client créé.'.($codeGenere ? " Code généré automatiquement : {$client->code}." : '');

        return redirect()->route('clients.index')->with('succes', $message);
    }

    /**
     * Création rapide depuis l'écran de vente ou de devis : juste le nom
     * (obligatoire) et le téléphone, pour ne pas interrompre la saisie en
     * cours. Le client reste modifiable ensuite depuis sa fiche complète.
     *
     * Valide manuellement (plutôt que $request->validate()) : cet endpoint
     * n'est appelé qu'en AJAX et doit toujours répondre en JSON, y compris
     * en cas d'échec — la détection automatique du format par Laravel
     * dépend de l'en-tête Accept envoyé par l'appelant, un point de
     * fragilité qu'on préfère éviter ici.
     */
    public function storeRapide(Request $request): JsonResponse
    {
        $validateur = Validator::make($request->all(), [
            'nom' => ['required', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:50'],
            'type_client_id' => ['nullable', 'exists:type_clients,id'],
        ]);

        if ($validateur->fails()) {
            return response()->json(['errors' => $validateur->errors()], 422);
        }

        $client = Client::create($validateur->validated() + ['actif' => true, 'code' => $this->genererCode()]);

        return response()->json([
            'id' => $client->id,
            'nom' => $client->nom,
            'telephone' => $client->telephone,
        ], 201);
    }

    public function show(Client $client): View
    {
        $client->loadCount('ventes');

        // Le "reste dû" par vente (bouton "Régler cette dette") a besoin de
        // soldeDuReel() sur la vente référencée : précharger ses
        // paiements/règlements/retours pour éviter un N+1 (voir Vente::soldeDuReel()).
        // RetourVente::vente : la facture d'origine, jamais nulle même pour un
        // retour crédité à ce client alors que la vente elle-même est restée
        // comptant (voir clients/show.blade.php, lien "facture d'origine").
        $ecritures = $client->ecritures()
            ->with(['auteur', 'reference' => function ($morphTo) {
                $morphTo->morphWith([
                    Vente::class => ['paiements', 'reglementsClient', 'retours'],
                    RetourVente::class => ['lignes.produit', 'vente'],
                    RemboursementAvoirClient::class => ['paiements'],
                ]);
            }])
            ->latest('created_at')
            ->paginate(15, ['*'], 'ecritures_page');

        // withTrashed() : une vente annulée reste visible dans l'historique
        // du client (avec badge), jamais masquée silencieusement — même
        // logique que le ticket de vente. Inclut aussi les ventes d'un AUTRE
        // client (souvent une vente comptant anonyme) dont un retour a été
        // crédité à CE client précis (voir RetourVenteController::store()) :
        // sans ça, la facture d'origine d'un avoir n'apparaissait nulle part
        // dans "Ventes", seulement noyée dans "Historique du compte" plus
        // bas — peu visible pour retrouver "quelle facture a généré cet
        // avoir ?". Marquée d'un badge distinct dans la vue (pas un achat de
        // ce client, juste un retour qui lui a été attribué).
        $ventes = Vente::withTrashed()
            ->where(function ($q) use ($client) {
                $q->where('client_id', $client->id)
                    ->orWhereHas('retours', fn ($q2) => $q2->where('client_id', $client->id));
            })
            ->with(['magasin', 'paiements', 'reglementsClient', 'lignes', 'retours', 'bonsLivraison.lignes', 'client'])
            ->latest('created_at')
            ->paginate(10, ['*'], 'ventes_page');

        $devis = $client->devis()
            ->latest('created_at')
            ->paginate(10, ['*'], 'devis_page');

        // Un règlement client exige une session de caisse ouverte (règle 14)
        // — celle de l'utilisateur courant.
        $sessionOuverte = SessionCaisse::where('caissier_id', request()->user()->id)
            ->whereNull('date_fermeture')
            ->whereNull('date_cloture')
            ->with('caisse')
            ->first();

        return view('clients.show', [
            'client' => $client,
            'solde' => $client->solde(),
            'totalVentes' => $client->totalVentes(),
            'totalRegle' => $client->totalRegle(),
            'ecritures' => $ecritures,
            'ventes' => $ventes,
            'sessionOuverte' => $sessionOuverte,
            'peutRegler' => request()->user()->can('client.reglement'),
            'moyensPaiement' => MoyenPaiement::actifs(),
            'devis' => $devis,
        ]);
    }

    public function ventesPdf(Client $client): Response
    {
        $ventes = $client->ventes()->withTrashed()->with(['magasin', 'lignes', 'bonsLivraison.lignes'])->orderBy('created_at')->get();

        return $this->pdfDepuisListe(
            "Ventes — {$client->nom}",
            ['Numéro', 'Date', 'Magasin', 'Total net', 'Statut', 'Livraison'],
            $ventes->map(fn (Vente $vente) => [
                $vente->numero,
                $vente->created_at->format('d/m/Y H:i'),
                $vente->magasin->nom,
                montant($vente->total_net),
                $vente->trashed() ? 'Annulée' : 'Validée',
                ! $vente->livraisonEngagee() ? '—' : ($vente->entierementLivree()
                    ? 'Entièrement livrée'
                    : quantite($vente->quantiteLivreePieces()).'/'.quantite($vente->lignes->sum('quantite_pieces')).' pièce(s)'),
            ]),
            'ventes-'.Str::slug($client->nom).'.pdf',
        );
    }

    public function exporterVentes(Client $client): StreamedResponse
    {
        $ventes = $client->ventes()->withTrashed()->with(['magasin', 'lignes', 'bonsLivraison.lignes'])->orderBy('created_at')->get();

        $spreadsheet = new Spreadsheet();
        $feuille = $spreadsheet->getActiveSheet();
        $feuille->setTitle('Ventes');
        $feuille->fromArray(['Numéro', 'Date', 'Magasin', 'Total net', 'Statut', 'Livraison'], null, 'A1');

        $ligne = 2;
        foreach ($ventes as $vente) {
            $feuille->setCellValue("A{$ligne}", $vente->numero);
            $feuille->setCellValue("B{$ligne}", $vente->created_at->format('d/m/Y H:i'));
            $feuille->setCellValue("C{$ligne}", $vente->magasin->nom);
            $feuille->setCellValue("D{$ligne}", $vente->total_net);
            $feuille->setCellValue("E{$ligne}", $vente->trashed() ? 'Annulée' : 'Validée');
            $feuille->setCellValue("F{$ligne}", ! $vente->livraisonEngagee() ? '—' : ($vente->entierementLivree()
                ? 'Entièrement livrée'
                : quantite($vente->quantiteLivreePieces()).'/'.quantite($vente->lignes->sum('quantite_pieces')).' pièce(s)'));
            $ligne++;
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $colonne) {
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }

        return $this->telechargerTableur($spreadsheet, 'ventes-'.Str::slug($client->nom).'.xlsx');
    }

    public function devisPdf(Client $client): Response
    {
        $devis = $client->devis()->orderBy('created_at')->get();

        return $this->pdfDepuisListe(
            "Devis — {$client->nom}",
            ['Numéro', 'Date', 'Statut', "Valide jusqu'au"],
            $devis->map(fn ($unDevis) => [
                $unDevis->numero,
                $unDevis->created_at->format('d/m/Y H:i'),
                $unDevis->statutEffectif()->libelle(),
                $unDevis->date_validite->format('d/m/Y'),
            ]),
            'devis-'.Str::slug($client->nom).'.pdf',
        );
    }

    public function exporterDevis(Client $client): StreamedResponse
    {
        $devis = $client->devis()->orderBy('created_at')->get();

        $spreadsheet = new Spreadsheet();
        $feuille = $spreadsheet->getActiveSheet();
        $feuille->setTitle('Devis');
        $feuille->fromArray(['Numéro', 'Date', 'Statut', 'Valide jusqu\'au'], null, 'A1');

        $ligne = 2;
        foreach ($devis as $unDevis) {
            $feuille->setCellValue("A{$ligne}", $unDevis->numero);
            $feuille->setCellValue("B{$ligne}", $unDevis->created_at->format('d/m/Y H:i'));
            $feuille->setCellValue("C{$ligne}", $unDevis->statutEffectif()->libelle());
            $feuille->setCellValue("D{$ligne}", $unDevis->date_validite->format('d/m/Y'));
            $ligne++;
        }

        foreach (['A', 'B', 'C', 'D'] as $colonne) {
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }

        return $this->telechargerTableur($spreadsheet, 'devis-'.Str::slug($client->nom).'.xlsx');
    }

    public function edit(Client $client): View
    {
        return view('clients.edit', [
            'client' => $client,
            'typesClient' => TypeClient::where('actif', true)->orderBy('nom')->get(),
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $donnees = $this->valider($request, $client);
        $codeGenere = blank($donnees['code']);

        if ($codeGenere) {
            $donnees['code'] = $this->genererCode();
        }

        $client->update($donnees);

        $message = 'Client mis à jour.'.($codeGenere ? " Code généré automatiquement : {$client->code}." : '');

        return redirect()->route('clients.index')->with('succes', $message);
    }

    public function destroy(Client $client): RedirectResponse
    {
        // Singleton (même principe que CompteTresorerie.type=caisse_generale) :
        // sert de compte à créditer pour un retour sur une vente comptant,
        // voir Client::comptantDefaut() / RetourVenteService.
        abort_if($client->comptant_defaut, 403, 'Le client "Client comptant" ne peut pas être supprimé.');

        if ($client->ventes()->exists()) {
            return redirect()->route('clients.index')->with('erreur', 'Ce client a des ventes, il ne peut pas être supprimé.');
        }

        $client->delete();

        return redirect()->route('clients.index')->with('succes', 'Client supprimé.');
    }

    private function valider(Request $request, ?Client $client = null): array
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('clients', 'code')->ignore($client?->id)],
            'type_client_id' => ['nullable', 'exists:type_clients,id'],
            'telephone' => ['nullable', 'string', 'max:50'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'limite_credit' => ['nullable', 'integer', 'min:0'],
            'actif' => ['boolean'],
        ]);

        $donnees['actif'] = $request->boolean('actif', true);

        return $donnees;
    }

    private function genererCode(): string
    {
        do {
            $code = 'CLI-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (Client::withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
