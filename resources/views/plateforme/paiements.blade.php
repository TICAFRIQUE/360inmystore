@extends('layouts.app')

@section('title', 'Paiements')

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
        <h2 class="h4 mb-0">Paiements d'abonnement</h2>
        <div class="text-end">
            <div class="text-secondary small">Encaissé sur la période (hors simulation)</div>
            <div class="fs-5 fw-semibold">{{ montant($encaisse) }}</div>
        </div>
    </div>
    <p class="text-secondary small mb-3">
        Période : du {{ \Illuminate\Support\Carbon::parse($dateDebut)->format('d/m/Y') }} au {{ \Illuminate\Support\Carbon::parse($dateFin)->format('d/m/Y') }}
    </p>

    <x-recherche-form :action="route('plateforme.paiements.index')" placeholder="Entreprise ou référence…"
        :autres-params="['date_debut', 'date_fin', 'statut', 'origine']">
        <div>
            <label for="date_debut" class="form-label small mb-1">Du</label>
            <input type="date" name="date_debut" id="date_debut" class="form-control" value="{{ $dateDebut }}" onchange="this.form.submit()">
        </div>
        <div>
            <label for="date_fin" class="form-label small mb-1">Au</label>
            <input type="date" name="date_fin" id="date_fin" class="form-control" value="{{ $dateFin }}" onchange="this.form.submit()">
        </div>
        <div>
            <label for="statut" class="form-label small mb-1">Statut</label>
            <select name="statut" id="statut" class="form-select" onchange="this.form.submit()">
                <option value="">Tous</option>
                <option value="reussi" @selected(request('statut') === 'reussi')>Réussi</option>
                <option value="refuse" @selected(request('statut') === 'refuse')>Refusé</option>
            </select>
        </div>
        <div>
            <label for="origine" class="form-label small mb-1">Origine</label>
            <select name="origine" id="origine" class="form-select" onchange="this.form.submit()">
                <option value="">Toutes</option>
                <option value="manuel" @selected(request('origine') === 'manuel')>Enregistré à la main</option>
                <option value="en_ligne" @selected(request('origine') === 'en_ligne')>En ligne</option>
                <option value="simulation" @selected(request('origine') === 'simulation')>Simulation</option>
            </select>
        </div>
        <x-slot:export>
            <x-export-buttons :pdf-route="route('plateforme.paiements.pdf', request()->query())" :excel-route="route('plateforme.paiements.excel', request()->query())" />
        </x-slot:export>
    </x-recherche-form>

    <div class="card mb-3">
        @include('plateforme._paiements-table', ['paiements' => $paiements])
    </div>

    {{ $paiements->links() }}
@endsection
