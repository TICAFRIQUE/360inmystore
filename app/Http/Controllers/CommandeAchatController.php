<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\TrieListe;
use App\Models\CommandeAchat;
use App\Models\EcritureCompteFournisseur;
use App\Models\Fournisseur;
use App\Models\Magasin;
use App\Models\MoyenPaiement;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Stock;
use App\Models\Taxe;
use App\Models\UniteVente;
use App\Services\AchatService;
use App\Services\ReceptionAchatService;
use App\Support\Arrondi;
use App\Support\Decimal;
use App\Support\NumeroDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommandeAchatController extends Controller
{
    use TrieListe, ExporteListe;

    private const ENTETES_EXPORT_LISTE = ['Numéro', 'Fournisseur', 'Date', 'Statut', 'Type', 'Réception', 'Retours', 'Montant facturé', 'Déjà réglé', 'Reste à régler'];

    public function index(Request $request): View
    {
        [$debut, $fin] = $this->periodeListe($request);

        $commandes = $this->requeteListe($request)->paginate(20)->withQueryString();

        return view('commande-achats.index', [
            'commandes' => $commandes,
            'receptionIncomplete' => $request->boolean('reception_incomplete'),
            'dateDebut' => $debut->toDateString(),
            'dateFin' => $fin->toDateString(),
        ]);
    }

    public function listePdf(Request $request): Response
    {
        return $this->pdfDepuisListe('Bons de commande', self::ENTETES_EXPORT_LISTE, $this->lignesExport($request), 'bons-de-commande.pdf', $this->sousTitrePeriode($request));
    }

    public function listeExcel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe('Bons de commande', self::ENTETES_EXPORT_LISTE, $this->lignesExport($request), 'bons-de-commande.xlsx');
    }

    /**
     * Période affichée par la liste : mois en cours tant qu'aucune date
     * n'est choisie.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodeListe(Request $request): array
    {
        return [
            $request->filled('date_debut') ? Carbon::parse($request->string('date_debut')) : now()->startOfMonth(),
            $request->filled('date_fin') ? Carbon::parse($request->string('date_fin')) : now()->endOfMonth(),
        ];
    }

    private function sousTitrePeriode(Request $request): string
    {
        [$debut, $fin] = $this->periodeListe($request);

        return 'Du '.$debut->format('d/m/Y').' au '.$fin->format('d/m/Y');
    }

    /**
     * Requête de la liste (filtres + tri), partagée par l'écran et ses exports :
     * un export porte toujours sur ce que l'écran affiche, sans la pagination.
     */
    private function requeteListe(Request $request): Builder
    {
        [$debut, $fin] = $this->periodeListe($request);

        $query = CommandeAchat::query()
            // lignes.taxe/paiements/reglementsFournisseur/retours : nécessaires à
            // CommandeAchat::totalTtc()/montantRegle()/resteDu() (colonnes
            // Montant facturé/Déjà réglé/Reste à régler — resteDu() déduit aussi les
            // retours déjà enregistrés sur cette commande), chargées ici pour
            // éviter un N+1 sur chaque ligne de la page. receptions.lignes :
            // nécessaires à totalTtcReel() (montant réel). lignes.receptions
            // (chemin inverse, distinct du précédent) : nécessaires à
            // quantiteRecuePieces()/tauxCompletion() (colonne Réception) —
            // voir les docblocks de CommandeAchat pour le détail de chaque
            // chemin d'eager loading.
            ->with(['fournisseur', 'lignes.taxe', 'lignes.receptions.taxe', 'paiements', 'reglementsFournisseur', 'receptions.lignes.taxe', 'retours'])
            ->whereBetween('date_commande', [$debut->toDateString(), $fin->toDateString()])
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where('numero', 'like', "%{$recherche}%");
            })
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->boolean('reception_incomplete'), fn ($q) => $q->receptionIncomplete());

        return $this->appliquerTri($query, $request, ['numero', 'date_commande', 'statut'], 'created_at');
    }

    private function lignesExport(Request $request): Collection
    {
        return $this->requeteListe($request)->get()->map(function (CommandeAchat $commande) {
            // Comme à l'écran : réception et montants n'ont de sens qu'une
            // fois le bon de commande validé.
            $validee = $commande->statut === 'validee';

            return [
                $commande->numero,
                $commande->fournisseur->nom,
                $commande->date_commande->format('d/m/Y'),
                $validee ? 'Validée' : 'Brouillon',
                $commande->type === 'achat_direct' ? 'Achat direct' : 'Bon de commande',
                $validee
                    ? quantite($commande->quantiteRecuePieces()).'/'.quantite($commande->quantiteCommandeePieces()).' · '.$commande->tauxCompletion().' %'
                    : '—',
                $commande->retours->isNotEmpty()
                    ? $commande->retours->count().' · '.montant($commande->retours->sum('montant_total'))
                    : '—',
                $validee ? montant($commande->totalTtcReel()) : '—',
                $validee ? montant($commande->montantRegle()) : '—',
                $validee ? montant($commande->resteDu()) : '—',
            ];
        });
    }

    public function create(): View
    {
        $produits = Produit::where('actif', true)->orderBy('nom')
            ->with(['uniteBase', 'uniteVentes' => fn ($q) => $q->where('actif', true)->with('unite')])
            ->get(['id', 'sku', 'nom', 'libelle_distinctif', 'unite_base_id']);

        return view('commande-achats.create', [
            'fournisseurs' => Fournisseur::where('actif', true)->orderBy('nom')->get(),
            // Solde en un seul agrégat (pas de N+1 par fournisseur) : un
            // solde négatif est un avoir, affiché à la saisie pour rappeler
            // qu'il se déduira automatiquement de la dette du nouvel achat.
            'fournisseurSoldes' => EcritureCompteFournisseur::selectRaw('fournisseur_id, SUM(montant) as solde')->groupBy('fournisseur_id')->pluck('solde', 'fournisseur_id'),
            'magasins' => Magasin::where('actif', true)->orderBy('nom')->get(),
            'taxes' => Taxe::where('actif', true)->orderBy('nom')->get(),
            'moyensPaiement' => MoyenPaiement::actifs(),
            'produits' => $produits,
            // Unités disponibles par produit (base + variantes déjà définies
            // au catalogue de vente) : évite de ressaisir un facteur à
            // l'achat qui pourrait diverger de celui utilisé à la vente.
            // Libellé affiché volontairement sans le facteur (nom complet +
            // abréviation entre parenthèses, ex. "Boîte (Bte)") — le facteur
            // reste utilisé en interne pour convertir en pièces, jamais
            // montré à la saisie.
            'unitesParProduit' => $produits->mapWithKeys(fn (Produit $p) => [
                $p->id => [
                    'basePiece' => $p->uniteBase?->nom_avec_abbreviation ?? 'pièce',
                    'variantes' => $p->uniteVentes->map(fn (UniteVente $uv) => [
                        'id' => $uv->id,
                        'libelle' => $uv->unite->nom_avec_abbreviation,
                        'facteur' => $uv->facteur,
                    ])->values(),
                ],
            ]),
            // Stock actuel, tous magasins/dépôts confondus (pas la
            // destination de la ligne, pas encore choisie à ce stade) —
            // simple repère pour aider à décider combien recommander, même
            // agrégat que le sélecteur produit de la vente (VenteController).
            'stocksParProduit' => Stock::selectRaw('produit_id, SUM(quantite) as total')
                ->groupBy('produit_id')
                ->pluck('total', 'produit_id'),
            'peutValider' => request()->user()->can('achat.valider'),
            'peutReceptionner' => request()->user()->can('achat.receptionner'),
        ]);
    }

    public function store(Request $request, AchatService $achatService, ReceptionAchatService $receptionService): RedirectResponse
    {
        $donnees = $request->validate([
            'numero' => ['nullable', 'string', 'max:255', 'unique:commande_achats,numero'],
            'fournisseur_id' => ['required', 'exists:fournisseurs,id'],
            'date_commande' => ['required', 'date'],
            'action' => ['required', 'in:brouillon,recevoir'],
        ]);

        // Un select laissé sur son option vide envoie "" (chaîne), pas
        // l'absence du champ : "nullable" ne l'exempte pas de la règle
        // "exists" (qui ne s'applique qu'à une valeur réellement null), donc
        // sans cette normalisation AVANT validate(), une unité/taxe non
        // choisie échouait avec "La valeur sélectionnée ... est invalide."
        $this->nettoyerLignes($request);

        // Pas de contrainte "distinct" sur produit_id seul : deux lignes du
        // même produit avec une unité de vente différente (pièce vs carton)
        // sont légitimes, seul le doublon (produit + unité) est bloqué côté
        // client (voir estDoublon dans commande-achats/create.blade.php),
        // même logique que le panier de vente.
        $lignes = $request->validate([
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'exists:produits,id'],
            'lignes.*.unite_vente_id' => ['nullable', 'exists:unite_ventes,id'],
            'lignes.*.taxe_id' => ['nullable', 'exists:taxes,id'],
            'lignes.*.magasin_destination_id' => [Rule::requiredIf($donnees['action'] === 'recevoir'), 'nullable', 'exists:magasins,id'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0.001'],
            // Prix indicatif, jamais arrondi à l'entier (voir CLAUDE.md) :
            // 'decimal:0,2' borne à 2 décimales max, comme la colonne
            // decimal(14,2) — le prix RÉELLEMENT facturé, capturé à la
            // réception (prix_achat_reel), reste lui entier.
            'lignes.*.prix_achat' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
        ])['lignes'];

        $receptionnerImmediatement = $donnees['action'] === 'recevoir';
        abort_if($receptionnerImmediatement && ! ($request->user()->can('achat.valider') && $request->user()->can('achat.receptionner')), 403);
        unset($donnees['action']);

        $paiements = [];
        $numeroFactureFournisseur = null;
        $numeroBonLivraisonFournisseur = null;
        if ($receptionnerImmediatement) {
            $donneesReception = $request->validate([
                'paiements' => ['sometimes', 'array'],
                'paiements.*.moyen_paiement_id' => ['required_with:paiements', 'exists:moyen_paiements,id'],
                'paiements.*.montant' => ['required_with:paiements', 'integer', 'min:1'],
                'numero_facture_fournisseur' => ['nullable', 'string', 'max:255'],
                'numero_bon_livraison_fournisseur' => ['nullable', 'string', 'max:255'],
            ]);
            $paiements = $donneesReception['paiements'] ?? [];
            $numeroFactureFournisseur = $donneesReception['numero_facture_fournisseur'] ?? null;
            $numeroBonLivraisonFournisseur = $donneesReception['numero_bon_livraison_fournisseur'] ?? null;
        }

        $numeroGenere = blank($donnees['numero']);
        if ($numeroGenere) {
            // Placeholder temporaire, unique, jamais visible : la contrainte
            // NOT NULL + unique sur `numero` empêche d'insérer d'abord un
            // numéro vide, donc on écrase ce placeholder juste après la
            // création par le numéro séquentiel basé sur l'id réel (voir
            // genererNumeroSequentiel() ci-dessous).
            $donnees['numero'] = $this->genererNumeroTemporaire();
        }

        try {
            $commande = DB::transaction(function () use ($donnees, $lignes, $paiements, $numeroFactureFournisseur, $numeroBonLivraisonFournisseur, $receptionnerImmediatement, $numeroGenere, $request, $achatService, $receptionService) {
                $commande = CommandeAchat::create($donnees + [
                    'statut' => 'brouillon',
                    'type' => $receptionnerImmediatement ? 'achat_direct' : 'commande',
                    'created_by' => $request->user()->id,
                ]);

                if ($numeroGenere) {
                    $commande->update(['numero' => $this->genererNumeroSequentiel($commande)]);
                }

                foreach ($lignes as $ligne) {
                    $commande->lignes()->create($ligne);
                }

                if (! $receptionnerImmediatement) {
                    return $commande;
                }

                $commande = $achatService->valider($commande, $request->user());

                $lignesReception = $commande->lignes->map(fn ($l) => [
                    'ligne_commande_achat_id' => $l->id,
                    'magasin_id' => $l->magasin_destination_id,
                    'quantite_pieces' => $l->quantite_pieces,
                    // prix_achat_reel reste entier (réception réelle, voir
                    // CLAUDE.md) même si le prix indicatif de la ligne, lui,
                    // peut désormais porter des centimes.
                    'prix_achat_reel' => Arrondi::entier($l->prixAchatParPiece()),
                ])->all();

                $receptionService->receptionner($commande, $lignesReception, $request->user(), $paiements, numeroFactureFournisseur: $numeroFactureFournisseur, numeroBonLivraisonFournisseur: $numeroBonLivraisonFournisseur);

                return $commande;
            });
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        if ($receptionnerImmediatement) {
            return redirect()->route('commande-achats.show', $commande)
                ->with('succes', "Achat enregistré et réceptionné : le stock et le compte fournisseur ont été mis à jour.");
        }

        $message = "Bon de commande créé.".($numeroGenere ? " Numéro généré automatiquement : {$commande->numero}." : '');

        return redirect()->route('commande-achats.show', $commande)->with('succes', $message);
    }

    public function show(CommandeAchat $commandeAchat): View
    {
        $commandeAchat->load([
            'fournisseur', 'lignes.produit', 'lignes.uniteVente.unite', 'lignes.taxe', 'lignes.magasinDestination',
            'lignes.receptions',
            'paiements.moyenPaiement', 'reglementsFournisseur.paiements.moyenPaiement', 'reglementsFournisseur.auteur',
            'retours.lignes.produit', 'retours.auteur',
            'receptions.lignes.produit', 'receptions.lignes.magasin', 'receptions.lignes.taxe',
            'receptions.paiements.moyenPaiement', 'receptions.auteur',
            'auteur', 'validateur', 'annulateur',
        ]);

        return view('commande-achats.show', [
            'commande' => $commandeAchat,
            'peutValider' => request()->user()->can('achat.valider'),
            'peutAnnuler' => request()->user()->can('achat.annuler'),
            'peutRegler' => request()->user()->can('fournisseur.reglement'),
            'peutRetourner' => request()->user()->can('achat.retour'),
            'peutReceptionner' => request()->user()->can('achat.receptionner'),
            'lignesRetournables' => $this->calculerLignesRetournables($commandeAchat),
            'dejaRecuParLigne' => $this->calculerDejaRecuParLigne($commandeAchat),
            'moyensPaiement' => MoyenPaiement::actifs(),
            'magasins' => Magasin::where('actif', true)->orderBy('nom')->get(),
        ]);
    }

    /**
     * Une "ligne retournable" par (ligne de commande × magasin ayant
     * effectivement reçu quelque chose), pas juste par ligne — une commande
     * sans réception (ancien modèle) n'a qu'un seul magasin possible (celui
     * de la ligne, la totalité y a été reçue à la validation) ; une commande
     * avec réceptions (nouveau modèle) peut avoir reçu une même ligne à
     * plusieurs destinations (magasin choisi à chaque réception), chacune
     * devenant sa propre ligne retournable avec son propre plafond — voir
     * RetourAchatService, même logique de calcul. Suppose `lignes`,
     * `retours.lignes`, `receptions.lignes.magasin` chargées.
     *
     * @return \Illuminate\Support\Collection<int, object{ligne: \App\Models\LigneCommandeAchat, magasin: Magasin, quantiteRecue: float, dejaRetourne: float, reste: float}>
     */
    private function calculerLignesRetournables(CommandeAchat $commandeAchat): \Illuminate\Support\Collection
    {
        $hasReceptions = $commandeAchat->receptions->isNotEmpty();

        // Commande validée mais jamais réceptionnée (cas normal, réception
        // différée) : rien n'a réellement été reçu, donc rien à retourner —
        // à distinguer de l'ancien modèle (tout reçu à la validation), seul
        // repérable via un vrai MouvementStock direct. Voir même garde dans
        // RetourAchatService::retourner().
        if (! $hasReceptions && ! $commandeAchat->aDesMouvementsStockDirects()) {
            return collect();
        }

        $dejaRetourneParClef = $commandeAchat->retours
            ->flatMap(fn ($retour) => $retour->lignes)
            ->groupBy(fn ($l) => "{$l->ligne_commande_achat_id}-{$l->magasin_id}")
            ->map(fn ($lignes) => (float) $lignes->sum('quantite_pieces'));

        $recuParClef = $commandeAchat->receptions->flatMap->lignes
            ->groupBy(fn ($l) => "{$l->ligne_commande_achat_id}-{$l->magasin_id}");

        $lignesRetournables = collect();

        foreach ($commandeAchat->lignes as $ligne) {
            $groupesAConsiderer = $hasReceptions
                ? $recuParClef->filter(fn ($_, $clef) => str_starts_with($clef, "{$ligne->id}-"))
                : collect(["{$ligne->id}-{$ligne->magasin_destination_id}" => collect()]);

            foreach ($groupesAConsiderer as $clef => $lignesRecues) {
                if ($hasReceptions) {
                    $magasin = $lignesRecues->first()->magasin;
                    $quantiteRecue = (float) $lignesRecues->sum('quantite_pieces');
                } else {
                    $magasin = $ligne->magasinDestination;
                    $quantiteRecue = (float) $ligne->quantite_pieces;
                }

                $dejaRetourne = (float) ($dejaRetourneParClef[$clef] ?? 0);
                $reste = $quantiteRecue - $dejaRetourne;

                if ($reste > 0) {
                    $lignesRetournables->push((object) [
                        'ligne' => $ligne,
                        'magasin' => $magasin,
                        'quantiteRecue' => $quantiteRecue,
                        'dejaRetourne' => $dejaRetourne,
                        'reste' => $reste,
                    ]);
                }
            }
        }

        return $lignesRetournables;
    }

    /**
     * Déjà reçu par ligne de commande (quantite_pieces cumulée, toutes
     * réceptions confondues), quelle que soit la destination — utilisé pour
     * la colonne "Reçu" et le reste à recevoir. Suppose `receptions.lignes`
     * chargée.
     *
     * @return \Illuminate\Support\Collection<int, float>
     */
    private function calculerDejaRecuParLigne(CommandeAchat $commandeAchat): \Illuminate\Support\Collection
    {
        return $commandeAchat->receptions
            ->flatMap(fn ($reception) => $reception->lignes)
            ->groupBy('ligne_commande_achat_id')
            ->map(fn ($lignes) => (float) $lignes->sum('quantite_pieces'));
    }

    public function facture(CommandeAchat $commandeAchat): View
    {
        return view('commande-achats.facture', $this->chargerDonneesFacture($commandeAchat));
    }

    public function pdf(Request $request, CommandeAchat $commandeAchat): Response
    {
        $pdf = Pdf::loadView('commande-achats.facture', $this->chargerDonneesFacture($commandeAchat) + ['pourPdf' => true]);

        // ?imprimer=1 (voir x-bouton-imprimer) : ouvre le PDF dans l'onglet
        // au lieu de forcer un téléchargement, pour que le bouton "Imprimer"
        // du détail du bon d'achat ne fasse jamais naviguer vers une autre
        // page (même mécanisme que ExporteListe::pdfDepuisListe()).
        $nomFichier = "bon-de-commande-{$commandeAchat->numero}.pdf";

        return $request->boolean('imprimer') ? $pdf->stream($nomFichier) : $pdf->download($nomFichier);
    }

    public function excel(CommandeAchat $commandeAchat): StreamedResponse
    {
        $commandeAchat->load([
            'fournisseur', 'lignes.produit', 'lignes.uniteVente.unite', 'lignes.taxe', 'lignes.magasinDestination',
            'paiements.moyenPaiement', 'reglementsFournisseur', 'retours',
            'receptions.lignes.taxe', 'receptions.lignes.produit', 'receptions.lignes.magasin', 'receptions.paiements.moyenPaiement',
        ]);
        $dejaRecuParLigne = $this->calculerDejaRecuParLigne($commandeAchat);
        $aDesReceptions = $commandeAchat->receptions->isNotEmpty();

        $spreadsheet = new Spreadsheet();
        $feuille = $spreadsheet->getActiveSheet();
        $feuille->setTitle("Bon de commande");

        $parametre = Parametre::actuel();
        $feuille->setCellValue('A1', $parametre->nom);
        $feuille->setCellValue('A2', $parametre->adresse);
        $feuille->setCellValue('A3', $parametre->numero ? 'Tél : '.$parametre->numero : '');

        $feuille->setCellValue('D1', "BON DE COMMANDE");
        $feuille->setCellValue('D2', 'N° '.$commandeAchat->numero);
        $feuille->setCellValue('D3', 'Date : '.$commandeAchat->date_commande->format('d/m/Y'));

        $feuille->setCellValue('A6', 'Fournisseur');
        $feuille->setCellValue('A7', $commandeAchat->fournisseur->nom);
        $feuille->setCellValue('A8', $commandeAchat->fournisseur->telephone ?? '');
        $feuille->setCellValue('A9', $commandeAchat->fournisseur->adresse ?? '');

        $ligneEnTete = 11;
        $entetes = ['A' => 'Désignation', 'B' => 'Unité', 'C' => 'Destination', 'D' => 'Quantité', 'E' => 'Prix HT', 'F' => 'Taxe', 'G' => 'Total HT', 'H' => 'Total TTC'];
        if ($commandeAchat->statut === 'validee') {
            $entetes['I'] = 'Reçu';
        }
        foreach ($entetes as $colonne => $libelle) {
            $feuille->setCellValue("{$colonne}{$ligneEnTete}", $libelle);
        }

        $ligne = $ligneEnTete + 1;
        foreach ($commandeAchat->lignes as $ligneAchat) {
            $feuille->setCellValue("A{$ligne}", $ligneAchat->produit->libelle_affichage);
            $feuille->setCellValue("B{$ligne}", $ligneAchat->uniteVente->unite->nom_avec_abbreviation ?? $ligneAchat->produit->unite_base_libelle);
            $feuille->setCellValue("C{$ligne}", $ligneAchat->magasinDestination->nom ?? '—');
            $feuille->setCellValue("D{$ligne}", (float) $ligneAchat->quantite);
            $feuille->setCellValue("E{$ligne}", (float) $ligneAchat->prix_achat);
            $feuille->setCellValue("F{$ligne}", $ligneAchat->taxe->nom ?? '—');
            $feuille->setCellValue("G{$ligne}", $ligneAchat->montantHt());
            $feuille->setCellValue("H{$ligne}", $ligneAchat->montantTtc());
            // Prix/montants indicatifs (E, G, H) : 2 décimales, jamais
            // arrondis à l'entier — voir CLAUDE.md, contrairement au reste
            // du classeur (Reçu, totaux réels…) qui reste entier XOF.
            foreach (['E', 'G', 'H'] as $colonneIndicative) {
                $feuille->getStyle("{$colonneIndicative}{$ligne}")->getNumberFormat()->setFormatCode('#,##0.##');
            }
            if ($commandeAchat->statut === 'validee') {
                $feuille->setCellValue("I{$ligne}", ($dejaRecuParLigne[$ligneAchat->id] ?? 0).'/'.(float) $ligneAchat->quantite_pieces);
            }
            $ligne++;
        }

        $ligne++;
        $feuille->setCellValue("F{$ligne}", 'Total HT');
        $feuille->setCellValue("G{$ligne}", $commandeAchat->totalHt());
        $feuille->getStyle("G{$ligne}")->getNumberFormat()->setFormatCode('#,##0.##');
        $ligne++;
        $feuille->setCellValue("F{$ligne}", 'Total taxes');
        $feuille->setCellValue("G{$ligne}", $commandeAchat->totalTaxes());
        $feuille->getStyle("G{$ligne}")->getNumberFormat()->setFormatCode('#,##0.##');
        $ligne++;
        $feuille->setCellValue("F{$ligne}", 'Total TTC');
        $feuille->setCellValue("G{$ligne}", $commandeAchat->totalTtcReel());
        $ligne++;
        if ($aDesReceptions && $commandeAchat->ecartMontant() != 0) {
            $feuille->setCellValue("F{$ligne}", 'Total TTC indicatif (commande)');
            $feuille->setCellValue("G{$ligne}", $commandeAchat->totalTtc());
            $feuille->getStyle("G{$ligne}")->getNumberFormat()->setFormatCode('#,##0.##');
            $ligne++;
        }
        foreach ($commandeAchat->paiements as $paiement) {
            $feuille->setCellValue("F{$ligne}", $paiement->moyenPaiement->nom);
            $feuille->setCellValue("G{$ligne}", $paiement->montant);
            $ligne++;
        }
        if ($commandeAchat->montantRegle() > 0) {
            $feuille->setCellValue("F{$ligne}", 'Montant réglé');
            $feuille->setCellValue("G{$ligne}", $commandeAchat->montantRegle());
            $ligne++;
        }
        if ($commandeAchat->resteDu() > 0) {
            $feuille->setCellValue("F{$ligne}", 'Reste dû au fournisseur');
            $feuille->setCellValue("G{$ligne}", $commandeAchat->resteDu());
            $ligne++;
        }

        if ($aDesReceptions) {
            $ligne++;
            $feuille->setCellValue("A{$ligne}", 'Bons d\'achat (réceptions)');
            $ligne++;
            foreach ($commandeAchat->receptions as $reception) {
                $feuille->setCellValue("A{$ligne}", $reception->numero);
                $feuille->setCellValue("B{$ligne}", $reception->created_at->format('d/m/Y'));
                $feuille->setCellValue("C{$ligne}", 'Montant TTC');
                $feuille->setCellValue("D{$ligne}", $reception->totalTtc());
                $references = array_filter([
                    $reception->numero_bon_livraison_fournisseur ? 'BL n° '.$reception->numero_bon_livraison_fournisseur : null,
                    $reception->numero_facture_fournisseur ? 'Facture n° '.$reception->numero_facture_fournisseur : null,
                ]);
                if (! empty($references)) {
                    $feuille->setCellValue("E{$ligne}", implode(' — ', $references));
                }
                $ligne++;
                foreach ($reception->lignes as $ligneReception) {
                    $feuille->setCellValue("A{$ligne}", '  '.$ligneReception->produit->libelle_affichage);
                    $feuille->setCellValue("B{$ligne}", $ligneReception->magasin->nom);
                    $feuille->setCellValue("C{$ligne}", (float) $ligneReception->quantite_pieces);
                    $feuille->setCellValue("D{$ligne}", $ligneReception->prix_achat_reel);
                    $ligne++;
                }
            }
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $colonne) {
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }

        return $this->telechargerTableur($spreadsheet, "bon-de-commande-{$commandeAchat->numero}.xlsx");
    }

    /**
     * receptions.lignes.taxe (uniquement, pas produit/magasin/paiements) :
     * suffisant pour totalTtcReel()/ecartMontant() (montant réel, voir
     * CommandeAchat::totalTtcReel()) — ce document n'affiche plus le détail
     * ligne par ligne des réceptions (retiré, voir facture.blade.php).
     */
    private function chargerDonneesFacture(CommandeAchat $commandeAchat): array
    {
        $commandeAchat->load([
            'fournisseur', 'lignes.produit', 'lignes.uniteVente.unite', 'lignes.taxe', 'lignes.magasinDestination',
            'paiements.moyenPaiement', 'reglementsFournisseur', 'retours', 'receptions.lignes.taxe',
        ]);

        $parametre = Parametre::actuel();

        return [
            'commande' => $commandeAchat,
            'parametre' => $parametre,
            'logoDataUri' => $parametre->logoDataUri(),
        ];
    }

    public function valider(Request $request, CommandeAchat $commandeAchat, AchatService $achatService): RedirectResponse
    {
        abort_unless($request->user()->can('achat.valider'), 403);

        try {
            $achatService->valider($commandeAchat, $request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('commande-achats.show', $commandeAchat)->with('erreur', $e->getMessage());
        }

        return redirect()->route('commande-achats.show', $commandeAchat)->with('succes', 'Commande validée : prête à être réceptionnée.');
    }

    public function destroy(Request $request, CommandeAchat $commandeAchat): RedirectResponse
    {
        abort_unless($request->user()->can('achat.annuler'), 403);

        if ($commandeAchat->statut !== 'brouillon') {
            return redirect()->route('commande-achats.index')->with('erreur', 'Seule une commande en brouillon peut être supprimée : utilisez « Annuler le bon de commande » à la place pour une commande déjà confirmée.');
        }

        $commandeAchat->delete();

        return redirect()->route('commande-achats.index')->with('succes', 'Commande supprimée.');
    }

    /**
     * Annule une commande validée (erreur de saisie, réception refusée…) —
     * pas une suppression : voir AchatService::annuler(). Le stock est
     * remis à jour automatiquement, échoue proprement si une partie a déjà
     * été consommée ailleurs.
     */
    public function annuler(Request $request, CommandeAchat $commandeAchat, AchatService $achatService): RedirectResponse
    {
        abort_unless($request->user()->can('achat.annuler'), 403);

        $donnees = $request->validate([
            'motif' => ['required', 'string', 'max:500'],
        ]);

        try {
            $achatService->annuler($commandeAchat, $request->user(), $donnees['motif']);
        } catch (RuntimeException $e) {
            return redirect()->route('commande-achats.show', $commandeAchat)->with('erreur', $e->getMessage());
        }

        return redirect()->route('commande-achats.index')->with('succes', "Commande annulée : le stock a été mis à jour.");
    }

    /**
     * Placeholder temporaire, unique, jamais visible par l'utilisateur — voir
     * store(), remplacé juste après l'insertion par genererNumeroSequentiel().
     */
    private function genererNumeroTemporaire(): string
    {
        return NumeroDocument::genererUnique('BC', CommandeAchat::class);
    }

    /**
     * Numéro séquentiel lisible (BC-00001, BC-00002…, s'allonge naturellement
     * au-delà de 99999) basé sur l'id auto-incrémenté réel de la commande —
     * garanti unique et sans collision par la BDD elle-même (pas de compteur
     * séparé à maintenir), donc jamais appelé avant la création de la ligne.
     */
    private function genererNumeroSequentiel(CommandeAchat $commande): string
    {
        return 'BC-'.str_pad((string) $commande->id, 5, '0', STR_PAD_LEFT);
    }

    private function nettoyerLignes(Request $request): void
    {
        $lignes = collect($request->input('lignes', []))->map(function (array $ligne) {
            $ligne['unite_vente_id'] = ($ligne['unite_vente_id'] ?? null) ?: null;
            $ligne['taxe_id'] = ($ligne['taxe_id'] ?? null) ?: null;
            $ligne['quantite'] = Decimal::normaliser($ligne['quantite'] ?? null);
            $ligne['prix_achat'] = Decimal::normaliser($ligne['prix_achat'] ?? null);

            return $ligne;
        })->all();

        $request->merge(['lignes' => $lignes]);
    }
}
