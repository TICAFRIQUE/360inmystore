@extends('layouts.app')

@section('title', 'Clients')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h2 class="h4 mb-0">Clients</h2>
        <div class="d-flex gap-2 flex-wrap">
            @can('client.gerer')
                <a href="{{ route('clients.create') }}" class="btn btn-primary">Nouveau client</a>
            @endcan
        </div>
    </div>

    <h2 class="h4 mb-3 d-none d-print-block">Clients</h2>

    <div class="d-print-none">
        <x-recherche-form :action="route('clients.index')" placeholder="Nom ou téléphone…">
            <x-slot:export>
                <x-export-buttons :pdf-route="route('clients.pdf', request()->query())" :excel-route="route('clients.excel', request()->query())" :tout="true" />
            </x-slot:export>
        </x-recherche-form>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <x-th-tri champ="nom" label="Nom" />
                        <th>Type</th>
                        <th>Téléphone</th>
                        <th>Solde dû</th>
                        <th>Avoir</th>
                        <th>Limite de crédit</th>
                        <x-th-tri champ="actif" label="Statut" />
                        <th class="text-end d-print-none">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($clients as $client)
                        @php
                            $solde = (int) ($soldes[$client->id] ?? 0);
                            $soldeDu = max($solde, 0);
                            $avoir = max(-$solde, 0);
                        @endphp
                        <tr>
                            <td><code>{{ $client->code }}</code></td>
                            <td><a href="{{ route('clients.show', $client) }}">{{ $client->nom }}</a></td>
                            <td>{{ $client->typeClient->nom ?? '—' }}</td>
                            <td>{{ telephone($client->telephone) ?: '—' }}</td>
                            <td class="{{ $soldeDu > 0 ? 'text-danger fw-medium' : '' }}">{{ montant($soldeDu) }}</td>
                            <td class="{{ $avoir > 0 ? 'text-success fw-medium' : '' }}">{{ montant($avoir) }}</td>
                            <td>{{ $client->limite_credit !== null ? montant($client->limite_credit) : 'Illimitée' }}</td>
                            <td>
                                @if ($client->actif)
                                    <span class="badge text-bg-success">Actif</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactif</span>
                                @endif
                            </td>
                            <td class="text-end d-print-none">
                                <x-detail-button :href="route('clients.show', $client)" />
                                @can('client.gerer')
                                    <x-edit-button :href="route('clients.edit', $client)" />
                                    <x-delete-button :action="route('clients.destroy', $client)" :label="'le client « '.$client->nom.' »'" />
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-secondary py-4">Aucun client pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($clients instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <div class="mt-3 d-print-none">
            {{ $clients->links() }}
        </div>
    @endif
@endsection
