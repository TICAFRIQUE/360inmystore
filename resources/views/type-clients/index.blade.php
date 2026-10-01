@extends('layouts.app')

@section('title', 'Types de client')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Types de client</h2>
        <a href="{{ route('type-clients.create') }}" class="btn btn-primary">Nouveau type</a>
    </div>

    <x-recherche-form :action="route('type-clients.index')" placeholder="Rechercher un type…">
        <x-slot:export>
            <x-export-buttons :pdf-route="route('type-clients.pdf', request()->query())" :excel-route="route('type-clients.excel', request()->query())" :tout="true" />
        </x-slot:export>
    </x-recherche-form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <x-th-tri champ="nom" label="Nom" />
                        <th>Clients</th>
                        <x-th-tri champ="actif" label="Statut" />
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($typesClient as $typeClient)
                        <tr>
                            <td>{{ $typeClient->nom }}</td>
                            <td>{{ $typeClient->clients_count }}</td>
                            <td>
                                @if ($typeClient->actif)
                                    <span class="badge text-bg-success">Actif</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <x-edit-button :href="route('type-clients.edit', $typeClient)" />
                                <x-delete-button :action="route('type-clients.destroy', $typeClient)" :label="'le type « '.$typeClient->nom.' »'" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-secondary py-4">Aucun type de client pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $typesClient->links() }}
    </div>
@endsection
