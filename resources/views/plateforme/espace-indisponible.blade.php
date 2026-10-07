@extends('layouts.plateforme')

@section('title', $introuvable ? 'Espace introuvable' : 'Espace indisponible')

@section('content')
    <div class="plateforme-public__carte text-center">
        <div class="display-6 text-secondary mb-3"><i class="bi {{ $introuvable ? 'bi-signpost-split' : 'bi-lock' }}"></i></div>

        @if ($introuvable)
            <h1 class="h4">Cet espace n'existe pas</h1>
            <p class="text-secondary">
                Aucun commerce n'utilise l'adresse <strong>{{ $sousDomaine }}</strong>.
                Vérifiez l'orthographe de l'adresse de votre espace.
            </p>
        @else
            <h1 class="h4">Cet espace est momentanément indisponible</h1>
            <p class="text-secondary">
                L'accès à l'espace <strong>{{ $sousDomaine }}</strong> est fermé pour le moment.
                Vos données sont conservées — contactez {{ config('app.name') }} pour le rouvrir.
            </p>
        @endif

        <a href="{{ plateforme()->urlCentrale() }}" class="btn btn-primary">Aller à l'accueil</a>
    </div>
@endsection
