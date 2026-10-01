{{-- Menu principal (sidebar, voir CLAUDE.md — Interface). Décrit comme une
     liste de sections → entrées → sous-entrées : un groupe n'apparaît que si
     au moins une de ses sous-entrées est visible pour l'utilisateur connecté,
     et son état "actif" se déduit de celles-ci — jamais une seconde liste de
     permissions/routes à maintenir à la main sur le parent. --}}
@php
    $utilisateurMenu = auth()->user();
    $peut = fn (string $permission) => $utilisateurMenu->can($permission);

    $sectionsMenu = [
        [
            'titre' => null,
            'entrees' => [
                ['libelle' => 'Tableau de bord', 'icone' => 'bi-speedometer2', 'url' => route('dashboard'),
                    'actif' => ['dashboard'], 'visible' => true],
            ],
        ],
        [
            'titre' => 'Opérations',
            'entrees' => [
                ['id' => 'vente', 'libelle' => 'Vente', 'icone' => 'bi-cart3', 'enfants' => [
                    // Session déjà ouverte par ce caissier : y aller directement
                    // plutôt que de repasser par la sélection de caisse (règle 7,
                    // voir AppServiceProvider — sessionCaissierOuverteMenu).
                    ['libelle' => 'Facture/BL', 'icone' => 'bi-cash-stack',
                        'url' => $sessionCaissierOuverteMenu ? route('sessions.show', $sessionCaissierOuverteMenu) : route('sessions.index'),
                        'actif' => ['sessions.*', 'ventes.*', 'ventes-en-attente.*', 'reglements.*'], 'visible' => $peut('caisse.ouvrir')],
                    ['libelle' => 'Devis', 'icone' => 'bi-file-earmark-text', 'url' => route('devis.index'),
                        'actif' => ['devis.*'], 'visible' => $peut('devis.voir')],
                    ['libelle' => 'Clients', 'icone' => 'bi-people', 'url' => route('clients.index'),
                        'actif' => ['clients.*'], 'visible' => $peut('client.voir')],
                    // Onglet "Bon de livraison" retiré du menu pour le moment (demande
                    // utilisateur) — route/contrôleur/permission vente.livrer inchangés,
                    // seule l'entrée de menu est masquée.
                    ['libelle' => 'Avoirs clients', 'icone' => 'bi-piggy-bank', 'url' => route('remboursements-avoir-client.index'),
                        'actif' => ['remboursements-avoir-client.*'], 'visible' => $peut('client.reglement')],
                ]],
                ['id' => 'achats', 'libelle' => 'Achats', 'icone' => 'bi-truck', 'enfants' => [
                    ['libelle' => 'Fournisseurs', 'icone' => 'bi-building', 'url' => route('fournisseurs.index'),
                        'actif' => ['fournisseurs.*', 'reglements-fournisseur.*'], 'visible' => $peut('fournisseur.voir')],
                    ['libelle' => 'Bons de commande', 'icone' => 'bi-receipt', 'url' => route('commande-achats.index'),
                        'actif' => ['commande-achats.*'], 'visible' => $peut('achat.voir')],
                ]],
                ['id' => 'stock', 'libelle' => 'Stock', 'icone' => 'bi-boxes', 'enfants' => [
                    ['libelle' => 'État de stock', 'icone' => 'bi-clipboard-data', 'url' => route('stock.index'),
                        'actif' => ['stock.index'], 'visible' => $peut('stock.voir')],
                    ['libelle' => 'Transfert', 'icone' => 'bi-arrow-left-right', 'url' => route('transferts.create'),
                        'actif' => ['transferts.*'], 'visible' => $peut('stock.transferer')],
                    ['libelle' => 'Casse / ajustement', 'icone' => 'bi-sliders', 'url' => route('stock.mouvements.create'),
                        'actif' => ['stock.mouvements.create', 'stock.mouvements.store'], 'visible' => $peut('stock.ajuster')],
                    ['libelle' => 'Inventaire', 'icone' => 'bi-clipboard-check', 'url' => route('inventaires.index'),
                        'actif' => ['inventaires.*'], 'visible' => $peut('inventaire.voir')],
                    ['libelle' => 'Historique des mouvements', 'icone' => 'bi-clock-history', 'url' => route('stock.mouvements.index'),
                        'actif' => ['stock.mouvements.index'], 'visible' => $peut('stock.voir')],
                ]],
                ['id' => 'catalogue', 'libelle' => 'Catalogue', 'icone' => 'bi-tags', 'enfants' => [
                    ['libelle' => 'Produits', 'icone' => 'bi-box-seam', 'url' => route('produits.index'),
                        'actif' => ['produits.*'], 'visible' => $peut('produit.voir')],
                    ['libelle' => 'Catégories', 'icone' => 'bi-diagram-3', 'url' => route('categories.index'),
                        'actif' => ['categories.*'], 'visible' => $peut('categorie.gerer')],
                ]],
            ],
        ],
        [
            'titre' => 'Pilotage',
            'entrees' => [
                ['libelle' => 'Trésorerie', 'icone' => 'bi-bank', 'url' => route('comptabilite.caisses.index'),
                    'actif' => ['comptabilite.*'], 'visible' => $peut('tresorerie.voir')],
                ['libelle' => 'Rapports', 'icone' => 'bi-bar-chart-line', 'url' => route('rapports.index'),
                    'actif' => ['rapports.*', 'journal.*'], 'visible' => $peut('rapport.voir')],
            ],
        ],
        [
            'titre' => 'Configuration',
            'entrees' => [
                ['id' => 'administration', 'libelle' => 'Administration', 'icone' => 'bi-gear', 'enfants' => [
                    ['libelle' => 'Magasins', 'icone' => 'bi-shop', 'url' => route('magasins.index'),
                        'actif' => ['magasins.*'], 'visible' => $peut('administration.gerer')],
                    ['libelle' => 'Caisses', 'icone' => 'bi-cash-stack', 'url' => route('caisses.index'),
                        'actif' => ['caisses.*'], 'visible' => $peut('administration.gerer')],
                    ['libelle' => 'Moyens de paiement', 'icone' => 'bi-credit-card', 'url' => route('moyens-paiement.index'),
                        'actif' => ['moyens-paiement.*'], 'visible' => $peut('administration.gerer')],
                    ['libelle' => 'Unités', 'icone' => 'bi-rulers', 'url' => route('unites.index'),
                        'actif' => ['unites.*'], 'visible' => $peut('administration.gerer')],
                    ['libelle' => 'Taxes', 'icone' => 'bi-percent', 'url' => route('taxes.index'),
                        'actif' => ['taxes.*'], 'visible' => $peut('taxe.gerer')],
                    ['libelle' => 'Devises', 'icone' => 'bi-cash-coin', 'url' => route('devises.index'),
                        'actif' => ['devises.*'], 'visible' => $peut('devise.gerer')],
                    ['libelle' => 'Types de client', 'icone' => 'bi-person-badge', 'url' => route('type-clients.index'),
                        'actif' => ['type-clients.*'], 'visible' => $peut('typeclient.gerer')],
                    ['libelle' => 'Motifs de mouvement', 'icone' => 'bi-tag', 'url' => route('motifs-mouvement.index'),
                        'actif' => ['motifs-mouvement.*'], 'visible' => $peut('motif.gerer')],
                    ['libelle' => 'Paramètres', 'icone' => 'bi-sliders2', 'url' => route('parametres.edit'),
                        'actif' => ['parametres.*'], 'visible' => $peut('parametre.gerer')],
                    ['libelle' => 'Utilisateurs', 'icone' => 'bi-person-gear', 'url' => route('utilisateurs.index'),
                        'actif' => ['utilisateurs.*'], 'visible' => $peut('utilisateur.gerer')],
                    ['libelle' => 'Rôles', 'icone' => 'bi-shield-lock', 'url' => route('roles.index'),
                        'actif' => ['roles.*'], 'visible' => $peut('role.gerer')],
                    // Jamais une permission Spatie : réservé au Superadmin/développeur
                    // en dur, voir User::estGestionnaireAbonnement() — ni délégable ni
                    // visible pour un Gérant, même habilité par ailleurs sur ce menu
                    // Administration (qui s'affiche donc aussi pour un compte
                    // développeur sans aucune autre permission). Toujours en dernier.
                    ['libelle' => 'Abonnement', 'icone' => 'bi-calendar-check', 'url' => route('abonnement.gestion'),
                        'actif' => ['abonnement.gestion'], 'visible' => $utilisateurMenu->estGestionnaireAbonnement()],
                ]],
            ],
        ],
    ];

    $estActif = fn (array $entree) => request()->routeIs(...$entree['actif']);
