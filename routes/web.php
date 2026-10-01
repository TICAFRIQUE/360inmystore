<?php

use App\Http\Controllers\AbonnementController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\BonLivraisonController;
use App\Http\Controllers\CaisseController;
use App\Http\Controllers\CategorieController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommandeAchatController;
use App\Http\Controllers\ComptabiliteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviseController;
use App\Http\Controllers\DevisController;
use App\Http\Controllers\FournisseurController;
use App\Http\Controllers\InventaireController;
use App\Http\Controllers\JournalActiviteController;
use App\Http\Controllers\MagasinController;
use App\Http\Controllers\MotifMouvementController;
use App\Http\Controllers\MoyenPaiementController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ParametreController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\RapportController;
use App\Http\Controllers\ReglementClientController;
use App\Http\Controllers\ReglementFournisseurController;
use App\Http\Controllers\RemboursementAvoirClientController;
use App\Http\Controllers\RemboursementAvoirFournisseurController;
use App\Http\Controllers\ReceptionAchatController;
use App\Http\Controllers\RetourAchatController;
use App\Http\Controllers\RetourVenteController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SessionCaisseController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockMouvementController;
use App\Http\Controllers\TaxeController;
use App\Http\Controllers\TransfertController;
use App\Http\Controllers\TypeClientController;
use App\Http\Controllers\UniteController;
use App\Http\Controllers\UniteVenteController;
use App\Http\Controllers\UtilisateurController;
use App\Http\Controllers\VenteController;
use App\Http\Controllers\VenteEnAttenteController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    // Verrou par (identifiant + IP) déjà dans le contrôleur (5 tentatives/min) ;
    // ce throttle générique par IP protège en plus contre l'énumération de
    // plusieurs identifiants depuis la même source.
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:20,1');
});

