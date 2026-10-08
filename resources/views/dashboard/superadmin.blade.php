@extends('layouts.app')

@section('title', 'Tableau de bord')

@section('content')
    @include('dashboard._entete', ['perimetre' => 'Tous les magasins'])

    @include('dashboard._apercu')

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="carte-titre">
                <div>
                    <h3 class="carte-titre__texte">Ventes par magasin</h3>
                    <p class="carte-titre__aide">{{ $periode['libelle'] }}</p>
                </div>
            </div>

            @if ($comparatifMagasins->isEmpty())
                <div class="etat-vide">
                    <i class="bi bi-shop"></i>
                    <p>Aucun magasin pour le moment.</p>
                </div>
            @else
                <x-liste-barres rang :lignes="$comparatifMagasins->map(fn ($magasin) => [
                    'libelle' => $magasin->nom,
                    'valeur' => (int) ($magasin->ventes_sum_total_net ?? 0),
                    'texte' => montant($magasin->ventes_sum_total_net ?? 0),
                ])" />
            @endif
        </div>
    </div>
@endsection
