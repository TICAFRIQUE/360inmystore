@extends('layouts.app')

@section('title', 'Unités')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Unités</h2>
        <a href="{{ route('unites.create') }}" class="btn btn-primary">Nouvelle unité</a>
    </div>

    <p class="text-secondary small">
        Réutilisées comme unité de base d'un produit (Litre, Mètre, Kg, Pièce…) et comme
        nom des unités de vente (Carton, Bidon, Sac, Rouleau…).
    </p>

    <div class="d-flex justify-content-end mb-3">
        <x-export-buttons :pdf-route="route('unites.pdf', request()->query())" :excel-route="route('unites.excel', request()->query())" :tout="true" />
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Abréviation</th>
                        <th>Utilisation</th>
                        <th>Statut</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($unites as $unite)
                        <tr>
                            <td>{{ $unite->nom }}</td>
                            <td class="text-secondary">{{ $unite->abbreviation ?? '—' }}</td>
                            <td class="text-secondary small">
                                {{ $unite->produits_count }} produit(s), {{ $unite->unite_ventes_count }} unité(s) de vente
                            </td>
                            <td>
                                @if ($unite->actif)
                                    <span class="badge text-bg-success">Actif</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <x-edit-button :href="route('unites.edit', $unite)" />
                                <x-delete-button :action="route('unites.destroy', $unite)" :label="'l\'unité « '.$unite->nom.' »'" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">Aucune unité pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
