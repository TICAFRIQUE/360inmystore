<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion — {{ $parametre->nom }}</title>
    <link rel="icon" href="{{ $parametre->logoUrl() }}">
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
    @include('partials.couleur-primaire')
</head>
<body>
    <div class="login-layout">
        <div class="login-layout__vitrine">
            <div class="login-layout__vitrine-contenu">
                <span class="brand-logo-chip brand-logo-chip--lg">
                    <img src="{{ $parametre->logoUrl() }}" alt="Logo {{ $parametre->nom }}">
                </span>
                <h1 class="h2 text-white mt-3 mb-2">{{ $parametre->nom }}</h1>
                @if ($parametre->slogan)
                    <p class="text-white-50 mb-2">{{ $parametre->slogan }}</p>
                @endif
                <span class="brand-accent-bar mb-3"></span>
                <p class="lead text-white-50">
                    Bienvenue dans votre application de gestion de caisse et de stock —
                    suivez vos ventes, vos magasins et vos équipes en toute simplicité,
                    et en toute sécurité.
                </p>

                <ul class="login-layout__atouts list-unstyled mt-4">
                    <li>
                        <i class="bi bi-boxes"></i>
                        <div>
                            <strong>Stock toujours à jour</strong>
                            <div class="text-white-50 small">Chaque mouvement est tracé, rien n'est jamais écrasé.</div>
                        </div>
                    </li>
                    <li>
                        <i class="bi bi-cash-coin"></i>
                        <div>
                            <strong>Caisse et ventes en temps réel</strong>
                            <div class="text-white-50 small">Une session par caisse, un ticket par vente.</div>
                        </div>
                    </li>
                    <li>
                        <i class="bi bi-shop"></i>
                        <div>
                            <strong>Plusieurs magasins, une seule application</strong>
                            <div class="text-white-50 small">Consolidez tous vos points de vente au même endroit.</div>
                        </div>
                    </li>
                </ul>
            </div>

            <div class="login-layout__illustration">
                @include('auth.partials.illustration-pos')
            </div>
        </div>

        <div class="login-layout__formulaire">
            <div class="login-layout__carte">
                <h2 class="h4 mb-1">Connexion</h2>
                <p class="text-secondary small mb-4">Accédez à votre espace de gestion.</p>

                @if ($errors->any())
                    <div class="alert alert-danger">
                        @foreach ($errors->all() as $erreur)
                            <div>{{ $erreur }}</div>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="username" class="form-label">Nom d'utilisateur</label>
                        <input type="text" name="username" id="username" class="form-control" value="{{ old('username') }}" required autofocus>
                    </div>

                    <div class="mb-4" x-data="{ visible: false }">
                        <label for="code" class="form-label">Mot de passe</label>
                        <div class="input-group">
                            {{-- En ligne, un compte peut avoir un vrai mot de passe
                                 (le propriétaire en choisit un à l'inscription) : aucune
                                 contrainte de forme ni de longueur à la saisie,
                                 contrairement au code à 4 chiffres de la version
                                 installée. --}}
                            <input :type="visible ? 'text' : 'password'" name="code" id="code" class="form-control"
                                   autocomplete="current-password" required>
                            <button type="button" class="btn btn-outline-secondary" @click="visible = !visible"
                                    :title="visible ? 'Masquer le code' : 'Afficher le code'">
                                <i class="bi" :class="visible ? 'bi-eye-slash' : 'bi-eye'"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Se connecter</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
