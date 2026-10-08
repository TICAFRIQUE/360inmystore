@extends('layouts.app')

@section('title', "Modifier le devis {$devis->numero}")

@section('content')
    <div x-data="devisApp({{ \Illuminate\Support\Js::from($produits) }}, {{ \Illuminate\Support\Js::from($panierInitial) }}, {{ \Illuminate\Support\Js::from($taxes->map(fn ($t) => ['id' => $t->id, 'libelle' => $t->nom, 'taux' => $t->taux])) }})">
        <div class="mb-3">
            <div class="d-flex align-items-center gap-2">
                <x-bouton-retour :route="route('devis.show', $devis)" />
                <h2 class="h4 mb-0">Modifier le devis {{ $devis->numero }}</h2>
            </div>
            <p class="text-secondary small mb-0 ms-5 ps-1">Client : {{ $devis->client->nom }}</p>
        </div>

        {{-- Même disposition que l'écran de caisse : les produits à gauche,
             le devis et ses informations à droite. --}}
        <div class="caisse">
            @include('partials.catalogue-vignettes')

            <aside class="caisse__panier" id="panier">
                @include('devis._panier')

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <form id="formDevis" method="POST" action="{{ route('devis.update', $devis) }}">
                            @csrf
                            @method('PUT')

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
                                    data-message="Enregistrer les modifications de ce devis ?"
                                    data-button-label="Enregistrer" data-button-class="btn-primary">
                                <i class="bi bi-check-circle me-1"></i>Enregistrer
                            </button>
                        </form>
                    </div>
                </div>
            </aside>
        </div>
    </div>
@endsection
