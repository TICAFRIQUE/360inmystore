{{-- Saisie d'une dépense (voir DepenseController::store). $sources : d'où
     l'utilisateur peut sortir l'argent — sa caisse ouverte, et la trésorerie
     s'il la gère. Le magasin concerné n'est demandé que pour un paiement
     depuis la trésorerie : depuis une caisse, c'est celui de la caisse. --}}
@extends('layouts.app')

@section('title', 'Nouvelle dépense')

@section('content')
    <div class="d-flex align-items-center gap-2 mb-3">
        <x-bouton-retour :route="route('depenses.index')" />
        <h2 class="h4 mb-0">Nouvelle dépense</h2>
    </div>

    <div class="card border-0 shadow-sm mx-auto" style="max-width: 640px;">
        <div class="card-body">
            @if ($motifs->isEmpty())
                <div class="etat-vide etat-vide--grand">
                    <i class="bi bi-tag"></i>
                    <h3 class="h5">Aucun motif de dépense</h3>
                    <p>Une dépense se classe sous un motif marqué « dépense » : loyer, salaires, électricité, transport…</p>
                    @if ($peutCreerMotif)
                        <a href="{{ route('motifs-mouvement.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Créer un motif de dépense</a>
                    @else
                        <p class="fw-medium">Demandez à votre responsable d'en créer un.</p>
                    @endif
                </div>
            @else
                <form method="POST" action="{{ route('depenses.store') }}" enctype="multipart/form-data"
                      x-data="{ source: @js(old('source', $sources->keys()->first())) }">
                    @csrf

                    <div class="mb-3">
                        <label for="motif_mouvement_id" class="form-label">Motif<span class="required-marker">*</span></label>
                        <select name="motif_mouvement_id" id="motif_mouvement_id" class="form-select @error('motif_mouvement_id') is-invalid @enderror" required autofocus>
                            <option value="">— Choisir —</option>
                            @foreach ($motifs as $motif)
                                <option value="{{ $motif->id }}" @selected(old('motif_mouvement_id') == $motif->id)>{{ $motif->nom }}</option>
                            @endforeach
                        </select>
                        @error('motif_mouvement_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="montant" class="form-label">Montant ({{ App\Models\Devise::abreviationActuelle() }})<span class="required-marker">*</span></label>
                            <input type="number" name="montant" id="montant" class="form-control @error('montant') is-invalid @enderror" value="{{ old('montant') }}" min="1" required>
                            @error('montant') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label for="source" class="form-label">Payée depuis<span class="required-marker">*</span></label>
                            <select name="source" id="source" class="form-select @error('source') is-invalid @enderror" x-model="source" required>
                                @foreach ($sources as $cle => $possible)
                                    <option value="{{ $cle }}">{{ $possible['libelle'] }}</option>
                                @endforeach
                            </select>
                            @error('source') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text">L'argent sort de là : il doit y être.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="beneficiaire" class="form-label">Bénéficiaire</label>
                        <input type="text" name="beneficiaire" id="beneficiaire" class="form-control @error('beneficiaire') is-invalid @enderror"
                               value="{{ old('beneficiaire') }}" maxlength="255" placeholder="À qui l'argent a été remis">
                        @error('beneficiaire') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    @if ($magasins->isNotEmpty())
                        <div class="mb-3" x-show="source !== 'session'" x-cloak>
                            <label for="magasin_id" class="form-label">Magasin concerné</label>
                            <select name="magasin_id" id="magasin_id" class="form-select" :disabled="source === 'session'">
                                <option value="">Toute l'entreprise</option>
                                @foreach ($magasins as $magasin)
                                    <option value="{{ $magasin->id }}" @selected(old('magasin_id') == $magasin->id)>{{ $magasin->nom }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Le loyer d'un magasin lui revient ; un abonnement commun concerne toute l'entreprise.</div>
                        </div>
                    @endif

                    <div class="mb-4">
                        <label for="recu" class="form-label">Photo du reçu</label>
                        <input type="file" name="recu" id="recu" class="form-control @error('recu') is-invalid @enderror" accept="image/*">
                        @error('recu') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">Facultative. Une photo prise au téléphone convient : elle est réduite automatiquement.</div>
                    </div>

                    <button type="submit" class="btn btn-primary">Enregistrer la dépense</button>
                    <a href="{{ route('depenses.index') }}" class="btn btn-link">Annuler</a>
                </form>
            @endif
        </div>
    </div>
@endsection
