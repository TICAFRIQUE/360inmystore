@extends('layouts.app')

@section('title', "Guide d'utilisation")

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Guide d'utilisation</h2>
        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Retour au tableau de bord
        </a>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <p class="mb-2">
                Ce guide n'affiche que les sections utiles à votre compte — les écrans
                auxquels vous n'avez pas accès ne sont pas listés ici.
            </p>
            <p class="mb-0">Sommaire :</p>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <a href="#connexion" class="btn btn-sm btn-outline-secondary">Connexion</a>
                @can('caisse.ouvrir')
                    <a href="#caisse" class="btn btn-sm btn-outline-secondary">Caisse &amp; ventes</a>
                @endcan
                @can('devis.voir')
                    <a href="#devis" class="btn btn-sm btn-outline-secondary">Devis</a>
                @endcan
                @can('client.voir')
                    <a href="#clients" class="btn btn-sm btn-outline-secondary">Clients</a>
                @endcan
                @canany(['produit.voir', 'categorie.gerer', 'stock.voir', 'inventaire.voir'])
                    <a href="#catalogue" class="btn btn-sm btn-outline-secondary">Catalogue &amp; stock</a>
                @endcanany
                @canany(['achat.voir', 'fournisseur.voir'])
                    <a href="#achats" class="btn btn-sm btn-outline-secondary">Achats &amp; fournisseurs</a>
                @endcanany
                @can('tresorerie.voir')
                    <a href="#tresorerie" class="btn btn-sm btn-outline-secondary">Trésorerie</a>
                @endcan
                @can('rapport.voir')
                    <a href="#rapports" class="btn btn-sm btn-outline-secondary">Rapports</a>
                @endcan
                @canany(['administration.gerer', 'taxe.gerer', 'typeclient.gerer', 'motif.gerer', 'parametre.gerer', 'utilisateur.gerer', 'role.gerer'])
                    <a href="#administration" class="btn btn-sm btn-outline-secondary">Administration</a>
                @endcanany
                <a href="#depannage" class="btn btn-sm btn-outline-secondary">Problèmes fréquents</a>
            </div>
        </div>
    </div>

    <div class="accordion" id="accordionGuide">

        {{-- ================= CONNEXION (tout le monde) ================= --}}
        <div class="accordion-item" id="connexion">
            <h2 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#gConnexion">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Se connecter
                </button>
            </h2>
            <div id="gConnexion" class="accordion-collapse collapse show" data-bs-parent="#accordionGuide">
                <div class="accordion-body">
                    <ol>
                        <li>Sur l'écran de connexion, saisir son <strong>nom d'utilisateur</strong>
                            (pas d'e-mail).</li>
                        <li>Saisir son <strong>code à 4 chiffres</strong> (le champ "Mot de passe") —
                            l'icône en forme d'œil permet de l'afficher en clair pour vérifier
                            la saisie.</li>
                        <li>Cliquer sur <em>Se connecter</em>.</li>
                    </ol>
                    <p class="mb-0">
                        Code oublié ou mauvaise saisie répétée ? Contacter le gérant ou le
                        superadmin : lui seul peut réinitialiser un compte utilisateur.
                    </p>
                </div>
            </div>
        </div>

        {{-- ================= CAISSE ================= --}}
        @can('caisse.ouvrir')
        <div class="accordion-item" id="caisse">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#gCaisse">
                    <i class="bi bi-shop me-2"></i>Caisse &amp; ventes
                </button>
            </h2>
            <div id="gCaisse" class="accordion-collapse collapse" data-bs-parent="#accordionGuide">
                <div class="accordion-body">
                    <h6>Ouvrir une session de caisse</h6>
                    <ol>
                        <li>Menu <strong>Vente → Facture/BL</strong>, choisir une caisse <em>libre</em>
                            (sans pastille "session ouverte").</li>
                        <li>Cliquer sur <em>Ouvrir</em>, saisir le <strong>fond de caisse</strong>
                            (montant en espèces déjà dans le tiroir au départ).</li>
                    </ol>
                    <p>
                        Impossible de vendre sans une session ouverte sur une caisse libre —
                        même pour un gérant ou un superadmin. Une caisse ne peut avoir
                        qu'une seule session ouverte à la fois.
                    </p>

                    <h6>Faire une vente</h6>
                    <ol>
                        <li>Depuis la session ouverte, cliquer sur <em>Nouvelle vente</em>.</li>
                        <li>Ajouter les produits au panier (chercher par nom ou SKU) ; si un
                            produit a plusieurs conditionnements (ex. rouleau, carton...),
                            choisir la bonne unité de vente.</li>
                        <li>Un produit affiché <strong>grisé</strong> ("rupture de stock") ne peut
                            pas être ajouté — le stock disponible est insuffisant.</li>
                        @can('vente.remise')
                            <li>Appliquer une remise si besoin (par ligne et/ou sur le total,
                                en montant ou en pourcentage).</li>
                        @endcan
                        <li>Choisir un client (optionnel pour une vente comptant) — si un
                            client a un <strong>avoir</strong> disponible (suite à un retour), il est
                            affiché ici et se déduira automatiquement d'une vente à crédit.</li>
                        <li>Saisir le ou les paiements (plusieurs moyens de paiement possibles
                            sur une même vente).</li>
                        <li>Valider — le ticket/la facture s'affiche, avec le bouton
                            <em>Imprimer</em>.</li>
                    </ol>

                    @can('vente.credit')
                        <h6>Vente à crédit</h6>
                        <p>
                            Nécessite un client identifié (pas de vente à crédit anonyme).
                            Le montant payé peut être inférieur au total : le reste devient une
                            dette sur le compte du client, visible sur sa fiche. Si le client a
                            une limite de crédit et que la vente la dépasserait, la vente est
                            bloquée (sauf autorisation spéciale accordée par le gérant).
                        </p>
                    @endcan

                    @can('ventenattente.gerer')
                        <h6>Mettre une vente en attente</h6>
                        <p>
                            Le bouton <em>Mettre en attente</em> met le panier de côté (aucun
                            stock ni paiement affecté) — à reprendre plus tard depuis
                            <strong>Ventes en attente</strong>. Seul le caissier qui l'a créée la voit
                            (le gérant voit celles de tout le magasin et peut les annuler).
                            Une vente en attente doit être finalisée ou annulée avant de pouvoir
                            clôturer la session.
                        </p>
                    @endcan

                    @can('vente.retour')
                        <h6>Retour client</h6>
                        <p>
                            Depuis le détail d'une vente, bouton <em>Retour</em> : sélectionner les
                            lignes et quantités à retourner (jamais plus que ce qui a été
                            vendu). Ça ne rembourse jamais en espèces — ça crédite un
                            <strong>avoir</strong> sur le compte du client, utilisable sur son prochain
                            achat, et remet la marchandise en stock.
                        </p>
                    @endcan

                    @can('client.reglement')
                        <h6>Encaisser une dette (règlement client)</h6>
                        <p>
                            Depuis la fiche client ou le détail d'une vente à crédit, bouton
                            <em>Régler</em> — nécessite une session de caisse ouverte, comme une
                            vente. Paiement partiel ou total, plusieurs moyens de paiement
                            possibles.
                        </p>
                    @endcan

                    @can('caisse.mouvement')
                        <h6>Entrée / sortie de caisse</h6>
                        <p>
                            Sur l'écran de la session ouverte (<strong>Vente → Facture/BL</strong>, puis la
                            session en cours) : formulaire pour enregistrer une entrée ou une
                            sortie d'espèces (appoint, prélèvement...), avec un motif obligatoire.
                            Une sortie ne peut jamais dépasser l'argent réellement disponible
                            dans le tiroir.
                        </p>
                    @endcan

                    @can('caisse.cloturer')
                        <h6>Clôturer la session</h6>
                        <ol>
                            <li>Vérifier qu'il ne reste <strong>aucune vente en attente</strong> sur cette
                                caisse (bloquant sinon).</li>
                            <li>Sur l'écran de la session, bouton <em>Clôturer</em> : compter
                                physiquement les espèces du tiroir et saisir le montant compté.</li>
                            <li>L'écart éventuel (théorique vs compté) est affiché — c'est normal
                                d'avoir un petit écart, il est simplement enregistré.</li>
                        </ol>
                        <p class="mb-0">
                            Une session peut rester ouverte plusieurs jours sans problème pour la
                            vente — un rappel s'affiche juste pour éviter de l'oublier trop
                            longtemps.
                        </p>
                    @endcan
                </div>
            </div>
        </div>
        @endcan

        {{-- ================= DEVIS ================= --}}
        @can('devis.voir')
        <div class="accordion-item" id="devis">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#gDevis">
                    <i class="bi bi-file-earmark-text me-2"></i>Devis
                </button>
            </h2>
            <div id="gDevis" class="accordion-collapse collapse" data-bs-parent="#accordionGuide">
                <div class="accordion-body">
                    <p>
                        Un devis se crée <strong>sans caisse ni session</strong> — c'est un document
                        indicatif pour un client identifié, avec une durée de validité.
                    </p>
                    <ol>
                        @can('devis.gerer')
                            <li>Menu <strong>Vente → Devis → Nouveau devis</strong>, choisir le client,
                                ajouter les lignes.</li>
                            <li>Le devis reste modifiable tant qu'il n'est ni transformé ni
                                expiré.</li>
                        @endcan
                        @can('devis.transformer')
                            <li>Pour le concrétiser en vente : bouton <em>Transformer en vente</em>
                                (nécessite alors une session de caisse ouverte, comme une vente
                                normale) — les prix appliqués sont ceux du catalogue au moment de
                                la transformation, pas ceux affichés sur le devis d'origine.</li>
                        @endcan
                        <li>Un devis passé la date de validité devient <strong>expiré</strong>
                            automatiquement et n'est plus transformable — en dupliquer un
                            nouveau si besoin.</li>
                    </ol>
                    <p class="mb-0">
                        Impression/export possible en PDF ou Excel, avec le même modèle que
                        la facture.
                    </p>
                </div>
            </div>
        </div>
        @endcan

        {{-- ================= CLIENTS ================= --}}
        @can('client.voir')
        <div class="accordion-item" id="clients">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#gClients">
                    <i class="bi bi-people me-2"></i>Clients
                </button>
            </h2>
            <div id="gClients" class="accordion-collapse collapse" data-bs-parent="#accordionGuide">
                <div class="accordion-body">
                    <p>
                        Menu <strong>Vente → Clients</strong> : fiche nom, téléphone, adresse,
                        type de client, et une <strong>limite de crédit</strong> optionnelle (vide ou
                        0 = illimitée).
                    </p>
                    <p @class(['mb-0' => !auth()->user()->can('client.reglement')])>
                        Le <strong>solde</strong> affiché sur la fiche client est calculé automatiquement
                        (ventes à crédit, règlements, retours) — jamais modifié à la main.
                        Un solde négatif est un <strong>avoir</strong> (le client a un crédit chez
                        nous, suite à un retour).
                    </p>
                    @can('client.reglement')
                        <p class="mb-0">
                            Bouton <em>Rembourser l'avoir</em> sur la fiche client : pour rendre
                            effectivement l'argent au client (plutôt que de laisser l'avoir se
                            déduire d'un futur achat).
                        </p>
                    @endcan
                </div>
            </div>
        </div>
        @endcan

        {{-- ================= CATALOGUE & STOCK ================= --}}
        @canany(['produit.voir', 'categorie.gerer', 'stock.voir', 'inventaire.voir'])
        <div class="accordion-item" id="catalogue">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#gCatalogue">
                    <i class="bi bi-box-seam me-2"></i>Catalogue &amp; stock
                </button>
            </h2>
            <div id="gCatalogue" class="accordion-collapse collapse" data-bs-parent="#accordionGuide">
                <div class="accordion-body">
                    @canany(['produit.voir', 'categorie.gerer'])
                        <h6>Produits</h6>
                        <p>
                            Menu <strong>Catalogue → Produits</strong> : SKU (identifiant unique),
                            nom, catégorie, prix pièce (prix de l'unité de base — pas
                            forcément une pièce physique, peut être le mètre, le kilo, le
                            litre...), seuil d'alerte, image. Une <strong>unité de vente</strong>
                            supplémentaire (ex. carton de 12, rouleau de 50 m) peut être ajoutée
                            sur la fiche produit, avec son propre prix.
                        </p>
                    @endcanany
                    @can('stock.voir')
                        <h6>Stock</h6>
                        <p>
                            Le stock affiché est toujours <strong>calculé</strong> à partir de l'historique
                            des mouvements (jamais modifié directement) : ventes, achats,
                            transferts, ajustements, casse, retours. <strong>Stock → État de stock</strong>
                            permet de consulter cet historique.
                        </p>
                    @endcan
                    @can('stock.transferer')
                        <h6>Transfert entre magasins/dépôts</h6>
                        <p>
                            <strong>Stock → Transfert</strong> : choisir le magasin/dépôt source et
                            destination, le produit, la quantité — simple sortie d'un côté,
                            entrée de l'autre.
                        </p>
                    @endcan
                    @can('inventaire.voir')
                        <h6>Inventaire</h6>
                        <p class="mb-0">
                            <strong>Stock → Inventaire</strong> : créer un inventaire pour un magasin,
                            saisir les quantités réellement comptées ; l'écart avec le stock
                            théorique est calculé automatiquement.
                            @can('inventaire.valider')
                                Il doit être <strong>validé</strong> pour que les ajustements
                                correspondants soient appliqués au stock.
                            @endcan
                        </p>
                    @endcan
                </div>
            </div>
        </div>
        @endcanany

        {{-- ================= ACHATS ================= --}}
        @canany(['achat.voir', 'fournisseur.voir'])
        <div class="accordion-item" id="achats">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#gAchats">
                    <i class="bi bi-truck me-2"></i>Achats &amp; fournisseurs
                </button>
            </h2>
            <div id="gAchats" class="accordion-collapse collapse" data-bs-parent="#accordionGuide">
                <div class="accordion-body">
                    @can('achat.creer')
                        <h6>Passer un bon de commande</h6>
                        <ol>
                            <li>Menu <strong>Achats → Bons de commande → Nouveau bon de commande</strong>,
                                choisir le fournisseur.</li>
                            <li>Ajouter les lignes : produit, quantité, prix d'achat (HT) indicatif, et
                                la destination du stock (un magasin ou un dépôt — un bon de commande
                                peut livrer plusieurs sites différents, ligne par ligne).</li>
                            <li><strong>Enregistrer en brouillon</strong> : rien n'est encore engagé, le
                                bon reste modifiable/supprimable. Il devra ensuite être <em>validé</em>
                                (bouton dédié sur sa fiche) puis <em>réceptionné</em> — en une ou
                                plusieurs fois, avec le prix et la destination réellement constatés à
                                chaque livraison — pour que le stock, le coût moyen et la dette
                                fournisseur bougent réellement. C'est le chemin pour une vraie commande
                                envoyée au fournisseur, en attente de livraison.</li>
                            <li><strong>Enregistrer et réceptionner immédiatement</strong> : pour un achat
                                déjà effectué (achat comptant chez le fournisseur) — une seule action
                                qui crée le bon, le valide, et enregistre la réception complète en une
                                fois (stock, coût moyen et dette fournisseur mis à jour tout de suite).
                                Un paiement peut y être saisi ; ce qui n'est pas payé devient une dette
                                sur le compte du fournisseur.</li>
                        </ol>
                    @endcan
                    @can('achat.receptionner')
                        <h6>Réceptionner un bon de commande</h6>
                        <p class="mb-0">
                            Depuis la fiche d'un bon de commande validé, bouton <em>Réceptionner</em> —
                            saisir, par ligne, la quantité réellement arrivée (partielle ou totale), le
                            prix réellement facturé par le fournisseur (peut différer de l'indicatif) et
                            la destination réelle. Chaque réception crée un <strong>bon d'achat</strong>
                            (numéroté, immuable) qui mouvemente le stock et pose la dette pour son reste
                            dû ; on peut réceptionner un même bon de commande plusieurs fois jusqu'à
                            réception complète.
                        </p>
                    @endcan
                    @can('fournisseur.reglement')
                        <h6>Régler un fournisseur</h6>
                        <p>
                            Depuis la fiche fournisseur : réglé soit sur un <strong>bon de commande précis</strong>
                            (partiel ou total, dans la limite de son reste dû), soit en
                            <strong>global</strong> (doit couvrir exactement le solde total du compte, réparti
                            automatiquement sur les bons de commande encore dus). Contrairement à un
                            règlement client, ceci <strong>ne nécessite aucune session de caisse</strong>.
                        </p>
                    @endcan
                    @can('achat.retour')
                        <h6>Retour fournisseur</h6>
                        <p class="mb-0">
                            Depuis le détail d'un bon de commande ayant reçu de la marchandise, bouton
                            <em>Retourner</em> — même principe que le retour client : crédite un avoir
                            sur le compte fournisseur, remet la marchandise en stock, plafonné à ce qui a
                            réellement été reçu.
                        </p>
                    @endcan
                </div>
            </div>
        </div>
        @endcanany

        {{-- ================= TRÉSORERIE ================= --}}
        @can('tresorerie.voir')
        <div class="accordion-item" id="tresorerie">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#gTresorerie">
                    <i class="bi bi-bank me-2"></i>Trésorerie
                </button>
            </h2>
            <div id="gTresorerie" class="accordion-collapse collapse" data-bs-parent="#accordionGuide">
                <div class="accordion-body">
                    <p>
                        Menu <strong>Trésorerie</strong> : répertorie la
                        <strong>Caisse Générale</strong> (un compte unique, permanent), les
                        <strong>Comptes</strong> (banque/autres, créés librement), et toutes les
                        caisses de vente (répertoire, pas seulement celles ouvertes
                        aujourd'hui).
                    </p>
                    <p>
                        La Caisse Générale reçoit <strong>automatiquement</strong> l'argent compté à
                        chaque clôture de session de caissier — aucune action manuelle. Elle
                        alimente aussi la part en espèces d'un règlement fournisseur ou d'un
                        remboursement d'avoir.
                    </p>
                    <p class="mb-0">
                        <strong>Virement</strong> entre deux comptes (ex. Caisse Générale → compte
                        bancaire) : depuis l'écran d'un compte, bouton dédié.
                    </p>
                </div>
            </div>
        </div>
        @endcan

        {{-- ================= RAPPORTS ================= --}}
        @can('rapport.voir')
        <div class="accordion-item" id="rapports">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#gRapports">
                    <i class="bi bi-graph-up me-2"></i>Rapports
                </button>
            </h2>
            <div id="gRapports" class="accordion-collapse collapse" data-bs-parent="#accordionGuide">
                <div class="accordion-body mb-0">
                    <p class="mb-0">
                        Menu <strong>Rapports</strong> : ventes, mouvements de caisse (tiroirs des
                        caissiers), trésorerie, stock sous seuil... Chaque rapport se filtre
                        par date/magasin et s'exporte en PDF ou Excel.
                    </p>
                </div>
            </div>
        </div>
        @endcan

        {{-- ================= ADMINISTRATION ================= --}}
        @canany(['administration.gerer', 'taxe.gerer', 'typeclient.gerer', 'motif.gerer', 'parametre.gerer', 'utilisateur.gerer', 'role.gerer'])
        <div class="accordion-item" id="administration">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#gAdministration">
                    <i class="bi bi-gear me-2"></i>Administration
                </button>
            </h2>
            <div id="gAdministration" class="accordion-collapse collapse" data-bs-parent="#accordionGuide">
                <div class="accordion-body">
                    @can('utilisateur.gerer')
                        <h6>Utilisateurs</h6>
                        <p>
                            <strong>Administration → Utilisateurs</strong> : créer un compte (nom,
                            identifiant, code à 4 chiffres, rôle, magasin de rattachement). Le
                            code peut être réinitialisé ici si un utilisateur l'a oublié.
                        </p>
                    @endcan
                    @can('role.gerer')
                        <h6>Rôles &amp; permissions</h6>
                        <p>
                            <strong>Administration → Rôles</strong> : créer un rôle et cocher les
                            permissions à lui accorder. Les rôles Gérant/Caissier existent par
                            défaut mais restent modifiables comme n'importe quel rôle créé ici.
                        </p>
                    @endcan
                    @canany(['motif.gerer', 'taxe.gerer', 'parametre.gerer'])
                        <h6>Paramètres, motifs, taxes</h6>
                        <p class="mb-0">
                            Réglages généraux (nom/logo de l'entreprise), référentiel des motifs
                            de mouvement de caisse, et des taxes utilisées côté achat — tout est
                            dans le menu <strong>Administration</strong>.
                        </p>
                    @endcanany
                </div>
            </div>
        </div>
        @endcanany

        {{-- ================= DÉPANNAGE (tout le monde) ================= --}}
        <div class="accordion-item" id="depannage">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#gDepannage">
                    <i class="bi bi-life-preserver me-2"></i>Problèmes fréquents
                </button>
            </h2>
            <div id="gDepannage" class="accordion-collapse collapse" data-bs-parent="#accordionGuide">
                <div class="accordion-body mb-0">
                    <dl class="mb-0">
                        @can('caisse.ouvrir')
                            <dt>Impossible de vendre / bouton grisé</dt>
                            <dd>Vérifier qu'une session de caisse est bien ouverte sur une
                                caisse libre.</dd>

                            <dt>Impossible de clôturer la session</dt>
                            <dd>Il reste une vente en attente sur cette caisse — la finaliser
                                ou l'annuler d'abord.</dd>
                        @endcan

                        @can('vente.credit')
                            <dt>Vente à crédit refusée</dt>
                            <dd>Le client a atteint sa limite de crédit — un utilisateur avec
                                le droit de dépasser la limite doit valider, ou encaisser
                                d'abord une partie de sa dette.</dd>
                        @endcan

                        <dt>Produit non trouvable au panier</dt>
                        <dd>Affiché grisé "rupture de stock" : la quantité disponible est
                            insuffisante pour ce magasin.</dd>

                        @can('fournisseur.reglement')
                            <dt>Un règlement fournisseur "global" est refusé</dt>
                            <dd>Le montant saisi doit couvrir exactement le solde total du
                                compte fournisseur — pour un montant partiel, cibler un bon
                                d'achat précis à la place.</dd>
                        @endcan

                        <dt>Écran "serveur injoignable" (application desktop)</dt>
                        <dd>Le poste serveur n'est pas démarré, ou le réseau ne relie pas
                            ce poste au serveur — voir le gérant/l'installateur du poste.</dd>
                    </dl>
                </div>
            </div>
        </div>

    </div>
@endsection
