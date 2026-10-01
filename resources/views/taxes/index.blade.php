@extends('layouts.app')

@section('title', 'Taxes')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Taxes</h2>
        <a href="{{ route('taxes.create') }}" class="btn btn-primary">Nouvelle taxe</a>
    </div>

    <x-recherche-form :action="route('taxes.index')" placeholder="Rechercher une taxe…">
        <x-slot:export>
            <x-export-buttons :pdf-route="route('taxes.pdf', request()->query())" :excel-route="route('taxes.excel', request()->query())" :tout="true" />
        </x-slot:export>
    </x-recherche-form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <x-th-tri champ="nom" label="Nom" />
                        <x-th-tri champ="taux" label="Taux" />
                        <x-th-tri champ="actif" label="Statut" />
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($taxes as $taxe)
                        <tr>
                            <td>{{ $taxe->nom }}</td>
                            <td>{{ $taxe->taux }} %</td>
                            <td>
                                @if ($taxe->actif)
                                    <span class="badge text-bg-success">Active</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactive</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <x-edit-button :href="route('taxes.edit', $taxe)" />
                                <x-delete-button :action="route('taxes.destroy', $taxe)" :label="'la taxe « '.$taxe->nom.' »'" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-secondary py-4">Aucune taxe pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $taxes->links() }}
    </div>
@endsection
