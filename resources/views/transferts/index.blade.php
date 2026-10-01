@extends('layouts.app')

@section('title', 'Transferts de stock')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Transferts de stock</h2>
        @can('stock.transferer')
            <a href="{{ route('transferts.create') }}" class="btn btn-primary">Nouveau transfert</a>
        @endcan
    </div>

    <div class="d-flex justify-content-end mb-3">
        <x-export-buttons :pdf-route="route('transferts.pdf', request()->query())" :excel-route="route('transferts.excel', request()->query())" :tout="true" />
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Produit</th>
                        <th>De</th>
                        <th>Vers</th>
                        <th>Quantité</th>
                        <th>Par</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transferts as $transfert)
                        <tr>
                            <td>{{ $transfert->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $transfert->produit->libelle_affichage }} <code class="small">{{ $transfert->produit->sku }}</code></td>
                            <td>{{ $transfert->magasinSource->nom }}</td>
                            <td>{{ $transfert->magasinDestination->nom }}</td>
                            <td>{{ quantite($transfert->quantite) }} pièces</td>
                            <td>{{ $transfert->auteur->name }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">Aucun transfert pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $transferts->links() }}
    </div>
@endsection
