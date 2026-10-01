@extends('layouts.app')

@section('title', 'Devises')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Devises</h2>
        <a href="{{ route('devises.create') }}" class="btn btn-primary">Nouvelle devise</a>
    </div>

    <p class="text-secondary small">
        Référentiel d'affichage uniquement — aucune conversion, aucun taux. Les
        montants restent en francs entiers, seule l'abréviation change. La
        devise active se choisit dans <a href="{{ route('parametres.edit') }}">Paramètres</a>.
    </p>

    <x-recherche-form :action="route('devises.index')" placeholder="Rechercher une devise…">
        <x-slot:export>
            <x-export-buttons :pdf-route="route('devises.pdf', request()->query())" :excel-route="route('devises.excel', request()->query())" :tout="true" />
        </x-slot:export>
    </x-recherche-form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <x-th-tri champ="nom" label="Nom" />
                        <x-th-tri champ="abreviation" label="Abréviation" />
                        <x-th-tri champ="actif" label="Statut" />
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($devises as $devise)
                        <tr>
                            <td>{{ $devise->nom }}</td>
                            <td>{{ $devise->abreviation }}</td>
                            <td>
                                @if ($devise->actif)
                                    <span class="badge text-bg-success">Active</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactive</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <x-edit-button :href="route('devises.edit', $devise)" />
                                <x-delete-button :action="route('devises.destroy', $devise)" :label="'la devise « '.$devise->nom.' »'" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-secondary py-4">Aucune devise pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $devises->links() }}
    </div>
@endsection
