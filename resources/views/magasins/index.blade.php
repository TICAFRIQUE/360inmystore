@extends('layouts.app')

@section('title', 'Magasins')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Magasins</h2>
        <a href="{{ route('magasins.create') }}" class="btn btn-primary">Nouveau magasin</a>
    </div>

    <x-recherche-form :action="route('magasins.index')" placeholder="Rechercher un magasin…">
        <x-slot:export>
            <x-export-buttons :pdf-route="route('magasins.pdf', request()->query())" :excel-route="route('magasins.excel', request()->query())" :tout="true" />
        </x-slot:export>
    </x-recherche-form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <x-th-tri champ="nom" label="Nom" />
                        <th>Type</th>
                        <th>Adresse</th>
                        <th>Téléphone</th>
                        <x-th-tri champ="actif" label="Statut" />
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($magasins as $magasin)
                        <tr>
                            <td>{{ $magasin->nom }}</td>
                            <td>
                                @if ($magasin->estDepot())
                                    <span class="badge text-bg-info">Dépôt</span>
                                @else
                                    <span class="badge text-bg-primary">Magasin</span>
                                @endif
                            </td>
                            <td>{{ $magasin->adresse ?? '—' }}</td>
                            <td>{{ $magasin->telephone ?? '—' }}</td>
                            <td>
                                @if ($magasin->actif)
                                    <span class="badge text-bg-success">Actif</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <x-edit-button :href="route('magasins.edit', $magasin)" />
                                <x-delete-button :action="route('magasins.destroy', $magasin)" :label="'le magasin « '.$magasin->nom.' »'" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">Aucun magasin pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $magasins->links() }}
    </div>
@endsection
