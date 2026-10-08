{{-- Connexion : à gauche (écran large) une présentation de l'application par
     ses écrans (voir views/apercus), à droite le formulaire. Sur téléphone
     et tablette, seul le formulaire s'affiche — on vient ici pour se
     connecter. --}}
@php
    // id : aperçu affiché (apercus/{id}).
    $ecrans = [
        ['id' => 'vente', 'icone' => 'bi-cart3', 'nom' => 'Caisse', 'texte' => 'Encaissez au comptant ou à crédit, en un ou plusieurs moyens de paiement.'],
        ['id' => 'stock', 'icone' => 'bi-boxes', 'nom' => 'Stock', 'texte' => 'Chaque mouvement est tracé, par magasin et par dépôt, avec alerte sous le seuil.'],
        ['id' => 'achats', 'icone' => 'bi-truck', 'nom' => 'Achats', 'texte' => 'Bons de commande, réceptions en plusieurs fois et dettes fournisseurs.'],
        ['id' => 'tresorerie', 'icone' => 'bi-bank', 'nom' => 'Trésorerie', 'texte' => 'Caisse générale, comptes et virements : vous savez où est votre argent.'],
        ['id' => 'rapports', 'icone' => 'bi-bar-chart-line', 'nom' => 'Rapports', 'texte' => 'Ventes, marges et écarts de caisse, exportables en PDF, Excel et CSV.'],
    ];
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion — {{ $parametre->nom }}</title>
    <link rel="icon" href="{{ $parametre->logoUrl() }}">
    {{-- Police Inter compilée par Vite (voir vite.config.js). --}}
    @fonts
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
    @include('partials.couleur-primaire')
