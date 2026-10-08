@extends('layouts.app')

@section('title', 'Produits')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h2 class="h4 mb-0">Produits</h2>
        <div class="d-flex gap-2 flex-wrap">
            @can('produit.creer')
                <a href="{{ route('produits.import') }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Importer</a>
                <a href="{{ route('produits.create') }}" class="btn btn-primary">Nouveau produit</a>
            @endcan
        </div>
    </div>

    <h2 class="h4 mb-3 d-none d-print-block">Produits</h2>

    <div class="d-print-none">
        <x-recherche-form :action="route('produits.index')" placeholder="SKU, nom ou code-barres…">
            <x-slot:export>
                <x-export-buttons :pdf-route="route('produits.pdf', request()->query())" :excel-route="route('produits.excel', request()->query())" :tout="true" />
            </x-slot:export>
        </x-recherche-form>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th></th>
                        <x-th-tri champ="sku" label="SKU" />
                        <x-th-tri champ="nom" label="Nom" />
                        <th>Catégorie</th>
                        <th>Stock</th>
                        <x-th-tri champ="prix_piece" label="Prix / unité" />
                        <th>Seuil d'alerte</th>
                        <x-th-tri champ="actif" label="Statut" />
                        <th class="text-end d-print-none">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($produits as $produit)
                        @php($total = $produit->totalStock($magasinsActifs))
                        <tr>
                            <td style="width: 48px;">
                                @if ($produit->getFirstMediaUrl('image'))
                                    <img src="{{ $produit->getFirstMediaUrl('image', 'thumb') ?: $produit->getFirstMediaUrl('image') }}" alt="" width="40" height="40" class="rounded object-fit-cover">
                                @else
                                    <div class="bg-light rounded d-flex align-items-center justify-content-center text-secondary" style="width:40px;height:40px;">
                                        <i class="bi bi-box-seam"></i>
                                    </div>
                                @endif
                            </td>
                            <td><code>{{ $produit->sku }}</code></td>
                            <td>{{ $produit->libelle_affichage }}</td>
                            <td>{{ $produit->categorie->nom }}</td>
                            <td class="{{ $total['sous_seuil'] ? 'text-danger' : '' }}">
                                {{ quantite($total['quantite']) }} {{ $produit->unite_base_libelle_complet }}
                                @if ($total['sous_seuil'])
                                    <i class="bi bi-exclamation-triangle-fill ms-1" title="Sous le seuil d'alerte"></i>
                                @endif
                            </td>
                            <td>{{ montant($produit->prix_piece) }} / {{ $produit->unite_base_libelle }}</td>
                            <td>{{ $produit->seuil_alerte }}</td>
                            <td>
                                @if ($produit->actif)
                                    <span class="badge text-bg-success">Actif</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactif</span>
                                @endif
                            </td>
                            <td class="text-end d-print-none">
                                <x-edit-button :href="route('produits.edit', $produit)" />
                                @can('produit.supprimer')
                                    <x-delete-button :action="route('produits.destroy', $produit)" :label="'le produit « '.$produit->libelle_affichage.' »'" />
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-secondary py-4">Aucun produit trouvé.</td>
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
@endsection
