@extends('layouts.app')

@section('title', 'Clôturer la session')

@section('content')
    <div class="d-flex align-items-center gap-2 mb-3">
        <x-bouton-retour :route="route('sessions.show', $session)" />
        <h2 class="h4 mb-0">Clôturer la session — {{ $session->caisse->nom }}</h2>
    </div>

    {{-- Même "story" financière que sessions.show : ce qui a été vendu (tous
         moyens de paiement confondus) → ce qui est reparti en retour/avoir →
         entrées/sorties manuelles → ce qu'il reste réellement dans le tiroir
         (espèces uniquement, règle 10 — c'est ce chiffre, et lui seul, qui
         est comparé au comptage physique plus bas). --}}
    <div class="row g-2 mb-3">
        <div class="col-6 col-md">
            <x-kpi-card compact label="Fond de caisse" icon="bi-cash-stack" color="primary"
                :value="montant($session->fond_de_caisse)" />
        </div>
        <div class="col-6 col-md">
            <x-kpi-card compact label="Total ventes" icon="bi-receipt" color="info"
                :value="$nbVentes" />
        </div>
        <div class="col-6 col-md">
            <x-kpi-card compact label="Montant vente" icon="bi-graph-up-arrow" color="success"
                :value="montant($totalVentes)" />
        </div>
        <div class="col-6 col-md position-relative">
            <x-kpi-card compact label="Retour/avoir" icon="bi-arrow-return-left" :color="$totalRetours > 0 ? 'warning' : 'secondary'"
                :value="montant($totalRetours)" />
            @if ($nbRetours > 0)
                <span class="badge rounded-pill text-bg-warning position-absolute top-0 end-0 mt-n1 me-1">{{ $nbRetours }}</span>
            @endif
        </div>
        <div class="col-6 col-md">
            <x-kpi-card compact label="Entrée" icon="bi-box-arrow-in-down" color="primary"
                :value="montant($detailTheorique['entrees'])" />
        </div>
        <div class="col-6 col-md">
            <x-kpi-card compact label="Sortie" icon="bi-box-arrow-up" color="secondary"
                :value="montant($detailTheorique['sorties'])" />
        </div>
        <div class="col-6 col-md">
            <x-kpi-card compact solid label="Total en caisse" icon="bi-wallet2" color="success"
                :value="montant($theorique)" />
        </div>
    </div>

    @if ($paiementsParMoyen->isNotEmpty())
        <div class="card mb-3" style="max-width: 720px;">
            <div class="card-body">
                <h3 class="h6">Répartition par moyen de paiement</h3>
                <div class="row g-3">
                    @foreach ($paiementsParMoyen as $paiement)
                        <div class="col-6 col-md-3">
                            <div class="text-secondary small">{{ $paiement->moyenPaiement->nom }}</div>
                            <div class="fw-medium">{{ montant($paiement->total) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <div class="card mx-auto" style="max-width: 480px;"
         x-data="{
             montantCompte: {{ (int) old('montant_compte', $theorique) }},
             theorique: {{ (int) $theorique }},
             get ecart() { return (Number(this.montantCompte) || 0) - this.theorique; },
             declencherCloture(event) {
                 const form = document.getElementById('formCloture');
                 if (!form.checkValidity()) {
                     form.classList.add('was-validated');
                     form.reportValidity();
                     return;
                 }
                 window.bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmActionModal')).show(event.currentTarget);
             },
         }">
        <div class="card-body">
            <p class="text-secondary small mb-1">Seules les espèces sont comptées dans le tiroir.</p>
            <p class="mb-1">Théorique (fond de caisse + ventes et règlements espèces + entrées − sorties) : <strong>{{ montant($theorique) }}</strong></p>
            @if ($detailTheorique['entrees'] > 0 || $detailTheorique['sorties'] > 0)
                <p class="text-secondary small mb-3">
                    dont entrées de caisse : {{ montant($detailTheorique['entrees']) }},
                    sorties de caisse : − {{ montant($detailTheorique['sorties']) }}
                </p>
            @else
                <div class="mb-3"></div>
            @endif

            <form id="formCloture" method="POST" action="{{ route('sessions.cloturer', $session) }}">
                @csrf

                <div class="mb-2">
                    <label for="montant_compte" class="form-label">Montant compté dans le tiroir (F CFA)<span class="required-marker">*</span></label>
                    <input type="number" name="montant_compte" id="montant_compte"
                           x-model.number="montantCompte"
                           class="form-control @error('montant_compte') is-invalid @enderror"
                           min="0" required autofocus>
                    @error('montant_compte') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="mb-4 small" :class="ecart === 0 ? 'text-success' : 'text-danger'">
                    <template x-if="ecart === 0">
                        <span><i class="bi bi-check-circle me-1"></i>Conforme au théorique.</span>
                    </template>
                    <template x-if="ecart !== 0">
                        <span><i class="bi bi-exclamation-triangle-fill me-1"></i>Écart : <span x-text="(ecart > 0 ? '+' : '') + ecart"></span> F</span>
                    </template>
                </div>

                <button type="button" class="btn btn-primary"
                        @click="declencherCloture($event)"
                        data-form-id="formCloture"
                        :data-message="ecart === 0
                            ? 'Clôturer cette session ? Cette action est irréversible.'
                            : 'Écart de ' + (ecart > 0 ? '+' : '') + ecart + ' F par rapport au théorique. Clôturer quand même ?'"
                        data-button-label="Clôturer" data-button-class="btn-primary">
                    <i class="bi bi-lock me-1"></i>Clôturer
                </button>
                <a href="{{ route('sessions.show', $session) }}" class="btn btn-link">Annuler</a>
            </form>
        </div>
    </div>
@endsection
