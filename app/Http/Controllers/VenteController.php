<?php

namespace App\Http\Controllers;

use App\Enums\EcritureCompteClientType;
use App\Enums\MouvementStockType;
use App\Enums\VenteType;
use App\Exceptions\DevisNonTransformableException;
use App\Exceptions\LimiteCreditDepasseeException;
use App\Exceptions\SessionNonOuverteException;
use App\Exceptions\StockInsuffisantException;
use App\Http\Controllers\Concerns\AutoriseMagasin;
use App\Http\Controllers\Concerns\AutoriseVenteEnAttente;
use App\Http\Controllers\Concerns\ValideRemises;
use App\Models\Client;
use App\Models\Devis;
use App\Models\EcritureCompteClient;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\SessionCaisse;
use App\Models\Stock;
use App\Models\Taxe;
use App\Models\TypeClient;
use App\Models\User;
use App\Models\Vente;
use App\Models\VenteEnAttente;
use App\Notifications\VenteSignalee;
use App\Services\CompteClientService;
use App\Services\DevisService;
use App\Services\StockService;
use App\Services\VenteService;
use App\Support\Decimal;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VenteController extends Controller
{
    use ValideRemises, AutoriseVenteEnAttente, AutoriseMagasin;

    public function create(SessionCaisse $session): View
    {
        return $this->formulaire($session);
    }

    /**
     * Reprendre une vente en attente n'est pas un flux à part : c'est le même
     * écran de création de vente, avec le panier déjà rempli des lignes
     * existantes — comme si on rouvrait la vente pour la modifier avant de la
     * finaliser (prix courant, pas de figeage à la mise en attente).
     */
    public function reprendre(VenteEnAttente $venteEnAttente): View
    {
        $this->assurerProprietaireOuGerant($venteEnAttente);

        $venteEnAttente->load(['lignes.produit', 'lignes.uniteVente', 'lignes.magasinSource', 'sessionCaisse']);

        return $this->formulaire($venteEnAttente->sessionCaisse, $venteEnAttente);
    }

    /**
     * Transformer un devis n'est pas non plus un flux à part : même écran de
     * caisse, panier pré-rempli des lignes du devis (montants indicatifs
     * repris au prix courant), mais le client est imposé par le devis — pas
     * de sélecteur (voir CLAUDE.md, section Devis, et règle 15).
     */
    public function transformerDevisForm(SessionCaisse $session, Devis $devis): View
    {
        $this->assurerMagasin($session->caisse->magasin_id);
        abort_if(! $devis->peutEtreTransforme(), 403, 'Ce devis ne peut plus être transformé en vente.');

        $devis->load(['client', 'lignes.produit', 'lignes.uniteVente']);

        return $this->formulaire($session, devisTransformation: $devis);
    }

    private function formulaire(SessionCaisse $session, ?VenteEnAttente $venteEnAttente = null, ?Devis $devisTransformation = null): View
    {
        $this->assurerMagasin($session->caisse->magasin_id);
        abort_if($session->date_cloture || $session->date_fermeture, 403, 'Cette session n\'est plus ouverte.');

        // Le sélecteur de produit (en haut du panier) est une pré-vérification
        // rapide, pas la vérification qui bloque réellement la vente : la
        // source de prélèvement est choisie plus tard, par ligne, parmi
        // n'importe quel magasin ou dépôt (voir choix obligatoire de la
        // source). Un stock nul dans le SEUL magasin de la caisse ne doit
        // donc pas y griser un produit encore disponible ailleurs — on
        // additionne le stock de toutes les sources confondues.
        $stocksParProduit = Stock::selectRaw('produit_id, SUM(quantite) as total')
            ->groupBy('produit_id')
            ->pluck('total', 'produit_id');

        // Le catalogue (données de référence) est mis en cache ; le stock,
        // dérivé des mouvements et donc jamais figé, est toujours recalculé
        // en direct puis fusionné à l'affichage.
        $produits = Produit::catalogueVente()
            ->map(fn (array $p) => [
                ...$p,
                'stock' => (float) ($stocksParProduit[$p['id']] ?? 0),
            ])
            ->values();

        // Un échec de soumission (validation ou exception métier, voir
        // store()/transformerDevis()/reprendre()) redirige ici avec
        // back()->withInput() : si un panier a été flashé, on reconstruit
        // le panier affiché à partir de CETTE saisie plutôt que de repartir
        // du devis/panier en attente d'origine — sinon chaque échec effaçait
        // silencieusement tous les choix déjà faits (destination, quantités).
        $panierInitial = match (true) {
            session()->hasOldInput('lignes') => collect(old('lignes'))->map(function (array $ligne) use ($produits) {
                $produit = $produits->firstWhere('id', (int) $ligne['produit_id']);
                $uniteVenteId = ($ligne['unite_vente_id'] ?? '') !== '' ? (int) $ligne['unite_vente_id'] : null;
                $unite = $uniteVenteId ? collect($produit['unites'] ?? [])->firstWhere('id', $uniteVenteId) : null;

                $donnees = [
                    'produit_id' => (int) $ligne['produit_id'],
                    'unite_vente_id' => $uniteVenteId,
                    'taxe_id' => ($ligne['taxe_id'] ?? '') !== '' ? (int) $ligne['taxe_id'] : '',
                    'produitLibelle' => $produit['libelle_affichage'] ?? '',
                    'uniteLibelle' => $unite['libelle'] ?? null,
                    'facteur' => $unite['facteur'] ?? 1,
                    'quantite' => (float) $ligne['quantite'],
                    'prixUnitaire' => $unite['prix'] ?? ($produit['prix_piece'] ?? 0),
                    'remise_type' => $ligne['remise_type'] ?? '',
                    'remise_valeur' => ($ligne['remise_valeur'] ?? '') !== '' ? (int) $ligne['remise_valeur'] : null,
                ];

                // Absent plutôt que null : côté JS, seule une clé manquante se
                // lit comme "aucun lieu choisi" (voir posApp, magasin_source_id).
                if (($ligne['magasin_source_id'] ?? '') !== '') {
                    $donnees['magasin_source_id'] = (int) $ligne['magasin_source_id'];
                }

                return $donnees;
            })->values(),
            $devisTransformation !== null => $devisTransformation->lignes->map(fn ($ligne) => [
                'produit_id' => $ligne->produit_id,
                'unite_vente_id' => $ligne->unite_vente_id,
                // Contrairement au prix/à la remise (indicatifs, recalculés au
                // catalogue courant), la taxe est un choix explicite du
                // vendeur : reprise telle quelle du devis (règle 15), modifiable
                // avant finalisation comme n'importe quelle ligne de vente.
                'taxe_id' => $ligne->taxe_id ?? '',
                'produitLibelle' => $ligne->produit->libelle_affichage,
                'uniteLibelle' => $ligne->uniteVente?->libelle,
                'facteur' => $ligne->uniteVente?->facteur ?? 1,
                'quantite' => (float) $ligne->quantite,
                'prixUnitaire' => $ligne->uniteVente?->prix ?? $ligne->produit->prix_piece,
                // Remise indicative du devis reprise comme point de départ,
                // modifiable avant finalisation (jamais figée, règle 15).
                'remise_type' => $ligne->remise_type ?? '',
                'remise_valeur' => $ligne->remise_valeur,
            ])->values(),
            $venteEnAttente !== null => $venteEnAttente->lignes->map(function ($ligne) {
                $donnees = [
                    'produit_id' => $ligne->produit_id,
                    'unite_vente_id' => $ligne->unite_vente_id,
                    'taxe_id' => '',
                    'produitLibelle' => $ligne->produit->libelle_affichage,
                    'uniteLibelle' => $ligne->uniteVente?->libelle,
                    'facteur' => $ligne->uniteVente?->facteur ?? 1,
                    'quantite' => (float) $ligne->quantite,
                    'prixUnitaire' => $ligne->uniteVente?->prix ?? $ligne->produit->prix_piece,
                    'remise_type' => '',
                    'remise_valeur' => null,
                ];

                // Absent plutôt que null si aucun lieu n'a été choisi avant la
                // mise en attente (choix optionnel à ce stade, voir
                // VenteEnAttenteController) : côté JS, seule une clé absente
                // se lit comme "non choisi" (voir posApp, magasin_source_id).
                if ($ligne->magasin_source_id !== null) {
                    $donnees['magasin_source_id'] = $ligne->magasin_source_id;
                    $donnees['magasinSourceNom'] = $ligne->magasinSource?->nom;
                }

                return $donnees;
            })->values(),
            default => collect(),
        };

        return view('ventes.create', [
            'session' => $session,
            'produits' => $produits,
            'taxes' => Taxe::where('actif', true)->orderBy('nom')->get(),
            'moyensPaiement' => MoyenPaiement::actifs(),
            'venteEnAttente' => $venteEnAttente,
            'devisTransformation' => $devisTransformation,
            'panierInitial' => $panierInitial,
            'clients' => request()->user()->can('vente.credit')
                ? Client::where('actif', true)->orderBy('nom')->get(['id', 'nom', 'telephone'])
                : collect(),
            'typesClient' => TypeClient::where('actif', true)->orderBy('nom')->get(),
            // Soldes en un seul aggregat (pas de N+1 par client) : un solde
            // négatif est un avoir, affiché au caissier au moment de choisir
            // le client pour qu'il puisse le mentionner/l'appliquer.
            'clientSoldes' => request()->user()->can('vente.credit')
                ? EcritureCompteClient::selectRaw('client_id, SUM(montant) as solde')->groupBy('client_id')->pluck('solde', 'client_id')
                : collect(),
        ]);
    }

    /**
     * Le client de la vente résultante est toujours celui du devis (jamais
     * un champ du formulaire) — voir DevisService::transformer(). La
     * permission vente.credit reste vérifiée ici : sans elle, aucun solde
     * restant dû n'est autorisé, même si le devis impose un client (sinon
     * la permission serait contournable en passant par un devis).
     */
    public function transformerDevis(Request $request, SessionCaisse $session, Devis $devis, DevisService $devisService, VenteService $venteService): RedirectResponse
    {
        $this->assurerMagasin($session->caisse->magasin_id);
        $this->nettoyerChampsOptionnels($request);
        $this->bloquerRemiseSansPermission($request);

        $donnees = $request->validate([
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'exists:produits,id'],
            'lignes.*.unite_vente_id' => ['nullable', 'exists:unite_ventes,id'],
            'lignes.*.taxe_id' => ['nullable', 'exists:taxes,id'],
            'lignes.*.magasin_source_id' => ['required', 'exists:magasins,id'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0.001'],
            'lignes.*.remise_type' => ['nullable', 'in:montant,pourcentage'],
            'lignes.*.remise_valeur' => ['nullable', 'integer', $this->remiseValeurMin(), $this->remisePourcentageMax()],
            'lignes.*.prix_personnalise' => ['nullable', 'boolean'],
            'remise_totale_type' => ['nullable', 'in:montant,pourcentage'],
            'remise_totale_valeur' => ['nullable', 'integer', 'min:0', $this->remisePourcentageMax()],
            'paiements' => ['present', 'array'],
            'paiements.*.moyen_paiement_id' => ['required', 'exists:moyen_paiements,id'],
            'paiements.*.montant' => ['required', 'integer', 'min:1'],
            'montant_recu' => ['nullable', 'integer', 'min:0'],
        ]);

        if (! $request->user()->can('vente.credit')) {
            $totalNet = $venteService->calculerTotalNet(
                $donnees['lignes'],
                $donnees['remise_totale_type'] ?? null,
                $donnees['remise_totale_valeur'] ?? null,
            );
            $totalPaiements = array_sum(array_column($donnees['paiements'], 'montant'));
            abort_if($totalPaiements < $totalNet, 403, 'Vous n\'avez pas la permission de vendre à crédit.');
        }

        try {
            $vente = $devisService->transformer(
                devis: $devis,
                session: $session,
                caissier: $request->user(),
                lignes: $donnees['lignes'],
                paiements: $donnees['paiements'],
                remiseTotaleType: $donnees['remise_totale_type'] ?? null,
                remiseTotaleValeur: $donnees['remise_totale_valeur'] ?? null,
                montantRecu: $donnees['montant_recu'] ?? null,
                autoriserDepassementLimite: $request->user()->can('client.depasser_limite'),
            );
        } catch (StockInsuffisantException|SessionNonOuverteException|InvalidArgumentException|LimiteCreditDepasseeException|DevisNonTransformableException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        return redirect()->route('ventes.ticket', $vente)->with('succes', 'Devis transformé en vente.');
    }

    public function store(Request $request, SessionCaisse $session, VenteService $venteService): RedirectResponse
    {
        $this->assurerMagasin($session->caisse->magasin_id);
        $this->nettoyerChampsOptionnels($request);
        $this->bloquerRemiseSansPermission($request);
        $this->bloquerCreditSansPermission($request);
        $this->bloquerTypeSansPermission($request);

        $donnees = $request->validate([
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'exists:produits,id'],
            'lignes.*.unite_vente_id' => ['nullable', 'exists:unite_ventes,id'],
            'lignes.*.taxe_id' => ['nullable', 'exists:taxes,id'],
            'lignes.*.magasin_source_id' => ['required', 'exists:magasins,id'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0.001'],
            'lignes.*.remise_type' => ['nullable', 'in:montant,pourcentage'],
            'lignes.*.remise_valeur' => ['nullable', 'integer', $this->remiseValeurMin(), $this->remisePourcentageMax()],
            'lignes.*.prix_personnalise' => ['nullable', 'boolean'],
            'remise_totale_type' => ['nullable', 'in:montant,pourcentage'],
            'remise_totale_valeur' => ['nullable', 'integer', 'min:0', $this->remisePourcentageMax()],
            // Un panier vendu à crédit à 100 % (aucun acompte) envoie un
            // tableau de paiements vide : "present" plutôt que "required" pour
            // l'autoriser, la cohérence montant/net à payer reste vérifiée par
            // VenteService.
            'paiements' => ['present', 'array'],
            'paiements.*.moyen_paiement_id' => ['required', 'exists:moyen_paiements,id'],
            'paiements.*.montant' => ['required', 'integer', 'min:1'],
            'montant_recu' => ['nullable', 'integer', 'min:0'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'type' => ['nullable', 'in:facture,bon_livraison'],
        ]);

        $client = ! empty($donnees['client_id']) ? Client::findOrFail($donnees['client_id']) : null;

        try {
            $vente = $venteService->vendre(
                session: $session,
                caissier: $request->user(),
                lignes: $donnees['lignes'],
                paiements: $donnees['paiements'],
                remiseTotaleType: $donnees['remise_totale_type'] ?? null,
                remiseTotaleValeur: $donnees['remise_totale_valeur'] ?? null,
                montantRecu: $donnees['montant_recu'] ?? null,
                client: $client,
                autoriserDepassementLimite: $request->user()->can('client.depasser_limite'),
                type: VenteType::tryFrom($donnees['type'] ?? '') ?? VenteType::Facture,
            );
        } catch (StockInsuffisantException|SessionNonOuverteException|InvalidArgumentException|LimiteCreditDepasseeException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        return redirect()->route('ventes.ticket', $vente)->with('succes', 'Vente enregistrée.');
    }

    public function ticket(Vente $vente): View
    {
        // Route ouverte hors du groupe can:vente.creer (voir routes/web.php) :
        // un utilisateur n'ayant que vente.livrer (livreur/magasinier sans
        // droit de vente) doit pouvoir ouvrir cette fiche pour y enregistrer
        // une livraison.
        abort_unless(request()->user()->can('vente.creer') || request()->user()->can('vente.livrer'), 403);
        $this->assurerMagasin($vente->magasin_id);

        $vente->load([
            'lignes.produit', 'lignes.uniteVente', 'lignes.taxe', 'lignes.magasinSource', 'paiements.moyenPaiement',
            'reglementsClient.paiements.moyenPaiement', 'reglementsClient.caissier',
            'retours.lignes.produit', 'retours.auteur', 'retours.client',
            'bonsLivraison' => fn ($q) => $q->withTrashed(),
            'bonsLivraison.lignes.produit', 'bonsLivraison.lignes.magasin',
            'bonsLivraison.auteur', 'bonsLivraison.annulateur',
            'magasin', 'caissier', 'sessionCaisse.caisse', 'annulateur', 'client',
        ]);

        // Reste retournable par ligne (quantite_pieces − déjà retourné) : une
        // seule passe sur les retours déjà chargés, jamais une requête par
        // ligne (voir RetourVenteService, même logique de calcul).
        $dejaRetourneParLigne = $vente->retours
            ->flatMap(fn ($retour) => $retour->lignes)
            ->groupBy('ligne_vente_id')
            ->map(fn ($lignes) => $lignes->sum('quantite_pieces'));

        // Reste à livrer par ligne : même principe, mais en excluant d'abord
        // les bons de livraison annulés (chargés withTrashed() ci-dessus pour
        // l'historique, mais qui ne comptent jamais dans le reste à livrer).
        $dejaLivreParLigne = $vente->bonsLivraison
            ->whereNull('deleted_at')
            ->flatMap(fn ($bonLivraison) => $bonLivraison->lignes)
            ->groupBy('ligne_vente_id')
            ->map(fn ($lignes) => $lignes->sum('quantite_pieces'));

        $signalements = $vente->activities()
            ->where('description', 'like', '%» signalée :%')
            ->with('causer')
            ->latest()
            ->get();

        // Un règlement client exige une session de caisse ouverte (règle 14)
        // — celle de l'utilisateur courant, pas forcément celle de la vente.
        $sessionOuverte = SessionCaisse::where('caissier_id', request()->user()->id)
            ->whereNull('date_fermeture')
            ->whereNull('date_cloture')
            ->with('caisse')
            ->first();

        // Le bouton "Facture" imprime en place (voir ventes/ticket.blade.php,
        // #factureImprimable) plutôt que de rediriger vers ventes.facture —
        // mêmes données que chargerDonneesFacture() pour un rendu identique.
        $parametre = Parametre::actuel();
        $logoDataUri = $parametre->logoDataUri();

        return view('ventes.ticket', [
            'vente' => $vente,
            'signalements' => $signalements,
            'sessionOuverte' => $sessionOuverte,
            'peutRegler' => request()->user()->can('client.reglement'),
            'peutRetourner' => request()->user()->can('vente.retour'),
            'peutRembourserAvoir' => request()->user()->can('client.reglement'),
            'peutLivrer' => request()->user()->can('vente.livrer'),
            'dejaRetourneParLigne' => $dejaRetourneParLigne,
            'dejaLivreParLigne' => $dejaLivreParLigne,
            'moyensPaiement' => MoyenPaiement::actifs(),
            // Pour le sélecteur "Client à créditer" du retour, uniquement
            // utile si la vente est comptant (voir ventes/ticket.blade.php) —
            // chargés inconditionnellement, coût négligeable (référentiels).
            'clientsPourRetour' => $vente->client_id === null ? Client::orderBy('nom')->get(['id', 'nom', 'telephone', 'comptant_defaut']) : null,
            'typesClient' => $vente->client_id === null ? TypeClient::where('actif', true)->orderBy('nom')->get() : null,
            'parametre' => $parametre,
            'logoDataUri' => $logoDataUri,
        ]);
    }

    public function facture(Vente $vente): View
    {
        $this->assurerMagasin($vente->magasin_id);

        return view('ventes.facture', $this->chargerDonneesFacture($vente));
    }

    public function pdf(Vente $vente): Response
    {
        $this->assurerMagasin($vente->magasin_id);

        $pdf = Pdf::loadView('ventes.facture', $this->chargerDonneesFacture($vente) + ['pourPdf' => true]);

        // ?imprimer=1 (voir x-bouton-imprimer) : ouvre le PDF dans l'onglet
        // au lieu de forcer un téléchargement, pour que le bouton "Imprimer"
        // imprime exactement le même rendu que "Télécharger en PDF" (même
        // mécanisme que CommandeAchatController::pdf()).
        $nomFichier = "facture-{$vente->numero}.pdf";

        return request()->boolean('imprimer') ? $pdf->stream($nomFichier) : $pdf->download($nomFichier);
    }

    public function excel(Vente $vente): StreamedResponse
    {
        $this->assurerMagasin($vente->magasin_id);

        $vente->load(['client', 'magasin', 'lignes.produit', 'lignes.uniteVente', 'lignes.taxe', 'paiements.moyenPaiement', 'reglementsClient', 'retours']);

        $spreadsheet = new Spreadsheet();
        $feuille = $spreadsheet->getActiveSheet();
        $feuille->setTitle('Vente');

        $parametre = Parametre::actuel();
        $feuille->setCellValue('A1', $parametre->nom);
        $feuille->setCellValue('A2', $parametre->adresse);
        $feuille->setCellValue('A3', $parametre->numero ? 'Tél : '.$parametre->numero : '');

        $feuille->setCellValue('D1', mb_strtoupper($vente->type->libelle()));
        $feuille->setCellValue('D2', 'N° '.$vente->numero);
        $feuille->setCellValue('D3', 'Date : '.$vente->created_at->format('d/m/Y H:i'));
        $feuille->setCellValue('D4', 'Magasin : '.$vente->magasin->nom);

        $feuille->setCellValue('A6', 'Client');
        $feuille->setCellValue('A7', $vente->client->nom ?? 'Client comptant');
        $feuille->setCellValue('A8', telephone($vente->client?->telephone));
        $feuille->setCellValue('A9', $vente->client->adresse ?? '');

        $ligneEnTete = 11;
        $entetes = ['A' => 'Désignation', 'B' => 'Unité', 'C' => 'Quantité', 'D' => 'P.u', 'E' => 'Remise', 'F' => 'Taxe', 'G' => 'Total'];
        foreach ($entetes as $colonne => $libelle) {
            $feuille->setCellValue("{$colonne}{$ligneEnTete}", $libelle);
        }

        $ligne = $ligneEnTete + 1;
        foreach ($vente->lignes as $ligneVente) {
            $feuille->setCellValue("A{$ligne}", $ligneVente->produit->libelle_affichage);
            $feuille->setCellValue("B{$ligne}", $ligneVente->uniteVente->libelle ?? $ligneVente->produit->unite_base_libelle);
            $feuille->setCellValue("C{$ligne}", $ligneVente->quantite);
            $feuille->setCellValue("D{$ligne}", $ligneVente->prixUnitaireEffectif());
            $feuille->setCellValue("E{$ligne}", $ligneVente->prix_personnalise ? 0 : $ligneVente->remise_ligne_montant);
            $feuille->setCellValue("F{$ligne}", $ligneVente->taxe->nom ?? '—');
            $feuille->setCellValue("G{$ligne}", $ligneVente->total_ligne);
            $ligne++;
        }

        $ligne++;
        $feuille->setCellValue("F{$ligne}", 'Sous-total (HT)');
        $feuille->setCellValue("G{$ligne}", $vente->sous_total);
        $ligne++;
        if ($vente->totalTaxes() > 0) {
            $feuille->setCellValue("F{$ligne}", 'Total taxes');
            $feuille->setCellValue("G{$ligne}", $vente->totalTaxes());
            $ligne++;
        }
        if ($vente->remise_totale_montant > 0) {
            $feuille->setCellValue("F{$ligne}", 'Remise');
            $feuille->setCellValue("G{$ligne}", $vente->remise_totale_montant);
            $ligne++;
        }
        $feuille->setCellValue("F{$ligne}", 'Total net');
        $feuille->setCellValue("G{$ligne}", $vente->total_net);
        $ligne++;
        foreach ($vente->paiements as $paiement) {
            $feuille->setCellValue("F{$ligne}", $paiement->moyenPaiement->nom);
            $feuille->setCellValue("G{$ligne}", $paiement->montant);
            $ligne++;
        }
        if ($vente->avoir_applique > 0) {
            $feuille->setCellValue("F{$ligne}", 'Avoir appliqué');
            $feuille->setCellValue("G{$ligne}", $vente->avoir_applique);
            $ligne++;
        }
        if ($vente->soldeDuReel() > 0) {
            $feuille->setCellValue("F{$ligne}", 'Solde à crédit');
            $feuille->setCellValue("G{$ligne}", $vente->soldeDuReel());
            $ligne++;
        }

        if ($vente->estBonLivraison()) {
            $ligne++;
            $feuille->setCellValue("A{$ligne}", 'Facture à suivre');
        }

        foreach (array_keys($entetes) as $colonne) {
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }

        $nomFichier = "facture-{$vente->numero}.xlsx";
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $nomFichier, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Logo encodé en data URI (comme pour le devis) : dompdf ne charge une
     * image distante que si enable_remote est activé côté serveur.
     */
    private function chargerDonneesFacture(Vente $vente): array
    {
        // bonsLivraison.lignes reste nécessaire : le badge de statut de
        // livraison en haut du document (livraisonEngagee()/entierementLivree(),
        // voir ventes/facture.blade.php) en dépend, même si le détail par
        // ligne n'est plus affiché dans le tableau.
        $vente->load(['client', 'magasin', 'caissier', 'sessionCaisse.caisse', 'lignes.produit', 'lignes.uniteVente', 'lignes.taxe', 'paiements.moyenPaiement', 'reglementsClient', 'retours', 'bonsLivraison.lignes']);

        $parametre = Parametre::actuel();

        return [
            'vente' => $vente,
            'parametre' => $parametre,
            'logoDataUri' => $parametre->logoDataUri(),
        ];
    }

    /**
     * Ne fait que tracer un signalement (ex. doublon de saisie) dans le
     * journal d'activité et prévenir les gérants — ne touche ni au stock ni
     * aux montants. Charge au titulaire de la permission vente.annuler de
     * décider s'il y a lieu d'annuler (voir annuler() ci-dessous).
     */
    public function signaler(Request $request, Vente $vente): RedirectResponse
    {
        $this->assurerMagasin($vente->magasin_id);

        $donnees = $request->validate([
            'motif' => ['required', 'string', 'max:500'],
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($vente)
            ->withProperties(['motif' => $donnees['motif']])
            ->log("Vente « {$vente->numero} » signalée : {$donnees['motif']}");

        User::gerantsEtSuperadmins($vente->magasin_id)->each(
            fn (User $destinataire) => $destinataire->notify(new VenteSignalee($vente, $donnees['motif']))
        );

        return redirect()->route('ventes.ticket', $vente)
            ->with('succes', 'Vente signalée. Le gérant peut ajuster la caisse ou le stock manuellement si besoin.');
    }

    /**
     * Annule une vente (erreur de saisie — pas un retour client, voir
     * discussion CLAUDE.md). La vente n'est ni supprimée ni modifiée dans
     * son contenu : elle est marquée annulée (soft delete + motif + auteur),
     * reste consultable dans l'historique, et un mouvement de stock inverse
     * (immuable) restitue exactement ce qui avait été décrémenté par la
     * vente. Exclue automatiquement des CA/rapports par le scope par défaut
     * du modèle Vente (soft deleted).
     */
    public function annuler(Request $request, Vente $vente, StockService $stockService, CompteClientService $compteClientService): RedirectResponse
    {
        $this->assurerMagasin($vente->magasin_id);

        $donnees = $request->validate([
            'motif' => ['required', 'string', 'max:500'],
        ]);

        // annuler() restitue la quantite_pieces ORIGINALE de chaque ligne :
        // si une partie a déjà été rendue via un retour, ce stock a déjà été
        // restitué, une annulation totale le restituerait une seconde fois.
        if ($vente->retours()->exists()) {
            return redirect()->route('ventes.ticket', $vente)
                ->with('erreur', "Impossible d'annuler cette vente : elle a déjà fait l'objet d'un retour partiel.");
        }

        $vente->load('lignes.produit', 'lignes.magasinSource', 'magasin', 'client');

        DB::transaction(function () use ($vente, $donnees, $request, $stockService, $compteClientService) {
            foreach ($vente->lignes as $ligne) {
                // Chaque ligne restitue son propre magasin/dépôt source, qui
                // peut différer du magasin de la vente (voir CLAUDE.md) —
                // jamais le magasin de la vente pour toutes les lignes.
                $stockService->enregistrerMouvement(
                    produit: $ligne->produit,
                    magasin: $ligne->magasinSource,
                    quantite: $ligne->quantite_pieces,
                    type: MouvementStockType::Annulation,
                    auteur: $request->user(),
                    reference: $vente,
                    motif: $donnees['motif'],
                );
            }

            // Reverse la dette exacte posée par crediterDette() à la vente
            // (retrouvée via l'écriture elle-même, pas recalculée) — rien à
            // faire pour une vente comptant, qui n'en a jamais posé.
            $montantDette = EcritureCompteClient::where('reference_type', $vente->getMorphClass())
                ->where('reference_id', $vente->id)
                ->where('type', EcritureCompteClientType::VenteCredit)
                ->value('montant');

            if ($montantDette > 0) {
                $compteClientService->annulerDette($vente->client, $montantDette, $vente, $request->user());
            }

            $vente->motif_annulation = $donnees['motif'];
            $vente->annulee_par = $request->user()->id;
            $vente->save();
            $vente->delete();
        });

        activity()
            ->causedBy($request->user())
            ->performedOn($vente)
            ->withProperties(['motif' => $donnees['motif']])
            ->log("Vente « {$vente->numero} » annulée : {$donnees['motif']}");

        return redirect()->route('ventes.ticket', $vente)
            ->with('succes', 'Vente annulée. Le stock a été remis à jour.');
    }

    /**
     * Les champs optionnels arrivent en chaîne vide depuis les <select>/<input>
     * du panier JS ; on les normalise en null pour que les règles "nullable"
     * fonctionnent (Laravel ne traite pas "" comme null).
     */
    private function nettoyerChampsOptionnels(Request $request): void
    {
        $lignes = collect($request->input('lignes', []))->map(function (array $ligne) {
            $ligne['unite_vente_id'] = ($ligne['unite_vente_id'] ?? null) ?: null;
            $ligne['taxe_id'] = ($ligne['taxe_id'] ?? null) ?: null;
            $ligne['magasin_source_id'] = ($ligne['magasin_source_id'] ?? null) ?: null;
            $ligne['remise_type'] = ($ligne['remise_type'] ?? null) ?: null;
            $ligne['remise_valeur'] = ($ligne['remise_valeur'] ?? null) ?: null;
            $ligne['quantite'] = Decimal::normaliser($ligne['quantite'] ?? null);

            return $ligne;
        })->all();

        $request->merge([
            'lignes' => $lignes,
            'remise_totale_type' => $request->input('remise_totale_type') ?: null,
            'remise_totale_valeur' => $request->input('remise_totale_valeur') ?: null,
            // Une vente entièrement à crédit (aucun paiement saisi) ne génère
            // aucun champ paiements[...] côté JS (boucle vide) : la clé
            // n'existe alors pas du tout dans la requête, ce qui échoue la
            // règle "present" — normalisée ici en tableau vide, autorisé
            // (règle 13 : paiement partiel ou nul si un client est identifié).
            'paiements' => $request->input('paiements', []),
        ]);
    }
}
