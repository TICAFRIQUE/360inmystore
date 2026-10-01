@extends('layouts.app')

@section('title', 'Bon de livraison')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Bon de livraison</h2>
    </div>

    <p class="text-secondary small mb-3">
        Toutes les factures pas encore entièrement livrées (comptant comme crédit) —
        ouvrez une facture pour y enregistrer une livraison, comme depuis sa fiche.
    </p>

    <x-recherche-form :action="route('bons-livraison.index')" placeholder="Numéro de facture…">
        <x-slot:export>
            <x-export-buttons :pdf-route="route('bons-livraison.liste.pdf', request()->query())" :excel-route="route('bons-livraison.liste.excel', request()->query())" :tout="true" />
        </x-slot:export>
    </x-recherche-form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Numéro</th>
                        <th>Client</th>
                        <th>Date</th>
                        <th>Livraison</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ventes as $vente)
                        <tr>
                            <td><code>{{ $vente->numero }}</code></td>
                            <td>{{ $vente->client->nom ?? 'Comptant' }}</td>
                            <td>{{ $vente->created_at->format('d/m/Y H:i') }}</td>
                            <td>
                                <span class="badge text-bg-warning-subtle text-warning-emphasis">
                                    {{ quantite($vente->quantiteLivreePieces()) }}/{{ quantite($vente->lignes->sum('quantite_pieces')) }} pièce(s)
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('ventes.ticket', $vente) }}" class="btn btn-sm btn-outline-info">
                                    <i class="bi bi-truck me-1"></i>Voir la facture
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">Aucune facture à livrer pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $ventes->links() }}
    </div>
@endsection
