@extends('layouts.app')

@section('title', 'Caisses')

@section('content')
    <h2 class="h4 mb-3">Caisses</h2>

    <x-alerte-sessions-anciennes :sessions="$sessionsAnciennes" />

    {{-- Aucune caisse : dire ce qui manque et y conduire, plutôt qu'une page
         vide. Une caisse est toujours rattachée à un magasin, donc le magasin
         d'abord s'il n'y en a aucun. --}}
    @if ($caissesOccupees->isEmpty() && $caissesLibres->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="etat-vide etat-vide--grand">
                    @if ($aucunMagasin)
                        <i class="bi bi-shop"></i>
                        <h3 class="h5">Aucun magasin pour l'instant</h3>
                        <p>Une caisse est toujours rattachée à un magasin : créez d'abord le magasin, puis ajoutez-y une caisse.</p>
                        @can('administration.gerer')
                            <a href="{{ route('magasins.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Créer un magasin</a>
                        @else
                            <p class="fw-medium">Demandez à votre responsable de créer un magasin et une caisse.</p>
                        @endcan
                    @else
                        <i class="bi bi-cash-coin"></i>
                        <h3 class="h5">{{ auth()->user()->magasin_id ? 'Aucune caisse dans votre magasin' : 'Aucune caisse pour le moment' }}</h3>
                        <p>Il faut une caisse pour ouvrir une session et commencer à vendre.</p>
                        @can('administration.gerer')
                            <a href="{{ route('caisses.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Créer une caisse</a>
                        @else
                            <p class="fw-medium">Demandez à votre responsable d'en créer une.</p>
                        @endcan
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if ($caissesOccupees->isNotEmpty())
        <h3 class="h6 text-secondary text-uppercase small mb-2">
            <i class="bi bi-lock-fill me-1"></i>Caisses occupées
        </h3>
        <div class="row g-3 mb-4">
            @foreach ($caissesOccupees as $caisse)
                @include('sessions._carte-caisse', ['caisse' => $caisse])
            @endforeach
        </div>
    @endif

    @if ($caissesLibres->isNotEmpty())
        <h3 class="h6 text-secondary text-uppercase small mb-2">
            <i class="bi bi-unlock-fill me-1"></i>Caisses libres
        </h3>
        <div class="row g-3">
            @foreach ($caissesLibres as $caisse)
                @include('sessions._carte-caisse', ['caisse' => $caisse])
            @endforeach
        </div>
    @endif
@endsection
