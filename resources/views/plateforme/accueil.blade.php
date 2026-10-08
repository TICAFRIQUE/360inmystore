{{-- Site vitrine de la plateforme (domaine principal). Les tarifs sont les
     formules actives du catalogue géré par l'éditeur — rien n'est écrit en
     dur ici. Les modules présentés reprennent le menu de l'application
     (partials/sidebar.blade.php) : un écran ajouté ou retiré là-bas doit
     l'être aussi dans $modules ci-dessous. Dans les textes, l'espace qui
     précède « : » et « ? » est insécable, pour que le signe ne passe jamais
     seul à la ligne. --}}
@extends('layouts.plateforme')

@section('title', 'Caisse, stock et crédit client pour votre commerce')
@section('description', config('app.name')." fait tourner la caisse, le stock, les achats, le crédit client et la trésorerie de votre commerce, dans votre navigateur. Un magasin ou plusieurs, quel que soit votre secteur.")
@section('contenu-classe', 'plateforme-public__contenu--pleine')

@php
    // id : ancre de la section et nom de l'illustration
    // (apercus/{id}). teinte : accent de la section, parmi
    // les couleurs du thème. points : [icône, sous-module, ce qu'il fait].
    $modules = [
        [
            'id' => 'vente', 'icone' => 'bi-cart3', 'nom' => 'Vente', 'teinte' => 'primary',
            'resume' => 'Caisse, devis, clients, avoirs',
            'titre' => 'Encaissez vite, au comptant comme à crédit',
            'texte' => "Une caisse pensée pour le comptoir : on cherche, on ajoute, on encaisse. Le stock et le compte du client se mettent à jour au même instant.",
            'points' => [
                ['bi-cash-stack', 'Caisse et factures', 'Ticket ou facture, remises par ligne ou sur le total, paiement en un ou plusieurs moyens.'],
                ['bi-pause-circle', 'Ventes en attente', 'Mettez un panier de côté et reprenez-le plus tard, sans bloquer la file.'],
                ['bi-file-earmark-text', 'Devis', 'Imprimable, en PDF ou en Excel, puis transformé en vente depuis la caisse.'],
                ['bi-people', 'Clients et crédit', 'Un compte par client, une limite de crédit, des règlements partiels ou complets.'],
                ['bi-arrow-return-left', 'Retours', 'Retour ligne par ligne le jour de la vente, remboursé ou gardé en avoir.'],
                ['bi-piggy-bank', 'Avoirs clients', "Tous les avoirs sur une seule liste, déduits d'une prochaine vente ou remboursés."],
            ],
        ],
        [
            'id' => 'achats', 'icone' => 'bi-truck', 'nom' => 'Achats', 'teinte' => 'info',
            'resume' => 'Fournisseurs, bons de commande',
            'titre' => 'Commandez, recevez, payez vos fournisseurs',
            'texte' => "Du bon de commande à la dernière livraison : ce qui est arrivé, à quel prix, dans quel magasin, et ce qu'il reste à payer.",
            'points' => [
                ['bi-building', 'Fournisseurs', 'Une fiche et un compte par fournisseur, avec sa dette toujours à jour.'],
                ['bi-receipt', 'Bons de commande', 'Préparés en brouillon, validés, imprimés ou envoyés en PDF et en Excel.'],
                ['bi-box-arrow-in-down', 'Réceptions échelonnées', 'Une commande reçue en une ou plusieurs fois, au prix réellement facturé.'],
                ['bi-wallet2', 'Règlements', "Payez à la réception ou plus tard, en totalité ou une partie d'un bon précis."],
                ['bi-arrow-return-right', 'Retours fournisseur', 'Marchandise renvoyée, avoir crédité sur le compte du fournisseur.'],
                ['bi-calculator', 'Coût moyen pondéré', 'Recalculé à chaque réception : vos marges restent justes dans le temps.'],
            ],
        ],
        [
            'id' => 'stock', 'icone' => 'bi-boxes', 'nom' => 'Stock', 'teinte' => 'success',
            'resume' => 'État, transferts, inventaires',
            'titre' => 'Un stock toujours juste, magasin par magasin',
            'texte' => "Chaque entrée et chaque sortie laisse une trace. Vous savez à tout moment ce que vous avez, où, et ce qu'il faut recommander.",
            'points' => [
                ['bi-clipboard-data', 'État de stock', "Quantités par magasin et par dépôt, avec alerte sous le seuil que vous fixez."],
                ['bi-arrow-left-right', 'Transferts', "D'un magasin ou d'un dépôt à l'autre, en une seule opération."],
                ['bi-sliders', 'Casse et ajustements', 'Une perte ou une correction se déclare avec son motif.'],
                ['bi-clipboard-check', 'Inventaires', "Saisissez ce que vous comptez : l'écart est calculé pour vous."],
                ['bi-clock-history', 'Historique des mouvements', "Qui a fait quoi, quand et pourquoi — rien n'est jamais effacé."],
                ['bi-house-gear', 'Dépôts', 'Des lieux de stockage sans caisse, gérés comme vos magasins.'],
            ],
        ],
        [
            'id' => 'catalogue', 'icone' => 'bi-tags', 'nom' => 'Catalogue', 'teinte' => 'warning',
            'resume' => 'Produits, catégories, unités',
            'titre' => 'Votre catalogue, à votre façon',
            'texte' => "À la pièce, au mètre, au kilo ou au carton : décrivez vos produits comme vous les vendez vraiment, quel que soit votre métier.",
            'points' => [
                ['bi-box-seam', 'Produits', "Référence unique, image, prix et seuil d'alerte pour chaque produit."],
                ['bi-stack', 'Unités de vente', 'Pièce, carton, rouleau, sac… chacune avec son propre prix.'],
                ['bi-diagram-3', 'Catégories', 'Rangez vos produits pour les retrouver vite à la caisse.'],
                ['bi-slash-circle', 'Ruptures signalées', "Un produit épuisé s'affiche grisé à la caisse : aucune vente impossible."],
            ],
        ],
        [
            'id' => 'tresorerie', 'icone' => 'bi-bank', 'nom' => 'Trésorerie', 'teinte' => 'primary',
            'resume' => 'Caisse générale, comptes, virements',
            'titre' => "Suivez l'argent, du tiroir à la banque",
            'texte' => "Ce que chaque caisse a encaissé, ce qui a été déposé, ce qui est sorti : vous voyez où est votre argent sans rien recopier.",
            'points' => [
                ['bi-safe', 'Caisse générale', 'Reçoit automatiquement le montant compté à chaque clôture de caisse.'],
                ['bi-credit-card-2-front', 'Comptes', 'Banque et autres comptes, chacun avec son solde et son historique.'],
                ['bi-arrow-repeat', 'Virements internes', "D'un compte à l'autre, par exemple de la caisse générale vers la banque."],
                ['bi-journal-arrow-down', 'Entrées et sorties', 'Chaque mouvement porte un motif, un montant et son auteur.'],
                ['bi-lock', 'Clôtures de caisse', "Comptage du tiroir en fin de session, avec l'écart constaté."],
                ['bi-shop-window', 'Caisses de vente', "L'historique complet de chaque caisse, toutes sessions confondues."],
            ],
        ],
        [
            'id' => 'rapports', 'icone' => 'bi-bar-chart-line', 'nom' => 'Rapports', 'teinte' => 'info',
            'resume' => 'Ventes, marges, écarts, exports',
            'titre' => 'Décidez avec des chiffres clairs',
            'texte' => "Un tableau de bord adapté à chaque rôle, et des rapports que vous filtrez par période et par magasin avant de les exporter.",
            'points' => [
                ['bi-graph-up-arrow', 'Ventes et marges', "Chiffre d'affaires, panier moyen, marge réelle par produit."],
                ['bi-clipboard-data', 'Stock et inventaires', 'Valeur du stock, produits sous le seuil, écarts constatés.'],
                ['bi-cash-coin', 'Caisse et trésorerie', 'Mouvements de caisse, écarts de clôture, flux de trésorerie.'],
                ['bi-trash3', 'Casse', 'Ce qui a été perdu, quand, et pour quel motif.'],
                ['bi-speedometer2', 'Tableau de bord', 'Le caissier voit sa session, le gérant son magasin, vous voyez tout.'],
                ['bi-download', 'Exports', 'Chaque liste et chaque rapport en PDF, Excel, CSV ou à imprimer.'],
            ],
        ],
        [
            'id' => 'administration', 'icone' => 'bi-gear', 'nom' => 'Administration', 'teinte' => 'secondary',
            'resume' => 'Magasins, utilisateurs, paramètres',
            'titre' => "Réglez l'outil à l'image de votre commerce",
            'texte' => "Vos magasins, vos employés, vos moyens de paiement, votre nom et vos couleurs : tout se règle à l'écran, sans technicien.",
            'points' => [
                ['bi-shop', 'Magasins et caisses', 'Un magasin ou plusieurs, avec leurs caisses et leurs dépôts.'],
                ['bi-shield-lock', 'Utilisateurs et rôles', 'Créez vos propres rôles et choisissez les permissions une à une.'],
                ['bi-credit-card', 'Moyens de paiement', 'Espèces, mobile money, carte… activez ceux que vous acceptez.'],
                ['bi-rulers', 'Unités, taxes et devises', 'Vos unités de mesure, les taxes de vos achats, votre devise.'],
                ['bi-person-badge', 'Types de client et motifs', 'Vos propres catégories de clients et motifs de mouvement.'],
                ['bi-palette', 'Votre identité', 'Le nom, le logo et la couleur de votre commerce, sur les écrans et les documents.'],
            ],
        ],
    ];

    $questions = [
        ['Faut-il installer quelque chose ?', config('app.name')." s'utilise dans votre navigateur, sur ordinateur, tablette ou téléphone : il n'y a rien à installer ni à mettre à jour. Il faut simplement une connexion internet."],
        ['Mon activité est-elle prise en charge ?', "Oui, dès lors que vous vendez des produits : boutique, alimentation, quincaillerie, pièces détachées, papeterie… Le catalogue, les unités de vente, les types de client et les motifs de mouvement se règlent selon votre métier."],
        ['Puis-je gérer plusieurs magasins et des dépôts ?', "Oui. Chaque magasin a son stock et ses caisses, les dépôts servent au stockage, et vous transférez la marchandise de l'un à l'autre. Les rapports se lisent magasin par magasin ou tous ensemble."],
        ['Mes employés ont-ils chacun leur accès ?', "Oui. Chaque employé a son propre compte, et vous décidez de ce qu'il peut faire grâce à des rôles dont vous choisissez les permissions. Les actions sensibles sont enregistrées avec leur auteur et leur date."],
        ['Mes données sont-elles séparées de celles des autres commerces ?', "Oui. Chaque commerce dispose de son propre espace, à sa propre adresse, avec sa propre base de données : personne d'autre n'y a accès."],
        ['Puis-je récupérer mes données ?', "Oui. Toutes les listes et tous les rapports s'exportent en PDF, Excel et CSV, quand vous le voulez."],
        ['Comment choisir ma formule ?', $essai
            ? "Vous créez votre compte, puis vous choisissez votre formule dans votre espace. L'essai gratuit de {$essai->jours} jours vous laisse tout découvrir avant de vous décider."
            : "Vous créez votre compte, puis vous choisissez la formule qui vous convient directement dans votre espace."],
    ];