Route::middleware(['auth', 'abonnement.actif'])->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::view('guide', 'guide.index')->name('guide');

    Route::get('abonnement', [AbonnementController::class, 'mon'])->name('abonnement.mon');

    Route::middleware('abonnement.gestion')->group(function () {
        Route::get('abonnement/gestion', [AbonnementController::class, 'gestion'])->name('abonnement.gestion');
        Route::post('abonnement/activer', [AbonnementController::class, 'activer'])->name('abonnement.activer');
        Route::post('abonnement/formules', [AbonnementController::class, 'storeFormule'])->name('abonnement.formules.store');
        Route::put('abonnement/formules/{formuleAbonnement}', [AbonnementController::class, 'updateFormule'])->name('abonnement.formules.update');
        Route::patch('abonnement/formules/{formuleAbonnement}/basculer', [AbonnementController::class, 'toggleFormule'])->name('abonnement.formules.basculer');
        Route::post('abonnement/configuration', [AbonnementController::class, 'updateConfiguration'])->name('abonnement.configuration.update');
    });

    Route::post('notifications/marquer-lues', [NotificationController::class, 'marquerLues'])->name('notifications.marquer-lues');
    Route::get('notifications/{notification}/ouvrir', [NotificationController::class, 'ouvrir'])->name('notifications.ouvrir');

    Route::middleware('can:administration.gerer')->group(function () {
        // pdf/excel avant le resource() : même précaution que pour inventaires.
        Route::get('magasins/pdf', [MagasinController::class, 'pdf'])->name('magasins.pdf');
        Route::get('magasins/excel', [MagasinController::class, 'excel'])->name('magasins.excel');
        Route::resource('magasins', MagasinController::class)->except(['show']);
    });

    Route::middleware('can:categorie.gerer')->group(function () {
        Route::get('categories/pdf', [CategorieController::class, 'pdf'])->name('categories.pdf');
        Route::get('categories/excel', [CategorieController::class, 'excel'])->name('categories.excel');
        Route::resource('categories', CategorieController::class)
            ->except(['show'])
            ->parameters(['categories' => 'categorie']);
    });

    // Lecture ouverte à produit.voir ; les actions de mutation sont vérifiées
    // finement dans ProduitController (produit.creer/modifier/supprimer).
    Route::middleware('can:produit.voir')->group(function () {
        Route::get('produits/pdf', [ProduitController::class, 'pdf'])->name('produits.pdf');
        Route::get('produits/excel', [ProduitController::class, 'excel'])->name('produits.excel');
        Route::resource('produits', ProduitController::class)->except(['show']);

        Route::post('produits/{produit}/unite-ventes', [UniteVenteController::class, 'store'])->name('produits.unite-ventes.store');
        Route::put('produits/{produit}/unite-ventes/{uniteVente}', [UniteVenteController::class, 'update'])->name('produits.unite-ventes.update');
        Route::delete('produits/{produit}/unite-ventes/{uniteVente}', [UniteVenteController::class, 'destroy'])->name('produits.unite-ventes.destroy');
    });

    Route::middleware('can:administration.gerer')->group(function () {
        Route::get('moyens-paiement/pdf', [MoyenPaiementController::class, 'pdf'])->name('moyens-paiement.pdf');
        Route::get('moyens-paiement/excel', [MoyenPaiementController::class, 'excel'])->name('moyens-paiement.excel');
        Route::resource('moyens-paiement', MoyenPaiementController::class)
            ->except(['show'])
            ->parameters(['moyens-paiement' => 'moyenPaiement']);

        Route::get('unites/pdf', [UniteController::class, 'pdf'])->name('unites.pdf');
        Route::get('unites/excel', [UniteController::class, 'excel'])->name('unites.excel');
        Route::resource('unites', UniteController::class)->except(['show']);
    });

    Route::middleware('can:taxe.gerer')->group(function () {
        Route::get('taxes/pdf', [TaxeController::class, 'pdf'])->name('taxes.pdf');
        Route::get('taxes/excel', [TaxeController::class, 'excel'])->name('taxes.excel');
        Route::resource('taxes', TaxeController::class)
            ->except(['show'])
            ->parameters(['taxes' => 'taxe']);
    });

    Route::middleware('can:parametre.gerer')->group(function () {
        Route::get('parametres', [ParametreController::class, 'edit'])->name('parametres.edit');
        Route::put('parametres', [ParametreController::class, 'update'])->name('parametres.update');
        Route::put('parametres/dossier-sauvegarde', [ParametreController::class, 'mettreAJourDossierSauvegarde'])->name('parametres.dossier-sauvegarde');
        Route::post('parametres/backup', [ParametreController::class, 'backup'])->name('parametres.backup');
        Route::post('parametres/restaurer', [ParametreController::class, 'restaurer'])->name('parametres.restaurer');
    });

    Route::middleware('can:fournisseur.voir')->group(function () {
        Route::get('fournisseurs', [FournisseurController::class, 'index'])->name('fournisseurs.index');
        Route::get('fournisseurs/pdf', [FournisseurController::class, 'pdf'])->name('fournisseurs.pdf');
        Route::get('fournisseurs/excel', [FournisseurController::class, 'excel'])->name('fournisseurs.excel');
    });

    // /fournisseurs/creer avant /fournisseurs/{fournisseur} : sinon "creer"
    // serait capturé comme un {fournisseur} par la route de consultation
    // ci-dessous (même précaution que pour clients).
    Route::middleware('can:fournisseur.gerer')->group(function () {
        Route::get('fournisseurs/creer', [FournisseurController::class, 'create'])->name('fournisseurs.create');
        Route::post('fournisseurs', [FournisseurController::class, 'store'])->name('fournisseurs.store');
        Route::get('fournisseurs/{fournisseur}/modifier', [FournisseurController::class, 'edit'])->name('fournisseurs.edit');
        Route::put('fournisseurs/{fournisseur}', [FournisseurController::class, 'update'])->name('fournisseurs.update');
        Route::delete('fournisseurs/{fournisseur}', [FournisseurController::class, 'destroy'])->name('fournisseurs.destroy');
    });

    Route::middleware('can:fournisseur.voir')->group(function () {
        Route::get('fournisseurs/{fournisseur}/commandes/pdf', [FournisseurController::class, 'commandesPdf'])->name('fournisseurs.commandes.pdf');
        Route::get('fournisseurs/{fournisseur}/commandes/excel', [FournisseurController::class, 'commandesExcel'])->name('fournisseurs.commandes.excel');
        Route::get('fournisseurs/{fournisseur}', [FournisseurController::class, 'show'])->name('fournisseurs.show');
    });

    Route::middleware('can:fournisseur.reglement')->group(function () {
        Route::post('fournisseurs/{fournisseur}/reglements', [ReglementFournisseurController::class, 'store'])->name('reglements-fournisseur.store');
        Route::post('fournisseurs/{fournisseur}/remboursements-avoir', [RemboursementAvoirFournisseurController::class, 'store'])->name('remboursements-avoir-fournisseur.store');
    });

    Route::middleware('can:typeclient.gerer')->group(function () {
        Route::get('type-clients/pdf', [TypeClientController::class, 'pdf'])->name('type-clients.pdf');
        Route::get('type-clients/excel', [TypeClientController::class, 'excel'])->name('type-clients.excel');
        Route::resource('type-clients', TypeClientController::class)
            ->except(['show'])
            ->parameters(['type-clients' => 'typeClient']);
    });

    Route::middleware('can:devise.gerer')->group(function () {
        Route::get('devises/pdf', [DeviseController::class, 'pdf'])->name('devises.pdf');
        Route::get('devises/excel', [DeviseController::class, 'excel'])->name('devises.excel');
        Route::resource('devises', DeviseController::class)->except(['show']);
    });

    // motifs-mouvement/rapide avant le resource() ci-dessous : sinon "rapide"
    // serait capturé comme un {motifMouvement} par la route show/edit.
    // Accessible à tout utilisateur connecté (pas seulement motif.gerer) :
    // quiconque peut déjà soumettre un mouvement de caisse/trésorerie doit
    // pouvoir y ajouter un nouveau motif à la volée (voir CLAUDE.md,
    // Mouvements de caisse) — seule la gestion complète (modifier/supprimer)
    // reste réservée à motif.gerer.
    Route::post('motifs-mouvement/rapide', [MotifMouvementController::class, 'storeRapide'])->name('motifs-mouvement.rapide');

    Route::middleware('can:motif.gerer')->group(function () {
        Route::get('motifs-mouvement/pdf', [MotifMouvementController::class, 'pdf'])->name('motifs-mouvement.pdf');
        Route::get('motifs-mouvement/excel', [MotifMouvementController::class, 'excel'])->name('motifs-mouvement.excel');
        Route::resource('motifs-mouvement', MotifMouvementController::class)
            ->except(['show'])
            ->parameters(['motifs-mouvement' => 'motifMouvement']);
    });

    Route::middleware('can:client.voir')->group(function () {
        Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('clients/pdf', [ClientController::class, 'pdf'])->name('clients.pdf');
        Route::get('clients/excel', [ClientController::class, 'excel'])->name('clients.excel');
    });

    // /clients/creer, /clients/pdf, /clients/excel avant /clients/{client} :
    // sinon ce segment serait capturé comme un {client} par la route de
    // consultation ci-dessous.
    Route::middleware('can:client.gerer')->group(function () {
        Route::get('clients/creer', [ClientController::class, 'create'])->name('clients.create');
        Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
        Route::post('clients/rapide', [ClientController::class, 'storeRapide'])->name('clients.rapide');
        Route::get('clients/{client}/modifier', [ClientController::class, 'edit'])->name('clients.edit');
        Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
        Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
    });

    Route::middleware('can:client.voir')->group(function () {
        Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
        Route::get('clients/{client}/ventes/pdf', [ClientController::class, 'ventesPdf'])->name('clients.ventes.pdf');
        Route::get('clients/{client}/ventes/excel', [ClientController::class, 'exporterVentes'])->name('clients.ventes.excel');
        Route::get('clients/{client}/devis/pdf', [ClientController::class, 'devisPdf'])->name('clients.devis.pdf');
        Route::get('clients/{client}/devis/excel', [ClientController::class, 'exporterDevis'])->name('clients.devis.excel');
    });

    Route::middleware('can:achat.voir')->group(function () {
        // Export de la liste, avant /commande-achats/{commandeAchat} : sinon
        // "pdf"/"excel" serait capturé comme un {commandeAchat}. Distinct de
        // commande-achats.pdf/.excel (un bon de commande précis).
        Route::get('commande-achats/pdf', [CommandeAchatController::class, 'listePdf'])->name('commande-achats.liste.pdf');
        Route::get('commande-achats/excel', [CommandeAchatController::class, 'listeExcel'])->name('commande-achats.liste.excel');
        Route::resource('commande-achats', CommandeAchatController::class)
            ->only(['index', 'create', 'store', 'destroy'])
            ->parameters(['commande-achats' => 'commandeAchat']);
        // withTrashed() : une commande annulée reste consultable (détail avec
        // bandeau d'annulation), voir CommandeAchatController::show().
        Route::get('commande-achats/{commandeAchat}', [CommandeAchatController::class, 'show'])->name('commande-achats.show')->withTrashed();
        Route::get('commande-achats/{commandeAchat}/facture', [CommandeAchatController::class, 'facture'])->name('commande-achats.facture')->withTrashed();
        Route::get('commande-achats/{commandeAchat}/pdf', [CommandeAchatController::class, 'pdf'])->name('commande-achats.pdf')->withTrashed();
        Route::get('commande-achats/{commandeAchat}/excel', [CommandeAchatController::class, 'excel'])->name('commande-achats.excel')->withTrashed();
        Route::post('commande-achats/{commandeAchat}/valider', [CommandeAchatController::class, 'valider'])->name('commande-achats.valider');
        // Pas de withTrashed() ici : tenter d'annuler une commande déjà
        // annulée doit échouer (404 sur la liaison de route), pas la re-traiter.
        Route::post('commande-achats/{commandeAchat}/annuler', [CommandeAchatController::class, 'annuler'])->name('commande-achats.annuler');
    });

    Route::middleware('can:achat.retour')->group(function () {
        Route::post('commande-achats/{commandeAchat}/retours', [RetourAchatController::class, 'store'])->name('commande-achats.retours.store');
    });

    Route::middleware('can:achat.receptionner')->group(function () {
        Route::post('commande-achats/{commandeAchat}/receptions', [ReceptionAchatController::class, 'store'])->name('commande-achats.receptions.store');
    });

    Route::middleware('can:stock.voir')->group(function () {
        Route::get('stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('stock/pdf', [StockController::class, 'pdf'])->name('stock.pdf');
        Route::get('stock/excel', [StockController::class, 'excel'])->name('stock.excel');
        Route::get('stock/mouvements', [StockMouvementController::class, 'index'])->name('stock.mouvements.index');
        Route::get('stock/mouvements/pdf', [StockMouvementController::class, 'pdf'])->name('stock.mouvements.pdf');
        Route::get('stock/mouvements/excel', [StockMouvementController::class, 'excel'])->name('stock.mouvements.excel');
    });

    Route::middleware('can:stock.transferer')->group(function () {
        Route::get('transferts/pdf', [TransfertController::class, 'pdf'])->name('transferts.pdf');
        Route::get('transferts/excel', [TransfertController::class, 'excel'])->name('transferts.excel');
        Route::resource('transferts', TransfertController::class)->only(['index', 'create', 'store']);
    });

    Route::middleware('can:stock.ajuster')->group(function () {
        Route::get('stock/mouvements/creer', [StockMouvementController::class, 'create'])->name('stock.mouvements.create');
        Route::post('stock/mouvements', [StockMouvementController::class, 'store'])->name('stock.mouvements.store');
    });

    Route::middleware('can:inventaire.voir')->group(function () {
        // pdf/excel avant le resource() ci-dessous : sinon "pdf"/"excel"
        // serait capturé comme un {inventaire} par la route de consultation.
        Route::get('inventaires/pdf', [InventaireController::class, 'pdf'])->name('inventaires.pdf');
        Route::get('inventaires/excel', [InventaireController::class, 'excel'])->name('inventaires.excel');
        Route::resource('inventaires', InventaireController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
        Route::post('inventaires/{inventaire}/saisir', [InventaireController::class, 'saisir'])->name('inventaires.saisir');
        Route::post('inventaires/{inventaire}/valider', [InventaireController::class, 'valider'])->name('inventaires.valider');
    });

    // La gestion (CRUD) des caisses passe par administration.gerer, distinct
    // de la permission caisse.gerer qui donne au gérant une vue d'ensemble
    // sur les sessions/ventes de son équipe (voir SessionCaisseController,
    // VenteController, VenteEnAttenteController, AutoriseVenteEnAttente).
    Route::middleware('can:administration.gerer')->group(function () {
        Route::get('caisses/pdf', [CaisseController::class, 'pdf'])->name('caisses.pdf');
        Route::get('caisses/excel', [CaisseController::class, 'excel'])->name('caisses.excel');
        Route::resource('caisses', CaisseController::class)
            ->except(['show'])
            ->parameters(['caisses' => 'caisse']);
    });

    Route::middleware('can:caisse.ouvrir')->group(function () {
        Route::get('sessions', [SessionCaisseController::class, 'index'])->name('sessions.index');
        Route::get('caisses/{caisse}/ouvrir', [SessionCaisseController::class, 'create'])->name('sessions.create');
        Route::post('caisses/{caisse}/ouvrir', [SessionCaisseController::class, 'store'])->name('sessions.store');
        Route::get('sessions/{session}', [SessionCaisseController::class, 'show'])->name('sessions.show');
        Route::get('sessions/{session}/rapport', [SessionCaisseController::class, 'rapport'])->name('sessions.rapport');
        Route::get('sessions/{session}/rapport/pdf', [SessionCaisseController::class, 'rapportPdf'])->name('sessions.rapport.pdf');
        Route::get('sessions/{session}/rapport/excel', [SessionCaisseController::class, 'rapportExcel'])->name('sessions.rapport.excel');
    });

    Route::middleware('can:caisse.cloturer')->group(function () {
        Route::get('sessions/{session}/cloturer', [SessionCaisseController::class, 'cloturerForm'])->name('sessions.cloturer.form');
        Route::post('sessions/{session}/cloturer', [SessionCaisseController::class, 'cloturer'])->name('sessions.cloturer');
    });

    Route::middleware('can:caisse.mouvement')->group(function () {
        Route::post('sessions/{session}/mouvements', [SessionCaisseController::class, 'storeMouvement'])->name('sessions.mouvements.store');
    });

    // Trésorerie (Caisse Générale + comptes bancaires/autres) : indépendante
    // des caisses de vente des caissiers ci-dessus (voir CLAUDE.md,
    // Trésorerie) — jamais gardée par caisse.*, toujours tresorerie.*.
    Route::middleware('can:tresorerie.voir')->group(function () {
        Route::get('comptabilite/caisses', [ComptabiliteController::class, 'index'])->name('comptabilite.caisses.index');
        Route::get('comptabilite/caisses-vente/{caisse}', [ComptabiliteController::class, 'showCaisseVente'])->name('comptabilite.caisses-vente.show');
        Route::get('comptabilite/caisses/{compte}', [ComptabiliteController::class, 'show'])->name('comptabilite.caisses.show');
        Route::get('comptabilite/caisses/{compte}/pdf', [ComptabiliteController::class, 'showPdf'])->name('comptabilite.caisses.show.pdf');
        Route::get('comptabilite/caisses/{compte}/excel', [ComptabiliteController::class, 'showExcel'])->name('comptabilite.caisses.show.excel');

        Route::get('rapports/tresorerie', [RapportController::class, 'tresorerie'])->name('rapports.tresorerie');
        Route::get('rapports/tresorerie/pdf', [RapportController::class, 'tresoreriePdf'])->name('rapports.tresorerie.pdf');
        Route::get('rapports/tresorerie/excel', [RapportController::class, 'tresorerieExcel'])->name('rapports.tresorerie.excel');
    });

    Route::middleware('can:tresorerie.gerer')->group(function () {
        Route::post('comptabilite/caisses/{compte}/mouvements', [ComptabiliteController::class, 'storeMouvement'])->name('comptabilite.mouvements.store');
        Route::post('comptabilite/caisses/{compte}/virement', [ComptabiliteController::class, 'virer'])->name('comptabilite.virement.store');
        Route::post('comptabilite/comptes', [ComptabiliteController::class, 'storeCompte'])->name('comptabilite.comptes.store');
        Route::put('comptabilite/comptes/{compte}', [ComptabiliteController::class, 'updateCompte'])->name('comptabilite.comptes.update');
    });

    Route::middleware('can:caisse.fermer')->group(function () {
        Route::post('sessions/{session}/fermer', [SessionCaisseController::class, 'fermer'])->name('sessions.fermer');
    });

    Route::middleware('can:vente.creer')->group(function () {
        Route::get('sessions/{session}/vente', [VenteController::class, 'create'])->name('ventes.create');
        Route::post('sessions/{session}/vente', [VenteController::class, 'store'])->name('ventes.store');
        Route::get('produits/{produit}/stock-magasins', [ProduitController::class, 'stockParMagasin'])->name('produits.stock-magasins');
        Route::get('ventes/{vente}/facture', [VenteController::class, 'facture'])->name('ventes.facture')->withTrashed();
        Route::get('ventes/{vente}/pdf', [VenteController::class, 'pdf'])->name('ventes.pdf')->withTrashed();
        Route::get('ventes/{vente}/excel', [VenteController::class, 'excel'])->name('ventes.excel')->withTrashed();
    });

    // Hors du groupe can:vente.creer : un utilisateur n'ayant que vente.livrer
    // (livreur/magasinier sans droit de vente) doit pouvoir ouvrir la fiche
    // d'une facture pour y enregistrer une livraison, sans avoir besoin de
    // vente.creer — le contrôle se fait dans VenteController::ticket() lui-même
    // (vente.creer OU vente.livrer). withTrashed() : une vente annulée reste
    // consultable (ticket avec mention "Annulée"), jamais un 404.
    Route::get('ventes/{vente}/ticket', [VenteController::class, 'ticket'])->name('ventes.ticket')->withTrashed();

    Route::middleware('can:vente.signaler')->group(function () {
        Route::post('ventes/{vente}/signaler', [VenteController::class, 'signaler'])->name('ventes.signaler');
    });

    // Pas de withTrashed() ici : tenter d'annuler une vente déjà annulée doit
    // échouer (404 sur la liaison de route), pas la re-traiter.
    Route::middleware('can:vente.annuler')->group(function () {
        Route::post('ventes/{vente}/annuler', [VenteController::class, 'annuler'])->name('ventes.annuler');
    });

    Route::middleware('can:vente.retour')->group(function () {
        // /retours avant /ventes/{vente}/retours : point d'entrée dédié pour
        // rechercher la facture à retourner sans déjà la connaître, même
        // principe que bons-livraison.index (liste globale).
        Route::get('retours', [RetourVenteController::class, 'rechercher'])->name('retours.rechercher');
        Route::post('ventes/{vente}/retours', [RetourVenteController::class, 'store'])->name('ventes.retours.store');
    });

    Route::middleware('can:vente.livrer')->group(function () {
        Route::get('bons-livraison', [BonLivraisonController::class, 'index'])->name('bons-livraison.index');
        Route::post('ventes/{vente}/bons-livraison', [BonLivraisonController::class, 'store'])->name('ventes.bons-livraison.store');
        // withTrashed() : un bon de livraison annulé reste consultable
        // (badge "Annulé"), jamais un 404 — même logique que ventes.facture.
        Route::get('bons-livraison/pdf', [BonLivraisonController::class, 'listePdf'])->name('bons-livraison.liste.pdf');
        Route::get('bons-livraison/excel', [BonLivraisonController::class, 'listeExcel'])->name('bons-livraison.liste.excel');
        Route::get('bons-livraison/{bonLivraison}', [BonLivraisonController::class, 'imprimer'])->name('bons-livraison.imprimer')->withTrashed();
        Route::get('bons-livraison/{bonLivraison}/pdf', [BonLivraisonController::class, 'pdf'])->name('bons-livraison.pdf')->withTrashed();
        // Pas de withTrashed() ici : annuler un BL déjà annulé doit échouer
        // (404), pas le re-traiter — même logique que ventes.annuler.
        Route::post('bons-livraison/{bonLivraison}/annuler', [BonLivraisonController::class, 'annuler'])->name('bons-livraison.annuler');
    });

    Route::middleware('can:client.reglement')->group(function () {
        Route::get('sessions/{session}/reglements/creer', [ReglementClientController::class, 'create'])->name('reglements.create');
        Route::post('sessions/{session}/reglements', [ReglementClientController::class, 'store'])->name('reglements.store');
        // /remboursements-avoir avant /clients/{client}/... : liste centralisée
        // des clients ayant un avoir, symétrique de reglements.create côté dette.
        Route::get('remboursements-avoir', [RemboursementAvoirClientController::class, 'index'])->name('remboursements-avoir-client.index');
        Route::get('remboursements-avoir/pdf', [RemboursementAvoirClientController::class, 'pdf'])->name('remboursements-avoir-client.pdf');
        Route::get('remboursements-avoir/excel', [RemboursementAvoirClientController::class, 'excel'])->name('remboursements-avoir-client.excel');
        Route::post('clients/{client}/remboursements-avoir', [RemboursementAvoirClientController::class, 'store'])->name('remboursements-avoir-client.store');
    });

    Route::middleware('can:devis.voir')->group(function () {
        Route::get('devis', [DevisController::class, 'index'])->name('devis.index');
        Route::get('devis/pdf', [DevisController::class, 'listePdf'])->name('devis.liste.pdf');
        Route::get('devis/excel', [DevisController::class, 'listeExcel'])->name('devis.liste.excel');
    });

    // /devis/creer avant /devis/{devis} : sinon "creer" serait capturé comme
    // un {devis} par la route de consultation ci-dessous.
    Route::middleware('can:devis.gerer')->group(function () {
        Route::get('devis/creer', [DevisController::class, 'create'])->name('devis.create');
        Route::post('devis', [DevisController::class, 'store'])->name('devis.store');
        Route::get('devis/{devis}/modifier', [DevisController::class, 'edit'])->name('devis.edit');
        Route::put('devis/{devis}', [DevisController::class, 'update'])->name('devis.update');
        Route::post('devis/{devis}/annuler', [DevisController::class, 'annuler'])->name('devis.annuler');
        // Même donnée que produits.stock-magasins (voir ProduitController),
        // route dupliquée pour rester accessible à devis.gerer sans exiger
        // vente.creer.
        Route::get('devis/produits/{produit}/stock-magasins', [ProduitController::class, 'stockParMagasin'])->name('devis.produits.stock-magasins');
    });

    Route::middleware('can:devis.voir')->group(function () {
        Route::get('devis/{devis}', [DevisController::class, 'show'])->name('devis.show');
        Route::get('devis/{devis}/facture', [DevisController::class, 'facture'])->name('devis.facture');
        Route::get('devis/{devis}/pdf', [DevisController::class, 'pdf'])->name('devis.pdf');
        Route::get('devis/{devis}/excel', [DevisController::class, 'excel'])->name('devis.excel');
    });

    Route::middleware('can:devis.transformer')->group(function () {
        Route::get('sessions/{session}/devis/{devis}/transformer', [VenteController::class, 'transformerDevisForm'])->name('devis.transformer.form');
        Route::post('sessions/{session}/devis/{devis}/transformer', [VenteController::class, 'transformerDevis'])->name('devis.transformer');
    });

    Route::middleware('can:ventenattente.gerer')->group(function () {
        Route::get('sessions/{session}/ventes-en-attente', [VenteEnAttenteController::class, 'index'])->name('ventes-en-attente.index');
        Route::post('sessions/{session}/ventes-en-attente', [VenteEnAttenteController::class, 'store'])->name('ventes-en-attente.store');
        Route::put('ventes-en-attente/{venteEnAttente}', [VenteEnAttenteController::class, 'update'])->name('ventes-en-attente.update');
        Route::get('ventes-en-attente/{venteEnAttente}/reprendre', [VenteController::class, 'reprendre'])->name('ventes-en-attente.reprendre.form');
        Route::post('ventes-en-attente/{venteEnAttente}/reprendre', [VenteEnAttenteController::class, 'reprendre'])->name('ventes-en-attente.reprendre');
        Route::delete('ventes-en-attente/{venteEnAttente}', [VenteEnAttenteController::class, 'annuler'])->name('ventes-en-attente.annuler');
    });

    Route::middleware('can:utilisateur.gerer')->group(function () {
        Route::get('utilisateurs/pdf', [UtilisateurController::class, 'pdf'])->name('utilisateurs.pdf');
        Route::get('utilisateurs/excel', [UtilisateurController::class, 'excel'])->name('utilisateurs.excel');
        Route::resource('utilisateurs', UtilisateurController::class)
            ->except(['show'])
            ->parameters(['utilisateurs' => 'utilisateur']);
        Route::post('utilisateurs/{utilisateur}/reinitialiser-code', [UtilisateurController::class, 'reinitialiserCode'])
            ->name('utilisateurs.reinitialiser-code');
    });

    Route::middleware('can:role.gerer')->group(function () {
        Route::get('roles/pdf', [RoleController::class, 'pdf'])->name('roles.pdf');
        Route::get('roles/excel', [RoleController::class, 'excel'])->name('roles.excel');
        Route::resource('roles', RoleController::class)->except(['show']);
    });

    Route::middleware('can:rapport.voir')->group(function () {
        Route::get('rapports', [RapportController::class, 'index'])->name('rapports.index');

        Route::get('rapports/ventes', [RapportController::class, 'ventes'])->name('rapports.ventes');
        Route::get('rapports/ventes/pdf', [RapportController::class, 'ventesPdf'])->name('rapports.ventes.pdf');
        Route::get('rapports/ventes/excel', [RapportController::class, 'ventesExcel'])->name('rapports.ventes.excel');

        Route::get('rapports/marge', [RapportController::class, 'marge'])->name('rapports.marge');
        Route::get('rapports/marge/pdf', [RapportController::class, 'margePdf'])->name('rapports.marge.pdf');
        Route::get('rapports/marge/excel', [RapportController::class, 'margeExcel'])->name('rapports.marge.excel');

        Route::get('rapports/stock', [RapportController::class, 'stock'])->name('rapports.stock');
        Route::get('rapports/stock/pdf', [RapportController::class, 'stockPdf'])->name('rapports.stock.pdf');
        Route::get('rapports/stock/excel', [RapportController::class, 'stockExcel'])->name('rapports.stock.excel');

        Route::get('rapports/ecarts-caisse', [RapportController::class, 'ecartsCaisse'])->name('rapports.ecarts-caisse');
        Route::get('rapports/ecarts-caisse/pdf', [RapportController::class, 'ecartsCaissePdf'])->name('rapports.ecarts-caisse.pdf');
        Route::get('rapports/ecarts-caisse/excel', [RapportController::class, 'ecartsCaisseExcel'])->name('rapports.ecarts-caisse.excel');

        Route::get('rapports/mouvements-caisse', [RapportController::class, 'mouvementsCaisse'])->name('rapports.mouvements-caisse');
        Route::get('rapports/mouvements-caisse/pdf', [RapportController::class, 'mouvementsCaissePdf'])->name('rapports.mouvements-caisse.pdf');
        Route::get('rapports/mouvements-caisse/excel', [RapportController::class, 'mouvementsCaisseExcel'])->name('rapports.mouvements-caisse.excel');

        Route::get('rapports/casse', [RapportController::class, 'casse'])->name('rapports.casse');
        Route::get('rapports/casse/pdf', [RapportController::class, 'cassePdf'])->name('rapports.casse.pdf');
        Route::get('rapports/casse/excel', [RapportController::class, 'casseExcel'])->name('rapports.casse.excel');

        Route::get('rapports/inventaires', [RapportController::class, 'inventaires'])->name('rapports.inventaires');
        Route::get('rapports/inventaires/pdf', [RapportController::class, 'inventairesPdf'])->name('rapports.inventaires.pdf');
        Route::get('rapports/inventaires/excel', [RapportController::class, 'inventairesExcel'])->name('rapports.inventaires.excel');

        Route::get('journal', [JournalActiviteController::class, 'index'])->name('journal.index');
        Route::get('journal/pdf', [JournalActiviteController::class, 'pdf'])->name('journal.pdf');
        Route::get('journal/excel', [JournalActiviteController::class, 'excel'])->name('journal.excel');
    });
});
