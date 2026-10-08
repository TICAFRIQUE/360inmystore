{{-- Pages publiques de la plateforme : site vitrine,
     inscription, espace introuvable ou suspendu. Volontairement sans aucune
     lecture de base ni de session — la page « espace indisponible » est
     rendue avant le démarrage des sessions, sans base de données utilisable
     (voir BloqueEspaceIndisponible). La marque affichée est celle du produit,
     pas celle d'un commerce.

     Sections facultatives : « navigation » (liens du menu, écran large) et
     « navigation-mobile » (les mêmes, dans le panneau coulissant), « entete »
     (boutons à droite), « pied » (pied de page complet à la place du pied
     minimal), « contenu-classe » (variante de la zone de contenu). --}}
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ config('app.name') }}</title>
    <meta name="description" content="@yield('description', config('app.name').' — caisse, stock et crédit client pour tous les commerces.')">
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/logo/favicon-app/favicon.svg') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/logo/favicon-app/favicon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/logo/favicon-app/apple-touch-icon.png') }}">
    {{-- Police Inter compilée par Vite (voir vite.config.js) : sans cette
         directive, la page retombe sur la police du système. --}}
    @fonts
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
</head>
<body class="plateforme-public">
    <header class="plateforme-public__entete">
        <div class="plateforme-public__barre">
            <a href="{{ plateforme()->urlCentrale() }}" class="plateforme-public__marque">
                <img src="{{ asset('images/logo/couleur/360inmystore-horizontal-couleur.svg') }}" alt="{{ config('app.name') }}" width="523" height="124">
            </a>

            @hasSection('navigation')
                <nav class="plateforme-public__nav" aria-label="Navigation principale">
                    @yield('navigation')
                </nav>
            @endif

            <div class="plateforme-public__actions">
                @yield('entete')

                @hasSection('navigation-mobile')
                    <button type="button" class="btn btn-outline-secondary plateforme-public__burger" data-bs-toggle="offcanvas" data-bs-target="#menuVitrine"
                            aria-controls="menuVitrine" aria-label="Ouvrir le menu">
                        <i class="bi bi-list"></i>
                    </button>
                @endif
            </div>
        </div>
    </header>

    @hasSection('navigation-mobile')
        <div class="offcanvas offcanvas-end plateforme-public__panneau" tabindex="-1" id="menuVitrine" aria-labelledby="menuVitrineTitre">
            <div class="offcanvas-header">
                <span class="offcanvas-title h6 mb-0" id="menuVitrineTitre">Menu</span>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fermer le menu"></button>
            </div>
            <div class="offcanvas-body">
                @yield('navigation-mobile')
            </div>
        </div>
    @endif

    <main class="plateforme-public__contenu @yield('contenu-classe')">
        @yield('content')
    </main>

    @hasSection('pied')
        @yield('pied')
    @else
        <footer class="plateforme-public__pied">{{ config('app.name') }} — caisse, stock et crédit client pour tous les commerces.</footer>
    @endif
</body>
</html>
