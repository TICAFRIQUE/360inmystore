@extends('layouts.app')

@section('title', 'Mouvements de caisse')

@section('content')
    <h2 class="h4 mb-1">Mouvements de caisse</h2>
    @php
        $magasinIdActif = auth()->user()->magasin_id ?: request('magasin_id');
        $filtresActifs = collect([
            $magasinIdActif ? 'Magasin : ' . $magasins->firstWhere('id', (int) $magasinIdActif)?->nom : null,
            request('caisse_id') ? 'Caisse : ' . $caisses->firstWhere('id', (int) request('caisse_id'))?->nom : null,
            request('caissier_id') ? 'Auteur : ' . $caissiers->firstWhere('id', (int) request('caissier_id'))?->name : null,
            request('type') ? 'Type : ' . match (request('type')) {
                'sortie' => 'Sortie',
                'vente' => 'Vente / Facture',
                'reglement' => 'Règlement client',
                'retour' => 'Retour (avoir)',
                default => 'Entrée',
            } : null,
        ])->filter();
    @endphp
    <p class="text-secondary small mb-3">
        Période : du {{ $debut->format('d/m/Y') }} au {{ $fin->format('d/m/Y') }}
        @if ($filtresActifs->isNotEmpty())
            · {{ $filtresActifs->implode(' · ') }}
        @endif
    </p>

    <form method="GET" action="{{ route('rapports.mouvements-caisse') }}" class="row g-2 mb-3 d-print-none">
        <div class="col-auto">
            <input type="date" name="debut" value="{{ $debut->toDateString() }}" class="form-control form-control-sm">
        </div>
        <div class="col-auto">
            <input type="date" name="fin" value="{{ $fin->toDateString() }}" class="form-control form-control-sm">
        </div>
        @unless (auth()->user()->magasin_id)
            <div class="col-auto">
                <select name="magasin_id" class="form-select form-select-sm">
                    <option value="">Tous les magasins</option>
                    @foreach ($magasins as $magasin)
                        <option value="{{ $magasin->id }}" @selected(request('magasin_id') == $magasin->id)>{{ $magasin->nom }}</option>
                    @endforeach
                </select>
            </div>
        @endunless
        <div class="col-auto">
            <select name="caisse_id" class="form-select form-select-sm">
                <option value="">Toutes les caisses</option>
                @foreach ($caisses as $caisse)
                    <option value="{{ $caisse->id }}" @selected(request('caisse_id') == $caisse->id)>{{ $caisse->nom }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <select name="caissier_id" class="form-select form-select-sm">
                <option value="">Tous les auteurs</option>
                @foreach ($caissiers as $caissier)
                    <option value="{{ $caissier->id }}" @selected(request('caissier_id') == $caissier->id)>{{ $caissier->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <select name="type" class="form-select form-select-sm">
                <option value="">Tous les types</option>
                <option value="entree" @selected(request('type') === 'entree')>Entrée</option>
                <option value="sortie" @selected(request('type') === 'sortie')>Sortie</option>
                <option value="vente" @selected(request('type') === 'vente')>Vente / Facture</option>
                <option value="reglement" @selected(request('type') === 'reglement')>Règlement client</option>
                <option value="retour" @selected(request('type') === 'retour')>Retour (avoir)</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filtrer</button>
        </div>
        @if (request()->hasAny(['debut', 'fin', 'magasin_id', 'caisse_id', 'caissier_id', 'type']))
            <div class="col-auto">
                <x-bouton-reinitialiser :route="route('rapports.mouvements-caisse')" />
            </div>
        @endif
        <div class="col-auto ms-auto">
            <x-export-buttons :pdf-route="route('rapports.mouvements-caisse.pdf', request()->query())" :excel-route="route('rapports.mouvements-caisse.excel', request()->query())" />
        </div>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <x-kpi-card label="Mouvements" icon="bi-arrow-left-right" color="primary"
                :value="$nombre" />
        </div>
        <div class="col-6 col-md-3">
            <x-kpi-card label="Ventes en espèces" icon="bi-cash-stack" color="success"
                :value="montant($totalVentes)" />
        </div>
        <div class="col-6 col-md-3">
            <x-kpi-card label="Total entrées (manuelles)" icon="bi-box-arrow-in-down" color="success"
                :value="montant($totalEntrees)" />
        </div>
        <div class="col-6 col-md-3">
            <x-kpi-card label="Total sorties" icon="bi-box-arrow-up" color="danger"
                :value="montant($totalSorties)" />
        </div>
        <div class="col-6 col-md-3">
            <x-kpi-card label="Retours (avoir, hors tiroir)" icon="bi-arrow-return-left" color="warning"
                :value="montant($totalRetours)" />
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Caisse</th>
                        <th>Magasin</th>
                        <th>Type</th>
                        <th>Motif</th>
                        <th class="text-end">Montant</th>
                        <th>Auteur</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mouvements as $mouvement)
                        <tr>
                            <td>{{ \Illuminate\Support\Carbon::parse($mouvement->created_at)->format('d/m/Y H:i') }}</td>
                            <td>{{ $mouvement->caisse_nom }}</td>
                            <td>{{ $mouvement->magasin_nom }}</td>
                            <td><span class="badge {{ $mouvement->type_badge }}">{{ $mouvement->type_libelle }}</span></td>
                            <td>{{ $mouvement->motif }}</td>
                            <td class="text-end fw-medium">
                                @if ($mouvement->hors_tiroir)
                                    {{ montant($mouvement->montant) }}
                                @else
                                    {{ $mouvement->signe_positif ? '+ ' : '− ' }}{{ montant($mouvement->montant) }}
                                @endif
                            </td>
                            <td>{{ $mouvement->auteur_nom ?? 'Utilisateur supprimé' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-secondary py-4">Aucun mouvement de caisse sur cette période.</td>
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
