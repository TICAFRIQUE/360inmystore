<?php

namespace App\Http\Controllers;

use App\Exceptions\DevisNonModifiableException;
use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\TrieListe;
use App\Http\Controllers\Concerns\ValideRemises;
use App\Models\Client;
use App\Models\Devis;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\SessionCaisse;
use App\Models\Taxe;
use App\Models\TypeClient;
use App\Services\DevisService;
use App\Services\StockService;
use App\Support\Arrondi;
use App\Support\Decimal;
use App\Support\Remise;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DevisController extends Controller
{
    use TrieListe, ValideRemises, ExporteListe;

    private const ENTETES_EXPORT_LISTE = ['Numéro', 'Client', 'Statut', "Valide jusqu'au"];

    public function index(Request $request): View
    {
        $devis = $this->requeteListe($request)->paginate(20)->withQueryString();

        return view('devis.index', [
            'devis' => $devis,
        ]);
    }

    public function listePdf(Request $request): Response
    {
        return $this->pdfDepuisListe('Devis', self::ENTETES_EXPORT_LISTE, $this->lignesExport($request), 'devis.pdf');
    }

    public function listeExcel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe('Devis', self::ENTETES_EXPORT_LISTE, $this->lignesExport($request), 'devis.xlsx');
    }

    /**
     * Requête de la liste (filtres + tri), partagée par l'écran et ses exports :
     * un export porte toujours sur ce que l'écran affiche, sans la pagination.
     */
    private function requeteListe(Request $request): Builder
    {
        $query = Devis::query()
            ->with(['client'])
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where(function ($sub) use ($recherche) {
                    $sub->where('numero', 'like', "%{$recherche}%")
                        ->orWhereHas('client', fn ($c) => $c->where('nom', 'like', "%{$recherche}%"));
                });
            })
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')));

        return $this->appliquerTri($query, $request, ['numero', 'statut', 'date_validite', 'created_at'], 'created_at');
    }

    private function lignesExport(Request $request): Collection
    {
        return $this->requeteListe($request)->get()->map(fn (Devis $devis) => [
            $devis->numero,
            $devis->client->nom,
            $devis->statutEffectif()->libelle(),
            $devis->date_validite->format('d/m/Y'),
        ]);
    }

    public function create(): View
    {
        return view('devis.create', [
            'produits' => Produit::catalogueVente(),
            'clients' => Client::where('actif', true)->orderBy('nom')->get(['id', 'nom', 'telephone']),
            'panierInitial' => collect(),
            'typesClient' => TypeClient::where('actif', true)->orderBy('nom')->get(),
            'taxes' => Taxe::where('actif', true)->orderBy('nom')->get(),
        ]);
    }

    public function store(Request $request, DevisService $devisService): RedirectResponse
    {
        $this->nettoyerLignes($request);
        $this->bloquerRemiseSansPermission($request);

        $donnees = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'exists:produits,id'],
            'lignes.*.unite_vente_id' => ['nullable', 'exists:unite_ventes,id'],
            'lignes.*.taxe_id' => ['nullable', 'exists:taxes,id'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0.001'],
            'lignes.*.remise_type' => ['nullable', 'in:montant,pourcentage'],
            'lignes.*.remise_valeur' => ['nullable', 'integer', 'min:0', $this->remisePourcentageMax()],
        ]);

        $devis = $devisService->creer(
            Client::findOrFail($donnees['client_id']),
            $request->user(),
            $donnees['lignes'],
        );

        return redirect()->route('devis.show', $devis)->with('succes', "Devis « {$devis->numero} » créé.");
    }

    public function show(Devis $devis, StockService $stockService): View
    {
        $devis->load(['client', 'auteur', 'lignes.produit', 'lignes.uniteVente', 'lignes.taxe', 'vente']);

        $sessionOuverte = SessionCaisse::where('caissier_id', request()->user()->id)
            ->whereNull('date_fermeture')
            ->whereNull('date_cloture')
            ->with('caisse')
            ->first();

        // Stock par lieu, purement informatif à côté de chaque ligne — un
        // devis ne réserve rien (règle 15), donc jamais stocké, toujours
        // recalculé à l'affichage.
        $stocksParProduit = $devis->lignes->pluck('produit')->unique('id')
            ->mapWithKeys(fn ($produit) => [$produit->id => $stockService->disponibiliteParMagasin($produit)]);

        // Le bouton "Imprimer" imprime en place (voir devis/show.blade.php,
        // #factureImprimable) plutôt que de rediriger vers devis.facture —
        // mêmes données que chargerDonneesFacture() pour un rendu identique.
        $parametre = Parametre::actuel();

        return view('devis.show', [
            'devis' => $devis,
            'montants' => $devis->calculerMontants(),
            'sessionOuverte' => $sessionOuverte,
            'lignesEnRupture' => $devis->peutEtreTransforme() ? $devis->lignesEnRuptureDeStock() : collect(),
            'stocksParProduit' => $stocksParProduit,
            'parametre' => $parametre,
            'logoDataUri' => $parametre->logoDataUri(),
        ]);
    }

    public function edit(Devis $devis): View
    {
        abort_unless($devis->peutEtreModifie(), 403, "Le devis « {$devis->numero} » n'est plus modifiable.");

        $devis->load(['lignes.produit', 'lignes.uniteVente']);

        $panierInitial = $devis->lignes->map(fn ($ligne) => [
            'produit_id' => $ligne->produit_id,
            'unite_vente_id' => $ligne->unite_vente_id,
            'taxe_id' => $ligne->taxe_id ?? '',
            'produitLibelle' => $ligne->produit->libelle_affichage,
            'uniteLibelle' => $ligne->uniteVente?->libelle,
            'facteur' => $ligne->uniteVente?->facteur ?? 1,
            'quantite' => (float) $ligne->quantite,
            'prixUnitaire' => $ligne->uniteVente?->prix ?? $ligne->produit->prix_piece,
            'remise_type' => $ligne->remise_type ?? '',
            'remise_valeur' => $ligne->remise_valeur,
        ])->values();

        return view('devis.edit', [
            'devis' => $devis,
            'produits' => Produit::catalogueVente(),
            'panierInitial' => $panierInitial,
            'taxes' => Taxe::where('actif', true)->orderBy('nom')->get(),
        ]);
    }

    public function update(Request $request, Devis $devis, DevisService $devisService): RedirectResponse
    {
        $this->nettoyerLignes($request);
        $this->bloquerRemiseSansPermission($request);

        $donnees = $request->validate([
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'exists:produits,id'],
            'lignes.*.unite_vente_id' => ['nullable', 'exists:unite_ventes,id'],
            'lignes.*.taxe_id' => ['nullable', 'exists:taxes,id'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0.001'],
            'lignes.*.remise_type' => ['nullable', 'in:montant,pourcentage'],
            'lignes.*.remise_valeur' => ['nullable', 'integer', 'min:0', $this->remisePourcentageMax()],
        ]);

        try {
            $devisService->modifier($devis, $donnees['lignes']);
        } catch (DevisNonModifiableException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        return redirect()->route('devis.show', $devis)->with('succes', 'Devis mis à jour.');
    }

    public function annuler(Devis $devis, DevisService $devisService): RedirectResponse
    {
        try {
            $devisService->annuler($devis);
        } catch (DevisNonModifiableException $e) {
            return back()->with('erreur', $e->getMessage());
        }

        return redirect()->route('devis.show', $devis)->with('succes', 'Devis annulé.');
    }

    /**
     * Version imprimable, à l'écran : mêmes données que le PDF (voir
     * chargerDonneesFacture()), pour un rendu identique entre « voir/
     * imprimer » et « télécharger en PDF ».
     */
    public function facture(Devis $devis): View
    {
        return view('devis.facture', $this->chargerDonneesFacture($devis));
    }

    public function pdf(Devis $devis): Response
    {
        $pdf = Pdf::loadView('devis.facture', $this->chargerDonneesFacture($devis) + ['pourPdf' => true]);

        // ?imprimer=1 (voir x-bouton-imprimer) : ouvre le PDF dans l'onglet
        // au lieu de forcer un téléchargement, pour que le bouton "Imprimer"
        // imprime exactement le même rendu que "Télécharger en PDF" (même
        // mécanisme que CommandeAchatController::pdf()).
        $nomFichier = "devis-{$devis->numero}.pdf";

        return request()->boolean('imprimer') ? $pdf->stream($nomFichier) : $pdf->download($nomFichier);
    }

    public function excel(Devis $devis): StreamedResponse
    {
        $devis->load(['client', 'lignes.produit', 'lignes.uniteVente', 'lignes.taxe']);
        $montants = $devis->calculerMontants();

        $spreadsheet = new Spreadsheet();
        $feuille = $spreadsheet->getActiveSheet();
        $feuille->setTitle('Devis');

        $parametre = Parametre::actuel();
        $feuille->setCellValue('A1', $parametre->nom);
        $feuille->setCellValue('A2', $parametre->adresse);
        $feuille->setCellValue('A3', $parametre->numero ? 'Tél : '.$parametre->numero : '');

        $feuille->setCellValue('D1', 'DEVIS');
        $feuille->setCellValue('D2', 'N° '.$devis->numero);
        $feuille->setCellValue('D3', 'Date : '.$devis->created_at->format('d/m/Y'));
        $feuille->setCellValue('D4', "Valide jusqu'au : ".$devis->date_validite->format('d/m/Y'));

        $feuille->setCellValue('A6', 'Client');
        $feuille->setCellValue('A7', $devis->client->nom);
        $feuille->setCellValue('A8', $devis->client->telephone);
        $feuille->setCellValue('A9', $devis->client->adresse);

        $ligneEnTete = 11;
        foreach (['A' => 'Désignation', 'B' => 'Unité', 'C' => 'Quantité', 'D' => 'Prix unitaire', 'E' => 'Remise', 'F' => 'Taxe', 'G' => 'Total'] as $colonne => $libelle) {
            $feuille->setCellValue("{$colonne}{$ligneEnTete}", $libelle);
        }

        $ligne = $ligneEnTete + 1;
        foreach ($devis->lignes as $ligneDevis) {
            $prixUnitaire = $ligneDevis->uniteVente->prix ?? $ligneDevis->produit->prix_piece;
            $sousTotalLigne = Arrondi::entier($prixUnitaire * (float) $ligneDevis->quantite);
            $remiseLigne = Remise::resoudre($ligneDevis->remise_type, $ligneDevis->remise_valeur, $sousTotalLigne);

            $feuille->setCellValue("A{$ligne}", $ligneDevis->produit->libelle_affichage);
            $feuille->setCellValue("B{$ligne}", $ligneDevis->uniteVente->libelle ?? $ligneDevis->produit->unite_base_libelle);
            $feuille->setCellValue("C{$ligne}", (float) $ligneDevis->quantite);
            $feuille->setCellValue("D{$ligne}", $prixUnitaire);
            $feuille->setCellValue("E{$ligne}", $remiseLigne);
            $feuille->setCellValue("F{$ligne}", $ligneDevis->taxe->nom ?? '—');
            $feuille->setCellValue("G{$ligne}", $sousTotalLigne - $remiseLigne);
            $ligne++;
        }

        $ligne++;
        $feuille->setCellValue("F{$ligne}", 'Total HT');
        $feuille->setCellValue("G{$ligne}", $montants['sous_total']);
        if ($montants['total_taxes'] > 0) {
            $ligne++;
            $feuille->setCellValue("F{$ligne}", 'Total taxes');
            $feuille->setCellValue("G{$ligne}", $montants['total_taxes']);
        }
        $ligne++;
        $feuille->setCellValue("F{$ligne}", 'Total net');
        $feuille->setCellValue("G{$ligne}", $montants['total_net']);

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $colonne) {
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }

        $nomFichier = "devis-{$devis->numero}.xlsx";
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $nomFichier, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Logo encodé en data URI (plutôt qu'une URL) : dompdf ne sait charger
     * une image distante que si enable_remote est activé côté serveur — un
     * data URI fonctionne partout, sans dépendre de cette configuration, et
     * rend identiquement à l'écran et dans le PDF.
     */
    private function chargerDonneesFacture(Devis $devis): array
    {
        $devis->load(['client', 'lignes.produit', 'lignes.uniteVente', 'lignes.taxe']);

        $parametre = Parametre::actuel();

        return [
            'devis' => $devis,
            'montants' => $devis->calculerMontants(),
            'parametre' => $parametre,
            'logoDataUri' => $parametre->logoDataUri(),
        ];
    }

    private function nettoyerLignes(Request $request): void
    {
        $lignes = collect($request->input('lignes', []))->map(function (array $ligne) {
            $ligne['unite_vente_id'] = ($ligne['unite_vente_id'] ?? null) ?: null;
            $ligne['taxe_id'] = ($ligne['taxe_id'] ?? null) ?: null;
            $ligne['remise_type'] = ($ligne['remise_type'] ?? null) ?: null;
            $ligne['remise_valeur'] = ($ligne['remise_valeur'] ?? null) ?: null;
            $ligne['quantite'] = Decimal::normaliser($ligne['quantite'] ?? null);

            return $ligne;
        })->all();

        $request->merge(['lignes' => $lignes]);
    }
}
