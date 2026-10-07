{{-- Site vitrine de la plateforme (domaine principal). Les tarifs sont les
     formules actives du catalogue géré par l'éditeur — rien n'est écrit en
     dur ici. --}}
@extends('layouts.plateforme')

@section('title', 'Caisse, stock et crédit client pour votre commerce')

@section('entete')
    <a href="#fonctionnalites" class="btn btn-sm btn-link text-secondary d-none d-md-inline-block">Fonctionnalités</a>
    <a href="#tarifs" class="btn btn-sm btn-link text-secondary d-none d-md-inline-block">Tarifs</a>
    <a href="#acces" class="btn btn-sm btn-outline-secondary">Accéder à mon espace</a>
    @if ($inscriptionOuverte)
        <a href="{{ route('plateforme.inscription') }}" class="btn btn-sm btn-primary">Créer un compte</a>
    @endif
@endsection

@section('content')
    <div class="vitrine">
        <section class="vitrine__hero">
            <h1 class="vitrine__titre">Vos ventes, votre stock et votre caisse, dans un seul outil.</h1>
            <p class="vitrine__accroche">
                {{ config('app.name') }} fait tourner la caisse, le stock, le crédit client et la trésorerie de votre
                commerce — un magasin ou plusieurs, quel que soit votre secteur. Sans installation : tout se passe
                dans votre navigateur, sur ordinateur, tablette ou téléphone.
            </p>
            @if ($inscriptionOuverte)
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <a href="{{ route('plateforme.inscription') }}" class="btn btn-primary btn-lg">
                        Créer un compte{{ $essai ? ' — essai gratuit de '.$essai->jours.' jours' : '' }}
                    </a>
                    <a href="#tarifs" class="btn btn-outline-secondary btn-lg">Voir les tarifs</a>
                </div>
            @endif
        </section>

        <section class="vitrine__section" id="fonctionnalites">
            <h2 class="vitrine__sous-titre">Tout ce qu'il faut pour gérer un commerce</h2>
            <div class="row g-3">
                @foreach ([
                    ['bi-cash-coin', 'Caisse et ventes', 'Vente comptant ou à crédit, remises, tickets et factures, plusieurs moyens de paiement.'],
                    ['bi-boxes', 'Stock toujours à jour', 'Chaque entrée et chaque sortie est tracée, par magasin et par dépôt, avec alertes de seuil.'],
                    ['bi-people', 'Crédit client', 'Comptes clients, limites de crédit, règlements, avoirs et retours.'],
                    ['bi-truck', 'Achats et fournisseurs', 'Bons de commande, réceptions échelonnées, dettes fournisseurs.'],
                    ['bi-bank', 'Trésorerie', 'Clôtures de caisse, caisse générale, comptes bancaires et virements.'],
                    ['bi-bar-chart-line', 'Rapports', 'Ventes, marges, écarts de caisse, inventaires — exportables en PDF, Excel et CSV.'],
                ] as [$icone, $titre, $texte])
                    <div class="col-sm-6 col-lg-4">
                        <div class="vitrine__atout">
                            <i class="bi {{ $icone }}"></i>
                            <h3 class="h6 mb-1">{{ $titre }}</h3>
                            <p class="text-secondary small mb-0">{{ $texte }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="vitrine__section">
            <h2 class="vitrine__sous-titre">Prêt en trois étapes</h2>
            <div class="row g-3">
                @foreach ([
                    ['Créez votre compte', 'Le nom de votre commerce, votre téléphone, un mot de passe : votre espace est prêt en une minute.'],
                    ['Choisissez votre formule', 'Vous arrivez directement dans votre espace pour choisir la formule qui vous convient.'],
                    ['Vendez', 'Ajoutez vos produits, ouvrez votre caisse, invitez vos employés.'],
                ] as $numero => [$titre, $texte])
                    <div class="col-md-4">
                        <div class="vitrine__etape">
                            <span class="vitrine__numero">{{ $numero + 1 }}</span>
                            <h3 class="h6 mb-1">{{ $titre }}</h3>
                            <p class="text-secondary small mb-0">{{ $texte }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="vitrine__section" id="tarifs">
            <h2 class="vitrine__sous-titre">Tarifs</h2>
            @if ($formules->isEmpty())
                <p class="text-center text-secondary">Les tarifs seront bientôt disponibles.</p>
            @else
                <x-tarifs :formules="$formules" action="plateforme._action-tarif" />
            @endif
        </section>

        <section class="vitrine__section" id="acces">
            <div class="plateforme-public__carte mx-auto">
                <h2 class="h5 mb-1">Accéder à mon espace</h2>
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
                    <button type="submit" class="btn btn-outline-primary w-100">Continuer</button>
                </form>

                <p class="text-center small mb-0 mt-3">
                    <a href="{{ route('login') }}" class="text-secondary">Connexion de l'éditeur</a>
                </p>
            </div>
        </section>
    </div>
@endsection