@endphp

<nav aria-label="Menu principal">
    <ul class="erp-menu">
        @foreach ($sectionsMenu as $section)
            @php
                $entreesVisibles = collect($section['entrees'])
                    ->map(function (array $entree) {
                        if (isset($entree['enfants'])) {
                            $entree['enfants'] = array_values(array_filter($entree['enfants'], fn (array $enfant) => $enfant['visible']));
                            $entree['visible'] = $entree['enfants'] !== [];
                        }

                        return $entree;
                    })
                    ->filter(fn (array $entree) => $entree['visible']);
            @endphp

            @continue($entreesVisibles->isEmpty())

            @if ($section['titre'])
                <li class="erp-menu__section" aria-hidden="true">{{ $section['titre'] }}</li>
            @endif

            @foreach ($entreesVisibles as $entree)
                @if (isset($entree['enfants']))
                    @php
                        $groupeActif = collect($entree['enfants'])->contains(fn (array $enfant) => $estActif($enfant));
                    @endphp
                    <li class="erp-menu__item erp-menu__groupe {{ $groupeActif ? 'is-open a-un-actif' : '' }}" data-menu-groupe>
                        <button type="button" class="erp-menu__link" aria-expanded="{{ $groupeActif ? 'true' : 'false' }}"
                                aria-controls="menu-{{ $entree['id'] }}">
                            <i class="bi {{ $entree['icone'] }}" aria-hidden="true"></i>
                            <span class="erp-menu__label">{{ $entree['libelle'] }}</span>
                            <i class="bi bi-chevron-down erp-menu__chevron" aria-hidden="true"></i>
                        </button>
                        <div class="erp-menu__sub" id="menu-{{ $entree['id'] }}">
                            <div class="erp-menu__sub-contenu">
                                <div class="erp-menu__sub-titre">{{ $entree['libelle'] }}</div>
                                <ul>
                                    @foreach ($entree['enfants'] as $enfant)
                                        <li>
                                            <a href="{{ $enfant['url'] }}"
                                               class="erp-menu__sublink {{ $estActif($enfant) ? 'is-active' : '' }}"
                                               @if ($estActif($enfant)) aria-current="page" @endif>
                                                <i class="bi {{ $enfant['icone'] }}" aria-hidden="true"></i>
                                                <span>{{ $enfant['libelle'] }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </li>
                @else
                    <li class="erp-menu__item">
                        <a href="{{ $entree['url'] }}" class="erp-menu__link {{ $estActif($entree) ? 'is-active' : '' }}"
                           @if ($estActif($entree)) aria-current="page" @endif>
                            <i class="bi {{ $entree['icone'] }}" aria-hidden="true"></i>
                            <span class="erp-menu__label">{{ $entree['libelle'] }}</span>
                        </a>
                    </li>
                @endif
            @endforeach
        @endforeach
    </ul>
</nav>