</head>
<body>
    <div class="login-layout">
        <div class="login-layout__vitrine">
            <div class="login-layout__marque">
                <span class="brand-logo-chip">
                    <img src="{{ $parametre->logoUrl() }}" alt="Logo {{ $parametre->nom }}">
                </span>
                <div>
                    <strong>{{ $parametre->nom }}</strong>
                    @if ($parametre->slogan)
                        <span>{{ $parametre->slogan }}</span>
                    @endif
                </div>
            </div>

            {{-- Les écrans défilent seuls toutes les 6 secondes, sauf si le
                 visiteur a demandé à réduire les animations ; survoler ou
                 choisir un onglet arrête le défilement. --}}
            <div class="login-layout__presentation"
                 x-data="{
                     actif: 0,
                     arret: false,
                     init() {
                         if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                         setInterval(() => { if (! this.arret) this.actif = (this.actif + 1) % {{ count($ecrans) }}; }, 6000);
                     },
                 }"
                 @mouseenter="arret = true" @mouseleave="arret = false" @focusin="arret = true">
                <h1 class="login-layout__titre">Toute votre activité, au même endroit.</h1>
                <p class="login-layout__accroche">
                    Suivez vos ventes, votre stock, vos achats et votre trésorerie — pour un magasin ou plusieurs,
                    en toute simplicité.
                </p>

                <div class="login-layout__onglets" role="tablist" aria-label="Aperçu des écrans">
                    @foreach ($ecrans as $i => $ecran)
                        <button type="button" role="tab" id="onglet-{{ $ecran['id'] }}" aria-controls="ecran-{{ $ecran['id'] }}"
                                :aria-selected="actif === {{ $i }} ? 'true' : 'false'" :class="{ 'est-actif': actif === {{ $i }} }"
                                @class(['est-actif' => $loop->first]) @click="actif = {{ $i }}">
                            <i class="bi {{ $ecran['icone'] }}"></i>{{ $ecran['nom'] }}
                        </button>
                    @endforeach
                </div>

                <div class="login-layout__ecrans">
                    @foreach ($ecrans as $i => $ecran)
                        <div class="login-layout__ecran" role="tabpanel" id="ecran-{{ $ecran['id'] }}" aria-labelledby="onglet-{{ $ecran['id'] }}"
                             x-show="actif === {{ $i }}" x-transition.opacity.duration.500ms @if (! $loop->first) x-cloak @endif>
                            @include('apercus.'.$ecran['id'])
                            <p class="login-layout__legende"><strong>{{ $ecran['nom'] }}</strong>{{ $ecran['texte'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="login-layout__formulaire">
            <div class="login-layout__carte">
                {{-- Rappel de la marque quand la présentation est masquée. --}}
                <div class="login-layout__marque-mobile">
                    <img src="{{ $parametre->logoUrl() }}" alt="Logo {{ $parametre->nom }}">
                    <strong>{{ $parametre->nom }}</strong>
                </div>

                <h2 class="login-layout__carte-titre">Connexion</h2>
                <p class="text-secondary mb-4">Accédez à votre espace de gestion.</p>

                @if ($errors->any())
                    <div class="alert alert-danger d-flex gap-2" role="alert">
                        <i class="bi bi-exclamation-circle"></i>
                        <div>
                            @foreach ($errors->all() as $erreur)
                                <div>{{ $erreur }}</div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="username" class="form-label">Nom d'utilisateur</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" name="username" id="username" class="form-control" value="{{ old('username') }}"
                                   autocomplete="username" autocapitalize="none" required autofocus>
                        </div>
                    </div>

                    <div class="mb-4" x-data="{ visible: false }">
                        <label for="code" class="form-label">Mot de passe</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            {{-- En ligne, un compte peut avoir un vrai mot de passe
                                 (le propriétaire en choisit un à l'inscription) : aucune
                                 contrainte de forme ni de longueur à la saisie,
                                 contrairement au code à 4 chiffres de la version
                                 installée. --}}
                            <input :type="visible ? 'text' : 'password'" name="code" id="code" class="form-control"
                                   autocomplete="current-password" required>
                            <button type="button" class="btn btn-outline-secondary" @click="visible = !visible"
                                    :title="visible ? 'Masquer' : 'Afficher'" :aria-label="visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'">
                                <i class="bi" :class="visible ? 'bi-eye-slash' : 'bi-eye'"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        Se connecter<i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </form>

                {{-- Accès perdu : un employé s'adresse à son responsable (qui
                     réinitialise son code depuis Administration → Utilisateurs) ; le
                     responsable lui-même s'adresse au support, dont les coordonnées
                     sont saisies par l'éditeur. Aucune réinitialisation en libre-service :
                     elle demanderait un code envoyé par SMS, qui n'est pas branché. --}}
                <div class="login-layout__aide" x-data="{ ouvert: false }">
                    <button type="button" class="btn btn-link btn-sm p-0" @click="ouvert = ! ouvert" :aria-expanded="ouvert ? 'true' : 'false'" aria-controls="acces-perdu">
                        Mot de passe oublié ?
                    </button>

                    <div id="acces-perdu" class="login-layout__perdu" x-show="ouvert" x-cloak x-transition.opacity>
                        <p><strong>Vous êtes employé ?</strong> Votre responsable peut réinitialiser votre accès depuis son espace.</p>
                        <p class="mb-0"><strong>Vous êtes le responsable ?</strong>
                            @if ($support->telephone || $support->whatsapp || $support->email)
                                Contactez le support, qui vous redonnera l'accès :
                            @else
                                Contactez le support de {{ config('app.name') }}, qui vous redonnera l'accès.
                            @endif
                        </p>
                        @if ($support->telephone || $support->whatsapp || $support->email)
                            <ul class="login-layout__contacts">
                                @if ($support->telephone)
                                    <li><a href="tel:{{ $support->telephone }}"><i class="bi bi-telephone"></i>{{ telephone($support->telephone) }}</a></li>
                                @endif
                                @if ($support->whatsapp)
                                    <li><a href="https://wa.me/{{ ltrim($support->whatsapp, '+') }}" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i>WhatsApp {{ telephone($support->whatsapp) }}</a></li>
                                @endif
                                @if ($support->email)
                                    <li><a href="mailto:{{ $support->email }}"><i class="bi bi-envelope"></i>{{ $support->email }}</a></li>
                                @endif
                            </ul>
                        @endif
                    </div>
                </div>
            </div>

            <p class="login-layout__pied">{{ config('app.name') }}</p>
        </div>
    </div>
</body>
</html>
