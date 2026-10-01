@extends('layouts.app')

@section('title', 'Catégories')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Catégories</h2>
        <a href="{{ route('categories.create') }}" class="btn btn-primary">Nouvelle catégorie</a>
    </div>

    <x-recherche-form :action="route('categories.index')" placeholder="Rechercher une catégorie…">
        <x-slot:export>
            <x-export-buttons :pdf-route="route('categories.pdf', request()->query())" :excel-route="route('categories.excel', request()->query())" :tout="true" />
        </x-slot:export>
    </x-recherche-form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <x-th-tri champ="nom" label="Nom" />
                        <th>Catégorie parente</th>
                        <th>Produits</th>
                        <th>Statut</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($categories as $categorie)
                        <tr>
                            <td>
                                @if ($categorie->parent_id)
                                    <span class="text-secondary">— </span>
                                @endif
                                {{ $categorie->nom }}
                            </td>
                            <td>{{ $categorie->parent->nom ?? '—' }}</td>
                            <td>{{ $categorie->produits_count }}</td>
                            <td>
                                @if ($categorie->actif)
                                    <span class="badge text-bg-success">Active</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactive</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <x-edit-button :href="route('categories.edit', $categorie)" />
                                <x-delete-button :action="route('categories.destroy', $categorie)" :label="'la catégorie « '.$categorie->nom.' »'" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">Aucune catégorie pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $categories->links() }}
    </div>
@endsection
