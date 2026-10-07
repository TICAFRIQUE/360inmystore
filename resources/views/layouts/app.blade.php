<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>window.DEVISE_ABREVIATION = @json(\App\Models\Devise::abreviationActuelle());</script>
    {{-- Sidebar miniature : état mémorisé par poste (localStorage), appliqué
         avant le premier rendu pour ne jamais voir le menu "sauter" de large à
         réduit au chargement. Sans préférence enregistrée, réduite d'office sur
         un écran étroit (< 1200 px) pour laisser la place au contenu. --}}
    <script>
        (function () {
            try {
                var preference = localStorage.getItem('erp.sidebar.mini');
                if (preference === '1' || (preference === null && window.innerWidth < 1200)) {
                    document.documentElement.classList.add('erp-sidebar-mini');
                }
            } catch (e) {}
        })();
    </script>
    <title>@yield('title', 'Tableau de bord') — {{ $parametre->nom }}</title>
    <link rel="icon" href="{{ $parametre->logoUrl() }}">
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
    @include('partials.couleur-primaire')
</head>
<body>
    <div class="erp-shell">
        {{-- Un seul menu, deux présentations : sidebar fixe (réductible en
             miniature) sur desktop (≥ lg), panneau coulissant sur
             mobile/tablette (< lg) — voir .offcanvas-lg de Bootstrap. --}}
        <aside class="erp-sidebar offcanvas-lg offcanvas-start d-print-none" tabindex="-1" id="menuPrincipal" aria-label="Menu">
            <div class="erp-sidebar__panneau">
                <div class="erp-sidebar__entete">
                    <a class="erp-brand" href="{{ route('dashboard') }}" title="{{ $parametre->nom }}">
                        <img class="erp-brand__logo" src="{{ $parametre->logoUrl() }}" alt="Logo {{ $parametre->nom }}">
                        <span class="erp-brand__texte">
                            <span class="erp-brand__nom">{{ $parametre->nom }}</span>
                            @if ($parametre->slogan)
                                <span class="erp-brand__slogan">{{ $parametre->slogan }}</span>
                            @endif
                        </span>
                    </a>
                    <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#menuPrincipal" aria-label="Fermer le menu"></button>
                </div>

                <div class="erp-sidebar__corps">
                    @include('partials.sidebar')
                </div>

                <div class="erp-sidebar__pied">{{ config('app.name') }}</div>
            </div>
        </aside>

        <div class="erp-contenu">
            <header class="erp-topbar d-print-none">
                <button type="button" class="erp-topbar__bascule d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#menuPrincipal"
                        aria-controls="menuPrincipal" aria-label="Ouvrir le menu">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
                <button type="button" class="erp-topbar__bascule d-none d-lg-inline-flex" data-bascule-sidebar
                        aria-controls="menuPrincipal" aria-label="Réduire ou agrandir le menu" title="Réduire / agrandir le menu">
                    <i class="bi bi-layout-sidebar" aria-hidden="true"></i>
                </button>

                <div class="erp-topbar__titre">@yield('title', 'Tableau de bord')</div>

                <div class="ms-auto">
                    @include('partials.topbar')
                </div>
            </header>

            <main class="erp-main">
                {{-- Session ouverte par l'éditeur depuis sa console (voir
                     AccesEspaceController) : toujours rappelé à l'écran. --}}
                @if (session(\App\Http\Controllers\AccesEspaceController::CLE_MAINTENANCE))
                    <div class="alert alert-warning d-print-none">
                        <i class="bi bi-tools me-1"></i>
                        <strong>Accès de maintenance.</strong>
                        Vous êtes dans l'espace de ce client, connecté avec le compte de son propriétaire
                        ({{ session(\App\Http\Controllers\AccesEspaceController::CLE_MAINTENANCE) }}).
                        Déconnectez-vous une fois l'intervention terminée.
                    </div>
                @endif

                @if (session('succes'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        {{ session('succes') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
                    </div>
                @endif

                @if (session('erreur'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        {{ session('erreur') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
                    </div>
                @endif

                @if ($errors->any())
                    {{-- Échec de validation "brut" (règles required/exists/...), distinct
                         des exceptions métier attrapées ci-dessus (session('erreur')) —
                         sans ce bloc, un formulaire refusé se réaffichait sans aucune
                         explication visible. --}}
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <div class="fw-medium mb-1">Le formulaire contient des erreurs :</div>
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $erreurValidation)
                                <li>{{ $erreurValidation }}</li>
                            @endforeach
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>

    @include('partials.confirm-delete-modal')
    @include('partials.confirm-action-modal')

    @stack('scripts')
</body>
</html>
