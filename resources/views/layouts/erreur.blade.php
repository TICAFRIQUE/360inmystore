<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titre', 'Erreur') — {{ $parametre->nom }}</title>
    <link rel="icon" href="{{ $parametre->logoUrl() }}">
    {{-- Police Inter compilée par Vite (voir vite.config.js) : sans cette
         directive, la page retombe sur la police du système. --}}
    @fonts
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
    @include('partials.couleur-primaire')
</head>
<body class="d-flex align-items-center" style="min-height: 100vh; background-color: var(--erp-ink);">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-8 col-md-6 col-lg-5">
                <div class="text-center mb-4">
                    <span class="brand-logo-chip brand-logo-chip--lg">
                        <img src="{{ $parametre->logoUrl() }}" alt="Logo {{ $parametre->nom }}">
                    </span>
                    <h1 class="h4 text-white mt-2 mb-1">{{ $parametre->nom }}</h1>
                    <span class="brand-accent-bar mx-auto"></span>
                </div>

                <div class="card shadow-sm text-center">
                    <div class="card-body p-4">
                        <div class="display-5 fw-bold text-primary mb-2">@yield('code')</div>
                        <h2 class="h5 mb-2">@yield('titre')</h2>
                        <p class="text-secondary mb-4">@yield('message')</p>
                        <a href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="btn btn-primary">
                            @yield('bouton', "Retour à l'accueil")
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
