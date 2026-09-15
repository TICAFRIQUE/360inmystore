<ul class="navbar-nav flex-column flex-lg-row flex-lg-wrap column-gap-1 row-gap-1">
    <li class="nav-item">
        <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <i class="bi bi-speedometer2 me-1"></i>Tableau de bord
        </a>
    </li>

    @canany(['produit.voir', 'categorie.gerer'])
        <li class="nav-item dropdown">
            <a href="#"
                class="nav-link dropdown-toggle {{ request()->routeIs('categories.*', 'produits.*') ? 'active' : '' }}"
                data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-tags me-1"></i>Catalogue
            </a>
            <ul class="dropdown-menu">
                @can('categorie.gerer')
                    <li>
                        <a href="{{ route('categories.index') }}"
                            class="dropdown-item {{ request()->routeIs('categories.*') ? 'active' : '' }}">
                            <i class="bi bi-tags me-2"></i>Catégories
                        </a>
                    </li>
                @endcan
                @can('produit.voir')
                    <li>
                        <a href="{{ route('produits.index') }}"
                            class="dropdown-item {{ request()->routeIs('produits.*') ? 'active' : '' }}">
                            <i class="bi bi-cup-hot me-2"></i>Produits
                        </a>
                    </li>
                @endcan
            </ul>
        </li>
    @endcanany


    @canany(['stock.voir', 'inventaire.voir', 'achat.voir', 'fournisseur.voir'])
        <li class="nav-item dropdown">
            <a href="#"
                class="nav-link dropdown-toggle {{ request()->routeIs('stock.*', 'transferts.*', 'inventaires.*', 'fournisseurs.*', 'reglements-fournisseur.*', 'commande-achats.*') ? 'active' : '' }}"
                data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-boxes me-1"></i>Stock
            </a>
            <ul class="dropdown-menu">
                @can('fournisseur.voir')
                    <li>
                        <a href="{{ route('fournisseurs.index') }}"
                            class="dropdown-item {{ request()->routeIs('fournisseurs.*', 'reglements-fournisseur.*') ? 'active' : '' }}">
                            <i class="bi bi-building me-2"></i>Fournisseurs
                        </a>
                    </li>
                @endcan
                @can('achat.voir')
                    <li>
                        <a href="{{ route('commande-achats.index') }}"
                            class="dropdown-item {{ request()->routeIs('commande-achats.*') ? 'active' : '' }}">
                            <i class="bi bi-truck me-2"></i>Bons de commande
                        </a>
                    </li>
                @endcan

            @endcan
            @can('stock.transferer')
                <li>
                    <a href="{{ route('transferts.create') }}"
                        class="dropdown-item {{ request()->routeIs('transferts.*') ? 'active' : '' }}">
                        <i class="bi bi-arrow-left-right me-2"></i>Transfert
                    </a>
                </li>
            @endcan
            @can('stock.ajuster')
                <li>
                    <a href="{{ route('stock.mouvements.create') }}"
                        class="dropdown-item {{ request()->routeIs('stock.mouvements.create', 'stock.mouvements.store') ? 'active' : '' }}">
                        <i class="bi bi-sliders me-2"></i>Casse / ajustement
                    </a>
                </li>
            @endcan
            @can('inventaire.voir')
                <li>
                    <a href="{{ route('inventaires.index') }}"
                        class="dropdown-item {{ request()->routeIs('inventaires.*') ? 'active' : '' }}">
                        <i class="bi bi-clipboard-check me-2"></i>Inventaire
                    </a>
                </li>
            @endcan
            @can('stock.voir')
                <li>
                    <a href="{{ route('stock.index') }}"
                        class="dropdown-item {{ request()->routeIs('stock.index') ? 'active' : '' }}">
                        <i class="bi bi-boxes me-2"></i>État de stock
                    </a>
                </li>
                <li>
                    <a href="{{ route('stock.mouvements.index') }}"
                        class="dropdown-item {{ request()->routeIs('stock.mouvements.index') ? 'active' : '' }}">
                        <i class="bi bi-clock-history me-2"></i>Historique des mouvements
                    </a>
                </li>
            </ul>
        </li>
    @endcanany

    @canany(['caisse.ouvrir', 'client.voir', 'devis.voir', 'vente.livrer', 'client.reglement'])
        <li class="nav-item dropdown">
            <a href="#"
                class="nav-link dropdown-toggle {{ request()->routeIs('sessions.*', 'ventes.*', 'ventes-en-attente.*', 'reglements.*', 'devis.*', 'clients.*', 'bons-livraison.*', 'remboursements-avoir-client.*') ? 'active' : '' }}"
                data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-cart3 me-1"></i>Vente
            </a>
            <ul class="dropdown-menu">
                @can('caisse.ouvrir')
                    <li>
                        {{-- Session déjà ouverte par ce caissier : y aller directement
                             plutôt que de repasser par la sélection de caisse (règle 7,
                             voir AppServiceProvider — sessionCaissierOuverteMenu). --}}
                        <a href="{{ $sessionCaissierOuverteMenu ? route('sessions.show', $sessionCaissierOuverteMenu) : route('sessions.index') }}"
                            class="dropdown-item {{ request()->routeIs('sessions.*', 'ventes.*', 'ventes-en-attente.*', 'reglements.*') ? 'active' : '' }}">
                            <i class="bi bi-cash-stack me-2"></i>Facture/BL
                        </a>
                    </li>
                @endcan
                @can('devis.voir')
                    <li>
                        <a href="{{ route('devis.index') }}"
                            class="dropdown-item {{ request()->routeIs('devis.*') ? 'active' : '' }}">
                            <i class="bi bi-file-earmark-text me-2"></i>Devis
                        </a>
                    </li>
                @endcan
                @can('client.voir')
                    <li>
                        <a href="{{ route('clients.index') }}"
                            class="dropdown-item {{ request()->routeIs('clients.*') ? 'active' : '' }}">
                            <i class="bi bi-people-fill me-2"></i>Clients
                        </a>
                    </li>
                @endcan
                {{-- Onglet "Bon de livraison" retiré du menu pour le moment (demande
                     utilisateur) — route/contrôleur/permission vente.livrer inchangés,
                     seule l'entrée de menu est masquée. --}}
                @can('client.reglement')
                    <li>
                        <a href="{{ route('remboursements-avoir-client.index') }}"
                            class="dropdown-item {{ request()->routeIs('remboursements-avoir-client.*') ? 'active' : '' }}">
                            <i class="bi bi-piggy-bank me-2"></i>Avoirs clients
                        </a>
                    </li>
                @endcan
            </ul>
        </li>
    @endcanany


    @can('tresorerie.voir')
        <li class="nav-item">
            <a href="{{ route('comptabilite.caisses.index') }}"
                class="nav-link {{ request()->routeIs('comptabilite.*') ? 'active' : '' }}">
                <i class="bi bi-bank me-1"></i>Trésorerie
            </a>
        </li>
    @endcan


    @can('rapport.voir')
        <li class="nav-item">
            <a href="{{ route('rapports.index') }}"
                class="nav-link {{ request()->routeIs('rapports.*') ? 'active' : '' }}">
                <i class="bi bi-bar-chart-line me-1"></i>Rapports
            </a>
        </li>
    @endcan

    {{-- L'entrée "Abonnement" du sous-menu (voir plus bas) est réservée en dur
         au Superadmin/développeur (User::estGestionnaireAbonnement()), jamais
         via une permission Spatie — donc ce menu doit s'afficher pour ce
         compte même s'il n'a par ailleurs aucune permission d'administration
         (cas du compte développeur sans autre rôle). --}}
    @if (auth()->user()->canAny(['administration.gerer', 'taxe.gerer', 'devise.gerer', 'typeclient.gerer', 'motif.gerer',
        'parametre.gerer', 'utilisateur.gerer', 'role.gerer']) || auth()->user()->estGestionnaireAbonnement())
        <li class="nav-item dropdown">
            <a href="#"
                class="nav-link dropdown-toggle {{ request()->routeIs('magasins.*', 'caisses.*', 'moyens-paiement.*', 'unites.*', 'taxes.*', 'type-clients.*', 'motifs-mouvement.*', 'parametres.*', 'utilisateurs.*', 'roles.*') ? 'active' : '' }}"
                data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-gear-wide-connected me-1"></i>Administration
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                @can('administration.gerer')
                    <li>
                        <a href="{{ route('magasins.index') }}"
                            class="dropdown-item {{ request()->routeIs('magasins.*') ? 'active' : '' }}">
                            <i class="bi bi-shop me-2"></i>Magasins
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('caisses.index') }}"
                            class="dropdown-item {{ request()->routeIs('caisses.*') ? 'active' : '' }}">
                            <i class="bi bi-cash-stack me-2"></i>Caisses
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('moyens-paiement.index') }}"
                            class="dropdown-item {{ request()->routeIs('moyens-paiement.*') ? 'active' : '' }}">
                            <i class="bi bi-credit-card me-2"></i>Moyens de paiement
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('unites.index') }}"
                            class="dropdown-item {{ request()->routeIs('unites.*') ? 'active' : '' }}">
                            <i class="bi bi-rulers me-2"></i>Unités
                        </a>
                    </li>
                @endcan

                @can('taxe.gerer')
                    <li>
                        <a href="{{ route('taxes.index') }}"
                            class="dropdown-item {{ request()->routeIs('taxes.*') ? 'active' : '' }}">
                            <i class="bi bi-percent me-2"></i>Taxes
                        </a>
                    </li>
                @endcan

                @can('devise.gerer')
                    <li>
                        <a href="{{ route('devises.index') }}"
                            class="dropdown-item {{ request()->routeIs('devises.*') ? 'active' : '' }}">
                            <i class="bi bi-cash-coin me-2"></i>Devises
                        </a>
                    </li>
                @endcan

                @can('typeclient.gerer')
                    <li>
                        <a href="{{ route('type-clients.index') }}"
                            class="dropdown-item {{ request()->routeIs('type-clients.*') ? 'active' : '' }}">
                            <i class="bi bi-person-badge me-2"></i>Types de client
                        </a>
                    </li>
                @endcan

                @can('motif.gerer')
                    <li>
                        <a href="{{ route('motifs-mouvement.index') }}"
                            class="dropdown-item {{ request()->routeIs('motifs-mouvement.*') ? 'active' : '' }}">
                            <i class="bi bi-tag me-2"></i>Motifs de mouvement
                        </a>
                    </li>
                @endcan

                @can('parametre.gerer')
                    <li>
                        <a href="{{ route('parametres.edit') }}"
                            class="dropdown-item {{ request()->routeIs('parametres.*') ? 'active' : '' }}">
                            <i class="bi bi-gear me-2"></i>Paramètres
                        </a>
                    </li>
                @endcan

                @can('utilisateur.gerer')
                    <li>
                        <a href="{{ route('utilisateurs.index') }}"
                            class="dropdown-item {{ request()->routeIs('utilisateurs.*') ? 'active' : '' }}">
                            <i class="bi bi-people me-2"></i>Utilisateurs
                        </a>
                    </li>
                @endcan

                @can('role.gerer')
                    <li>
                        <a href="{{ route('roles.index') }}"
                            class="dropdown-item {{ request()->routeIs('roles.*') ? 'active' : '' }}">
                            <i class="bi bi-shield-lock me-2"></i>Rôles
                        </a>
                    </li>
                @endcan

                {{-- Jamais un @can : réservé au Superadmin/développeur en dur,
                     voir User::estGestionnaireAbonnement() — ni délégable ni
                     visible pour un Gérant, même habilité par ailleurs sur ce
                     menu Administration. Toujours en dernier. --}}
                @if (auth()->user()->estGestionnaireAbonnement())
                    <li>
                        <a href="{{ route('abonnement.gestion') }}"
                            class="dropdown-item {{ request()->routeIs('abonnement.gestion') ? 'active' : '' }}">
                            <i class="bi bi-calendar-check me-2"></i>Abonnement
                        </a>
                    </li>
                @endif
            </ul>
        </li>
    @endif
</ul>
