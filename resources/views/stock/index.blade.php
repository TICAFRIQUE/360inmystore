@extends('layouts.app')

@section('title', 'État de stock')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h2 class="h4 mb-0">État de stock</h2>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('stock.mouvements.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-clock-history me-1"></i>Historique des mouvements
            </a>
            @can('stock.transferer')
                <a href="{{ route('transferts.create') }}" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-left-right me-1"></i>Transfert
                </a>
            @endcan
            @can('stock.ajuster')
                <a href="{{ route('stock.mouvements.create') }}" class="btn btn-outline-primary">
                    <i class="bi bi-sliders me-1"></i>Casse / ajustement
                </a>
            @endcan
        </div>
    </div>

    <h2 class="h4 mb-3 d-none d-print-block">État de stock</h2>

    <div class="row g-3 mb-3 d-print-none">
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-secondary small">Pièces en stock</div>
                    <div class="fs-5 fw-medium">{{ quantite($kpis['totalPieces']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-secondary small">Valeur du stock (CMP)</div>
                    <div class="fs-5 fw-medium">{{ montant($kpis['valeurStock']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-secondary small">Produits sous seuil</div>
                    <div class="fs-5 fw-medium {{ $kpis['sousSeuil'] > 0 ? 'text-danger' : '' }}">{{ $kpis['sousSeuil'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-secondary small">Magasin(s) concerné(s)</div>
                    <div class="fs-5 fw-medium">{{ $kpis['nbMagasins'] }}</div>
                </div>
            </div>
        </div>
    </div>

    <x-recherche-form :action="route('stock.index')" placeholder="Produit commençant par…"
        :autres-params="['magasin_id', 'produit_id', 'sous_seuil']">
        <div>
            <select name="magasin_id" class="form-select" onchange="this.form.submit()">
                <option value="">Tous les magasins</option>
                @foreach ($magasins as $magasin)
                    <option value="{{ $magasin->id }}" @selected(request('magasin_id') == $magasin->id)>{{ $magasin->nom }}</option>
                @endforeach
            </select>
        </div>
        <div style="min-width: 260px;">
            <select name="produit_id" id="produit_id_filtre" class="form-select" onchange="this.form.submit()">
                <option value="">Tous les produits</option>
                @foreach ($produitsFiltrables as $produit)
                    <option value="{{ $produit->id }}" @selected(request('produit_id') == $produit->id)>{{ $produit->libelle_affichage }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-check mb-2">
            <input type="checkbox" name="sous_seuil" value="1" id="sous_seuil" class="form-check-input" onchange="this.form.submit()" @checked(request()->boolean('sous_seuil'))>
            <label for="sous_seuil" class="form-check-label">Sous le seuil d'alerte uniquement</label>
        </div>
        <x-slot:export>
            <x-export-buttons :pdf-route="route('stock.pdf', request()->query())" :excel-route="route('stock.excel', request()->query())" :tout="true" />
        </x-slot:export>
    </x-recherche-form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <x-th-tri champ="nom" label="Produit" />
                        <th>Stock</th>
                        <th>Seuil d'alerte</th>
                        <th>Coût moyen pondéré</th>
                        <th>Prix de vente</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($produits as $produit)
                        <tr>
                            @php($total = $produit->totalStock($magasinsAffiches))
                            <td>{{ $produit->libelle_affichage }} <code class="small">{{ $produit->sku }}</code></td>
                            <td class="{{ $total['sous_seuil'] ? 'text-danger' : '' }}">
                                {{ quantite($total['quantite']) }} {{ $produit->unite_base_libelle_complet }}
                                @if ($total['sous_seuil'])
                                    <i class="bi bi-exclamation-triangle-fill ms-1" title="Sous le seuil d'alerte"></i>
                                @endif
                            </td>
                            <td>{{ $produit->seuil_alerte }}</td>
                            <td>{{ montant($produit->coutMoyenPondere()) }}</td>
                            <td>{{ montant($produit->prix_piece) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">Aucun produit trouvé.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($produits instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <div class="mt-3 d-print-none">
            {{ $produits->links() }}
        </div>
    @endif

    <div class="text-secondary small mt-3 d-none d-print-block">
        Édité le {{ now()->format('d/m/Y à H:i') }}
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            window.initSelect2('#produit_id_filtre', { placeholder: 'Tous les produits' });
        });
    </script>
@endpush