@endphp

@section('navigation')
    <div class="dropdown">
        <button type="button" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">Modules</button>
        <div class="dropdown-menu vitrine-menu">
            <div class="vitrine-menu__grille">
                @foreach ($modules as $module)
                    <a href="#{{ $module['id'] }}" class="vitrine-menu__lien">
                        <span class="vitrine-pastille vitrine-pastille--{{ $module['teinte'] }}"><i class="bi {{ $module['icone'] }}"></i></span>
                        <span>
                            <span class="vitrine-menu__nom">{{ $module['nom'] }}</span>
                            <span class="vitrine-menu__resume">{{ $module['resume'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
    <a href="#secteurs" class="nav-link">Secteurs</a>
    <a href="#demarrer" class="nav-link">Démarrer</a>
    <a href="#tarifs" class="nav-link">Tarifs</a>
    <a href="#questions" class="nav-link">Questions</a>
@endsection

@section('navigation-mobile')
    {{-- Un lien suivi referme le panneau (data-bs-dismiss bloquerait la
         navigation vers l'ancre). --}}
    <nav class="vitrine-mobile" aria-label="Navigation principale"
         x-data @click="if ($event.target.closest('a')) window.bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('menuVitrine')).hide()">
        <div class="vitrine-mobile__titre">Modules</div>
        @foreach ($modules as $module)
            <a href="#{{ $module['id'] }}" class="vitrine-mobile__lien">
                <span class="vitrine-pastille vitrine-pastille--{{ $module['teinte'] }}"><i class="bi {{ $module['icone'] }}"></i></span>
                {{ $module['nom'] }}
            </a>
        @endforeach

        <div class="vitrine-mobile__titre">La plateforme</div>
        <a href="#secteurs" class="vitrine-mobile__lien">Secteurs</a>
        <a href="#demarrer" class="vitrine-mobile__lien">Démarrer</a>
        <a href="#tarifs" class="vitrine-mobile__lien">Tarifs</a>
        <a href="#questions" class="vitrine-mobile__lien">Questions fréquentes</a>

        <div class="d-grid gap-2 mt-4">
            <a href="#acces" class="btn btn-outline-secondary">Accéder à mon espace</a>
            @if ($inscriptionOuverte)
                <a href="{{ route('plateforme.inscription') }}" class="btn btn-primary">Créer un compte</a>
            @endif
        </div>
    </nav>
@endsection

@section('entete')
    <a href="#acces" class="btn btn-outline-secondary d-none d-md-inline-block">Accéder à mon espace</a>
    @if ($inscriptionOuverte)
        <a href="{{ route('plateforme.inscription') }}" class="btn btn-primary d-none d-sm-inline-block">Créer un compte</a>
    @endif
@endsection

@section('content')
    <div class="vitrine">
        {{-- Présentation --}}
        <section class="vitrine__hero">
            <div class="vitrine__conteneur vitrine__hero-grille">
                <div>
                    <span class="vitrine__etiquette"><i class="bi bi-stars"></i>Tous commerces · un ou plusieurs magasins</span>
                    <h1 class="vitrine__titre">Vos ventes, votre stock et votre caisse, <span class="text-primary">dans un seul outil.</span></h1>
                    <p class="vitrine__accroche">
                        {{ config('app.name') }} fait tourner la caisse, le stock, les achats, le crédit client et la
                        trésorerie de votre commerce. Sans installation : tout se passe dans votre navigateur.
                    </p>

                    <div class="vitrine__boutons">
                        @if ($inscriptionOuverte)
                            <a href="{{ route('plateforme.inscription') }}" class="btn btn-primary btn-lg">
                                Créer un compte<i class="bi bi-arrow-right ms-2"></i>
                            </a>
                        @endif
                        <a href="#modules" class="btn btn-outline-secondary btn-lg">Découvrir les modules</a>
                    </div>

                    <ul class="vitrine__garanties">
                        @if ($inscriptionOuverte && $essai)
                            <li><i class="bi bi-check-circle-fill"></i>Essai gratuit de {{ $essai->jours }} jours</li>
                        @endif
                        <li><i class="bi bi-check-circle-fill"></i>Prêt en une minute</li>
                        <li><i class="bi bi-check-circle-fill"></i>Ordinateur, tablette, téléphone</li>
                    </ul>
                </div>

                <div class="vitrine__visuel">
                    @include('apercus.accueil')
                    <div class="vitrine__bulle vitrine__bulle--haut" aria-hidden="true">
                        <span class="vitrine-pastille vitrine-pastille--success"><i class="bi bi-check2"></i></span>
                        <span><strong>Vente encaissée</strong><small>9 025 F · Espèces</small></span>
                    </div>
                    <div class="vitrine__bulle vitrine__bulle--bas" aria-hidden="true">
                        <span class="vitrine-pastille vitrine-pastille--primary"><i class="bi bi-boxes"></i></span>
                        <span><strong>Stock mis à jour</strong><small>à chaque vente</small></span>
                    </div>
                </div>
            </div>
        </section>

        {{-- Repères --}}
        <section class="vitrine__reperes" aria-label="En bref">
            <div class="vitrine__conteneur vitrine__reperes-grille">
                @foreach ([
                    ['bi-grid-1x2', count($modules).' modules', 'De la caisse aux rapports'],
                    ['bi-shop', 'Multi-magasin', 'Magasins, caisses et dépôts'],
                    ['bi-shield-check', 'Tout est tracé', 'Chaque action sensible, avec son auteur'],
                    ['bi-download', 'PDF, Excel, CSV', 'Vos données, quand vous voulez'],
                ] as [$icone, $titre, $texte])
                    <div class="vitrine__repere">
                        <i class="bi {{ $icone }}"></i>
                        <div>
                            <strong>{{ $titre }}</strong>
                            <span>{{ $texte }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Modules --}}
        <section class="vitrine__section" id="modules">
            <div class="vitrine__conteneur">
                <div class="vitrine__entete">
                    <span class="vitrine__surtitre">Les modules</span>
                    <h2 class="vitrine__sous-titre">Tout ce qu'il faut pour gérer un commerce</h2>
                    <p class="vitrine__intro">Chaque module fait son travail et ils se parlent entre eux : une vente met le stock, la caisse et le compte du client à jour d'un seul geste.</p>
                </div>

                <nav class="vitrine__onglets" aria-label="Modules">
                    @foreach ($modules as $module)
                        <a href="#{{ $module['id'] }}" class="vitrine__onglet"><i class="bi {{ $module['icone'] }}"></i>{{ $module['nom'] }}</a>
                    @endforeach
                </nav>

                @foreach ($modules as $module)
                    <article class="vitrine-module vitrine-module--{{ $module['teinte'] }} @if ($loop->even) vitrine-module--inverse @endif" id="{{ $module['id'] }}">
                        <div class="vitrine-module__texte">
                            <span class="vitrine-module__nom">
                                <span class="vitrine-pastille vitrine-pastille--{{ $module['teinte'] }}"><i class="bi {{ $module['icone'] }}"></i></span>
                                {{ $module['nom'] }}
                            </span>
                            <h3 class="vitrine-module__titre">{{ $module['titre'] }}</h3>
                            <p class="vitrine-module__accroche">{{ $module['texte'] }}</p>

                            <ul class="vitrine-module__points">
                                @foreach ($module['points'] as [$icone, $titre, $texte])
                                    <li>
                                        <i class="bi {{ $icone }}"></i>
                                        <div>
                                            <strong>{{ $titre }}</strong>
                                            <span>{{ $texte }}</span>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        <div class="vitrine-module__visuel">
                            @include('apercus.'.$module['id'])
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        {{-- Secteurs --}}
        <section class="vitrine__section vitrine__section--blanche" id="secteurs">
            <div class="vitrine__conteneur">
                <div class="vitrine__entete">
                    <span class="vitrine__surtitre">Pour qui ?</span>
                    <h2 class="vitrine__sous-titre">Fait pour tous les commerces</h2>
                    <p class="vitrine__intro">Rien n'est figé pour un métier précis : ce qui change d'un commerce à l'autre se règle dans les paramètres.</p>
                </div>

                <div class="vitrine__secteurs">
                    @foreach ([
                        ['bi-bag', 'Boutique et prêt-à-porter'],
                        ['bi-basket', 'Alimentation et supérette'],
                        ['bi-tools', 'Quincaillerie et matériaux'],
                        ['bi-gear-wide-connected', 'Pièces détachées'],
                        ['bi-phone', 'Électronique et téléphonie'],
                        ['bi-book', 'Librairie et papeterie'],
                        ['bi-droplet', 'Cosmétique et beauté'],
                        ['bi-box2', 'Grossiste et demi-gros'],
                    ] as [$icone, $secteur])
                        <div class="vitrine__secteur"><i class="bi {{ $icone }}"></i>{{ $secteur }}</div>
                    @endforeach
                </div>
                <p class="vitrine__note">Votre activité n'est pas dans la liste ? Si vous vendez des produits, {{ config('app.name') }} vous convient.</p>
            </div>
        </section>

        {{-- Démarrage --}}
        <section class="vitrine__section" id="demarrer">
            <div class="vitrine__conteneur">
                <div class="vitrine__entete">
                    <span class="vitrine__surtitre">Démarrer</span>
                    <h2 class="vitrine__sous-titre">Prêt en trois étapes</h2>
                </div>

                <ol class="vitrine__etapes">
                    @foreach ([
                        ['bi-person-plus', 'Créez votre compte', 'Le nom de votre commerce, votre téléphone, un mot de passe : votre espace est prêt en une minute.'],
                        ['bi-card-checklist', 'Choisissez votre formule', 'Vous arrivez directement dans votre espace pour choisir la formule qui vous convient.'],
                        ['bi-cart-check', 'Vendez', 'Ajoutez vos produits, ouvrez votre caisse, invitez vos employés.'],
                    ] as $numero => [$icone, $titre, $texte])
                        <li class="vitrine__etape">
                            <span class="vitrine__numero">{{ $numero + 1 }}</span>
                            <i class="bi {{ $icone }} vitrine__etape-icone"></i>
                            <h3 class="vitrine__etape-titre">{{ $titre }}</h3>
                            <p class="text-secondary mb-0">{{ $texte }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- Tarifs --}}
        <section class="vitrine__section vitrine__section--blanche" id="tarifs">
            <div class="vitrine__conteneur">
                <div class="vitrine__entete">
                    <span class="vitrine__surtitre">Tarifs</span>
                    <h2 class="vitrine__sous-titre">Une formule pour chaque commerce</h2>
                    <p class="vitrine__intro">Vous choisissez votre formule après avoir créé votre compte.</p>
                </div>

                @if ($formules->isEmpty())
                    <p class="text-center text-secondary mb-0">Les tarifs seront bientôt disponibles.</p>
                @else
                    <x-tarifs :formules="$formules" action="plateforme._action-tarif" />
                @endif
            </div>
        </section>

        {{-- Questions fréquentes --}}
        <section class="vitrine__section" id="questions">
            <div class="vitrine__conteneur">
                <div class="vitrine__entete">
                    <span class="vitrine__surtitre">Questions fréquentes</span>
                    <h2 class="vitrine__sous-titre">Ce qu'on nous demande souvent</h2>
                </div>

                <div class="accordion vitrine__questions" id="questionsFrequentes">
                    @foreach ($questions as $i => [$question, $reponse])
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button type="button" class="accordion-button @if ($i > 0) collapsed @endif" data-bs-toggle="collapse" data-bs-target="#question{{ $i }}"
                                        aria-expanded="{{ $i === 0 ? 'true' : 'false' }}" aria-controls="question{{ $i }}">
                                    {{ $question }}
                                </button>
                            </h3>
                            <div id="question{{ $i }}" class="accordion-collapse collapse @if ($i === 0) show @endif" data-bs-parent="#questionsFrequentes">
                                <div class="accordion-body">{{ $reponse }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Accès à son espace --}}
        <section class="vitrine__final" id="acces">
            <div class="vitrine__conteneur vitrine__final-grille">
                <div>
                    <h2 class="vitrine__final-titre">Prêt à mettre de l'ordre dans votre commerce ?</h2>
                    <p class="vitrine__final-texte">Créez votre espace maintenant et commencez à vendre aujourd'hui. Déjà client ? Retrouvez votre espace avec son adresse.</p>
                    @if ($inscriptionOuverte)
                        <a href="{{ route('plateforme.inscription') }}" class="btn btn-light btn-lg">
                            Créer un compte<i class="bi bi-arrow-right ms-2"></i>
                        </a>
                    @endif
                </div>

                <div class="plateforme-public__carte vitrine__acces">
                    <h3 class="h5 mb-1">Accéder à mon espace</h3>
                    <p class="text-secondary small mb-3">Saisissez l'adresse de votre espace pour vous connecter.</p>

                    <form method="POST" action="{{ route('plateforme.espace') }}">
                        @csrf
                        <label for="sous_domaine" class="form-label">Adresse de votre espace</label>
                        <div class="input-group mb-3 @error('sous_domaine') has-validation @enderror">
                            <input type="text" name="sous_domaine" id="sous_domaine" class="form-control @error('sous_domaine') is-invalid @enderror"
                                   value="{{ old('sous_domaine') }}" placeholder="ma-boutique" autocapitalize="none" autocomplete="off" required>
                            <span class="input-group-text">.{{ $domaine }}</span>
                            @error('sous_domaine') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Continuer</button>
                    </form>
                </div>
            </div>
        </section>
    </div>
@endsection

@section('pied')
    <footer class="vitrine-pied">
        <div class="vitrine__conteneur">
            <div class="vitrine-pied__grille">
                <div class="vitrine-pied__marque">
                    <img src="{{ asset('images/logo/fond-sombre/360inmystore-horizontal-fond-sombre.svg') }}" alt="{{ config('app.name') }}" width="523" height="124">
                    <p>Caisse, stock, achats, crédit client et trésorerie pour tous les commerces — un magasin ou plusieurs.</p>
                </div>

                <nav aria-label="Modules">
                    <h2 class="vitrine-pied__titre">Modules</h2>
                    <ul class="vitrine-pied__liens vitrine-pied__liens--double">
                        @foreach ($modules as $module)
                            <li><a href="#{{ $module['id'] }}">{{ $module['nom'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>

                <nav aria-label="La plateforme">
                    <h2 class="vitrine-pied__titre">La plateforme</h2>
                    <ul class="vitrine-pied__liens">
                        <li><a href="#secteurs">Secteurs</a></li>
                        <li><a href="#demarrer">Démarrer</a></li>
                        <li><a href="#tarifs">Tarifs</a></li>
                        <li><a href="#questions">Questions fréquentes</a></li>
                    </ul>
                </nav>

                <div>
                    <h2 class="vitrine-pied__titre">Votre compte</h2>
                    <ul class="vitrine-pied__liens">
                        @if ($inscriptionOuverte)
                            <li><a href="{{ route('plateforme.inscription') }}">Créer un compte</a></li>
                        @endif
                        <li><a href="#acces">Accéder à mon espace</a></li>
                        @if ($contact->telephone)
                            <li><a href="tel:{{ $contact->telephone }}"><i class="bi bi-telephone me-2"></i>{{ telephone($contact->telephone) }}</a></li>
                        @endif
                        @if ($contact->email)
                            <li><a href="mailto:{{ $contact->email }}"><i class="bi bi-envelope me-2"></i>{{ $contact->email }}</a></li>
                        @endif
                        @if ($contact->whatsapp)
                            <li><a href="https://wa.me/{{ ltrim($contact->whatsapp, '+') }}" target="_blank" rel="noopener"><i class="bi bi-whatsapp me-2"></i>WhatsApp</a></li>
                        @endif
                    </ul>
                </div>
            </div>

            <div class="vitrine-pied__bas">
                <span>© {{ date('Y') }} {{ config('app.name') }}</span>
                <a href="{{ route('login') }}">Connexion de l'éditeur</a>
            </div>
        </div>
    </footer>
@endsection
