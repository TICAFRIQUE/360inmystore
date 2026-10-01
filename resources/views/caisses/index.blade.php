@extends('layouts.app')

@section('title', 'Caisses')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Caisses</h2>
        <a href="{{ route('caisses.create') }}" class="btn btn-primary">Nouvelle caisse</a>
    </div>

    <x-recherche-form :action="route('caisses.index')" placeholder="Rechercher une caisse…">
        <x-slot:export>
            <x-export-buttons :pdf-route="route('caisses.pdf', request()->query())" :excel-route="route('caisses.excel', request()->query())" :tout="true" />
        </x-slot:export>
    </x-recherche-form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <x-th-tri champ="nom" label="Nom" />
                        <th>Magasin</th>
                        <th>État</th>
                        <x-th-tri champ="actif" label="Statut" />
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($caisses as $caisse)
                        <tr>
                            <td>{{ $caisse->nom }}</td>
                            <td>{{ $caisse->magasin->nom }}</td>
                            <td>
                                @if ($caisse->sessionCaisses->isNotEmpty())
                                    <span class="badge text-bg-warning">Occupée</span>
                                @else
                                    <span class="badge text-bg-success">Libre</span>
                                @endif
                            </td>
                            <td>
                                @if ($caisse->actif)
                                    <span class="badge text-bg-success">Active</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactive</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <x-edit-button :href="route('caisses.edit', $caisse)" />
                                <x-delete-button :action="route('caisses.destroy', $caisse)" :label="'la caisse « '.$caisse->nom.' »'" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">Aucune caisse pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $caisses->links() }}
    </div>
@endsection
