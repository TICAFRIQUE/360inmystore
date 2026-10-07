@extends('layouts.app')

@section('title', 'Fournisseurs')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h2 class="h4 mb-0">Fournisseurs</h2>
        <div class="d-flex gap-2 flex-wrap">
            @can('fournisseur.gerer')
                <a href="{{ route('fournisseurs.create') }}" class="btn btn-primary">Nouveau fournisseur</a>
            @endcan
        </div>
    </div>

    <h2 class="h4 mb-3 d-none d-print-block">Fournisseurs</h2>

    <div class="d-print-none">
        <x-recherche-form :action="route('fournisseurs.index')" placeholder="Nom, téléphone ou e-mail…">
            <x-slot:export>
                <x-export-buttons :pdf-route="route('fournisseurs.pdf', request()->query())" :excel-route="route('fournisseurs.excel', request()->query())" :tout="true" />
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
                        <th>Téléphone</th>
                        <th>E-mail</th>
                        <th>Solde dû</th>
                        <th>Avoir</th>
                        <x-th-tri champ="actif" label="Statut" />
                        <th class="text-end d-print-none">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($fournisseurs as $fournisseur)
                        @php
                            $solde = (int) ($soldes[$fournisseur->id] ?? 0);
                            $soldeDu = max($solde, 0);
                            $avoir = max(-$solde, 0);
                        @endphp
                        <tr>
                            <td><code>{{ $fournisseur->code }}</code></td>
                            <td><a href="{{ route('fournisseurs.show', $fournisseur) }}">{{ $fournisseur->nom }}</a></td>
                            <td>{{ telephone($fournisseur->telephone) ?: '—' }}</td>
                            <td>{{ $fournisseur->email ?? '—' }}</td>
                            <td class="{{ $soldeDu > 0 ? 'text-danger fw-medium' : '' }}">{{ montant($soldeDu) }}</td>
                            <td class="{{ $avoir > 0 ? 'text-success fw-medium' : '' }}">{{ montant($avoir) }}</td>
                            <td>
                                @if ($fournisseur->actif)
                                    <span class="badge text-bg-success">Actif</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactif</span>
                                @endif
                            </td>
                            <td class="text-end d-print-none">
                                <x-detail-button :href="route('fournisseurs.show', $fournisseur)" />
                                @can('fournisseur.gerer')
                                    <x-edit-button :href="route('fournisseurs.edit', $fournisseur)" />
                                    <x-delete-button :action="route('fournisseurs.destroy', $fournisseur)" :label="'le fournisseur « '.$fournisseur->nom.' »'" />
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-secondary py-4">Aucun fournisseur pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($fournisseurs instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <div class="mt-3 d-print-none">
            {{ $fournisseurs->links() }}
        </div>
    @endif
@endsection
