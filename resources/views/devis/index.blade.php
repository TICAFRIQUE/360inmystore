@extends('layouts.app')

@section('title', 'Devis')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Devis</h2>
        @can('devis.gerer')
            <a href="{{ route('devis.create') }}" class="btn btn-primary">Nouveau devis</a>
        @endcan
    </div>

    <x-recherche-form :action="route('devis.index')" placeholder="Numéro ou client…"
        :autres-params="['statut']">
        <div>
            <label for="statut" class="form-label small mb-1">Statut</label>
            <select name="statut" id="statut" class="form-select" onchange="this.form.submit()">
                <option value="">Tous les statuts</option>
                <option value="brouillon" @selected(request('statut') === 'brouillon')>Brouillon</option>
                <option value="refuse" @selected(request('statut') === 'refuse')>Refusé / annulé</option>
                <option value="transforme" @selected(request('statut') === 'transforme')>Transformé en vente</option>
            </select>
        </div>
        <x-slot:export>
            <x-export-buttons :pdf-route="route('devis.liste.pdf', request()->query())" :excel-route="route('devis.liste.excel', request()->query())" :tout="true" />
        </x-slot:export>
    </x-recherche-form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <x-th-tri champ="numero" label="Numéro" />
                        <th>Client</th>
                        <x-th-tri champ="statut" label="Statut" />
                        <x-th-tri champ="date_validite" label="Valide jusqu'au" />
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($devis as $unDevis)
                        <tr>
                            <td><code>{{ $unDevis->numero }}</code></td>
                            <td>{{ $unDevis->client->nom }}</td>
                            <td><span class="badge {{ $unDevis->statutEffectif()->classeBadge() }}">{{ $unDevis->statutEffectif()->libelle() }}</span></td>
                            <td>{{ $unDevis->date_validite->format('d/m/Y') }}</td>
                            <td class="text-end">
                                <a href="{{ route('devis.show', $unDevis) }}" class="btn btn-sm btn-icon btn-outline-secondary" title="Voir">
                                    <i class="bi bi-eye"></i>
                                    <span class="visually-hidden">Voir</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">Aucun devis pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $devis->links() }}
    </div>
@endsection
