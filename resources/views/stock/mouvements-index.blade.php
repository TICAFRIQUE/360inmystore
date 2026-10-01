@extends('layouts.app')

@section('title', 'Historique des mouvements de stock')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-1">
        <div class="d-flex align-items-center gap-2">
            <x-bouton-retour :route="route('stock.index')" />
            <h2 class="h4 mb-0">Historique des mouvements de stock</h2>
        </div>
        <div class="d-flex gap-2">
            <x-export-buttons :pdf-route="route('stock.mouvements.pdf', request()->query())" :excel-route="route('stock.mouvements.excel', request()->query())" :tout="true" />
        </div>
    </div>
    <p class="text-secondary small mb-3">
        Période : du {{ \Illuminate\Support\Carbon::parse($dateDebut)->format('d/m/Y') }} au {{ \Illuminate\Support\Carbon::parse($dateFin)->format('d/m/Y') }}
        @if ($type)
            · Type : {{ $type->libelle() }}
        @endif
    </p>

    <form method="GET" action="{{ route('stock.mouvements.index') }}" class="row g-2 mb-3 align-items-end d-print-none">
        <div class="col-auto">
            <label for="date_debut" class="form-label small mb-1">Du</label>
            <input type="date" name="date_debut" id="date_debut" class="form-control" value="{{ $dateDebut }}" onchange="this.form.submit()">
        </div>
        <div class="col-auto">
            <label for="date_fin" class="form-label small mb-1">Au</label>
            <input type="date" name="date_fin" id="date_fin" class="form-control" value="{{ $dateFin }}" onchange="this.form.submit()">
        </div>
        <div class="col-auto">
            <label for="type" class="form-label small mb-1">Type</label>
            <select name="type" id="type" class="form-select" onchange="this.form.submit()">
                <option value="">Tous les types</option>
                @foreach ($types as $t)
                    <option value="{{ $t->value }}" @selected($type === $t)>{{ $t->libelle() }}</option>
                @endforeach
            </select>
        </div>
        @if (request()->hasAny(['date_debut', 'date_fin', 'type']))
            <div class="col-auto">
                <x-bouton-reinitialiser :route="route('stock.mouvements.index')" />
            </div>
        @endif
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Produit</th>
                        <th>Destination</th>
                        <th>Quantité</th>
                        <th>Auteur</th>
                        <th>Motif</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mouvements as $mouvement)
                        <tr>
                            <td>{{ $mouvement->created_at->format('d/m/Y H:i') }}</td>
                            <td><span class="badge {{ $mouvement->type->classeBadge() }}">{{ $mouvement->type->libelle() }}</span></td>
                            <td>{{ $mouvement->produit->libelle_affichage }} <code class="small">{{ $mouvement->produit->sku }}</code></td>
                            <td>{{ $mouvement->magasin->nom }}</td>
                            <td class="{{ $mouvement->quantite >= 0 ? 'text-success' : 'text-danger' }} fw-medium">
                                {{ $mouvement->quantite >= 0 ? '+' : '' }}{{ quantite($mouvement->quantite) }}
                            </td>
                            <td>{{ $mouvement->auteur->name }}</td>
                            <td>{{ $mouvement->motif ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-secondary py-4">Aucun mouvement sur cette période.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($mouvements instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <div class="mt-3 d-print-none">
            {{ $mouvements->links() }}
        </div>
    @endif
@endsection
