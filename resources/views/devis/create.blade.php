@extends('layouts.app')

@section('title', 'Nouveau devis')

@section('content')
    <div x-data="devisApp({{ \Illuminate\Support\Js::from($produits) }}, {{ \Illuminate\Support\Js::from($panierInitial) }}, {{ \Illuminate\Support\Js::from($taxes->map(fn ($t) => ['id' => $t->id, 'libelle' => $t->nom, 'taux' => $t->taux])) }})">
        <div class="d-flex align-items-center gap-2 mb-3">
            <x-bouton-retour :route="route('devis.index')" />
            <h2 class="h4 mb-0">Nouveau devis</h2>
        </div>

        {{-- Même disposition que l'écran de caisse : les produits à gauche,
             le devis et ses informations à droite. --}}
        <div class="caisse">
            @include('partials.catalogue-vignettes')

            <aside class="caisse__panier" id="panier">
                @include('devis._panier')

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h3 class="carte-titre__texte mb-3">Informations</h3>

                        <form id="formDevis" method="POST" action="{{ route('devis.store') }}">
                            @csrf

                            <div class="mb-3">
                                <label for="client_id" class="form-label">Client<span class="required-marker">*</span></label>
                                <div class="d-flex gap-1">
                                    <select name="client_id" id="client_id" class="form-select @error('client_id') is-invalid @enderror" required>
                                        <option value="">— Choisir un client —</option>
                                        @foreach ($clients as $c)
                                            <option value="{{ $c->id }}">{{ $c->nom }}{{ $c->telephone ? ' — '.telephone($c->telephone) : '' }}</option>
                                        @endforeach
                                    </select>
                                    @can('client.gerer')
                                        <button type="button" class="btn btn-outline-primary btn-icon flex-shrink-0" title="Nouveau client"
                                                onclick="window.ouvrirClientRapide('client_id')">
                                            <i class="bi bi-person-plus"></i>
                                            <span class="visually-hidden">Nouveau client</span>
                                        </button>
                                    @endcan
                                </div>
                                @error('client_id') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                            </div>

                            <template x-for="(ligne, index) in panier" :key="'d'+index">
                                <span>
                                    <input type="hidden" :name="'lignes['+index+'][produit_id]'" :value="ligne.produit_id">
                                    <input type="hidden" :name="'lignes['+index+'][unite_vente_id]'" :value="ligne.unite_vente_id">
                                    <input type="hidden" :name="'lignes['+index+'][taxe_id]'" :value="ligne.taxe_id">
                                    <input type="hidden" :name="'lignes['+index+'][quantite]'" :value="ligne.quantite">
                                    <input type="hidden" :name="'lignes['+index+'][remise_type]'" :value="ligne.remise_type">
                                    <input type="hidden" :name="'lignes['+index+'][remise_valeur]'" :value="ligne.remise_valeur">
                                </span>
                            </template>

                            <button type="button" class="btn btn-primary w-100"
                                    :disabled="panier.length === 0 || aUnDoublon || aUneLigneNonChoisie"
                                    @click="declencherEnregistrement($event, 'formDevis')"
                                    data-form-id="formDevis"
                                    data-message="Créer ce devis ? Les montants restent indicatifs jusqu'à sa transformation en vente."
                                    data-button-label="Créer le devis" data-button-class="btn-primary">
                                <i class="bi bi-check-circle me-1"></i>Créer le devis
                            </button>
                        </form>
                    </div>
                </div>
            </aside>
        </div>

        @can('client.gerer')
            @include('partials.client-rapide-modal')
        @endcan
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            window.initSelect2('#client_id', { placeholder: '— Choisir un client —' });
        });
    </script>
@endpush
