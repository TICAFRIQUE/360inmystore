@extends('layouts.app')

@section('title', "Paiement de l'abonnement")

@section('content')
    <div class="d-flex align-items-center gap-2 mb-3">
        <x-bouton-retour :route="route('abonnement.mon')" />
        <h2 class="h4 mb-0">Paiement de l'abonnement</h2>
    </div>

    <div class="row">
        <div class="col-12 col-lg-7 col-xl-6">
            @if ($simulation)
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>Paiement simulé.</strong> Aucun montant n'est débité : ce parcours sert à tester
                    l'abonnement en attendant le paiement en ligne réel.
                </div>
            @endif

            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-baseline border-bottom pb-3 mb-3">
                        <div>
                            <div class="fw-medium">Formule {{ $formule->nom }}</div>
                            <div class="text-secondary small">
                                {{ $formule->illimite ? 'Sans limite de durée' : $formule->jours.' jours' }}
                            </div>
                        </div>
                        <div class="fs-4 fw-semibold text-primary">{{ montant($formule->prix) }}</div>
                    </div>

                    <form method="POST" action="{{ route('abonnement.paiement.store', $formule->id) }}">
                        @csrf

                        <fieldset class="mb-3">
                            <legend class="form-label">Moyen de paiement<span class="required-marker">*</span></legend>
                            @foreach ($moyens as $code => $libelle)
                                <div class="form-check">
                                    <input type="radio" name="moyen" value="{{ $code }}" id="moyen-{{ $code }}" class="form-check-input"
                                           @checked(old('moyen', array_key_first($moyens)) === $code) required>
                                    <label class="form-check-label" for="moyen-{{ $code }}">{{ $libelle }}</label>
                                </div>
                            @endforeach
                            @error('moyen') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        </fieldset>

                        @if ($simulation)
                            <div class="mb-4">
                                <label for="resultat" class="form-label">Résultat à simuler</label>
                                <select name="resultat" id="resultat" class="form-select" style="max-width: 16rem;">
                                    <option value="reussi" @selected(old('resultat') !== 'refuse')>Paiement accepté</option>
                                    <option value="refuse" @selected(old('resultat') === 'refuse')>Paiement refusé</option>
                                </select>
                            </div>
                        @endif

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-credit-card me-1"></i>Payer {{ montant($formule->prix) }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
