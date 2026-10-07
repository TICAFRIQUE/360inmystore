{{-- Pages publiques de la plateforme : site vitrine,
     inscription, espace introuvable ou suspendu. Volontairement sans aucune
     lecture de base ni de session — la page « espace indisponible » est
     rendue avant le démarrage des sessions, sans base de données utilisable
     (voir BloqueEspaceIndisponible). La marque affichée est celle du produit,
     pas celle d'un commerce. --}}
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('images/logo-defaut.svg') }}">
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
</head>
<body class="plateforme-public">
    <header class="plateforme-public__entete">
        <a href="{{ plateforme()->urlCentrale() }}" class="plateforme-public__marque">
            <img src="{{ asset('images/logo-defaut.svg') }}" alt="">
            <span>{{ config('app.name') }}</span>
        </a>
        <div class="d-flex align-items-center gap-2">
            @yield('entete')
        </div>
    </header>

    <main class="plateforme-public__contenu">
        @yield('content')
    </main>

    <footer class="plateforme-public__pied">{{ config('app.name') }} — caisse, stock et crédit client pour tous les commerces.</footer>
</body>
</html>
