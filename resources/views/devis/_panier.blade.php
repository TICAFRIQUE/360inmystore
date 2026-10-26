{{-- Lignes du devis, partagées par la création et la modification : même
     présentation que le panier de l'écran de caisse (voir ventes/create),
     sans lieu de prélèvement ni paiement — un devis ne mouvemente rien. Le
     stock de chaque lieu est rappelé à titre indicatif. --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="carte-titre mb-3">
            <div>
                <h3 class="carte-titre__texte">
                    Lignes du devis
                    <span class="badge rounded-pill text-bg-primary ms-1" x-show="panier.length > 0" x-text="panier.length" x-cloak></span>
                </h3>
                <p class="carte-titre__aide">Montants indicatifs, au prix courant du catalogue — jamais figés.</p>
            </div>
        </div>

        <div class="etat-vide" x-show="panier.length === 0">
            <i class="bi bi-file-earmark-text"></i>
            <p>Le devis est vide. Choisissez un produit pour commencer.</p>
        </div>

        <template x-for="(ligne, index) in panier" :key="index">
            <div class="panier-ligne" x-init="chargerSources(ligne)">
                <div class="panier-ligne__tete">
                    <div class="panier-ligne__nom">
                        <strong x-text="ligne.produitLibelle"></strong>
                        <span x-show="(sourcesParProduit[ligne.produit_id] || []).length > 0"
                              x-text="'Stock : ' + (sourcesParProduit[ligne.produit_id] || []).map((source) => source.nom + (source.type === 'depot' ? ' (dépôt)' : '') + ' ' + source.quantite).join(' · ')"></span>
                    </div>
                    <span class="panier-ligne__total" x-text="montant(totalLigne(ligne))"></span>
                    <button type="button" class="btn btn-sm btn-icon btn-outline-danger flex-shrink-0" @click="retirerLigne(index)" title="Retirer du devis">
                        <i class="bi bi-trash"></i>
                        <span class="visually-hidden">Retirer du devis</span>
                    </button>
                </div>

                <div class="panier-ligne__reglages panier-ligne__reglages--devis">
                    <div class="panier-ligne__quantite">
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" :disabled="atteintMin(ligne)" @click="changerQuantite(ligne, -1)" aria-label="Diminuer la quantité">−</button>
                        <input type="number" class="form-control form-control-sm text-center p-1" min="1" aria-label="Quantité"
                               :value="ligne.quantite" @input="definirQuantite(ligne, $event.target.value, $event.target)"
                               @blur="validerQuantite(ligne, $event.target.value, $event.target)">
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" @click="changerQuantite(ligne, 1)" aria-label="Augmenter la quantité">+</button>
                    </div>

                    <select class="form-select form-select-sm" aria-label="Unité"
                            :class="{ 'is-invalid': estDoublon(index) || ligne.unite_vente_id === undefined }"
                            @change="changerVarianteDepuisSelect(ligne, $event.target.value)" required>
                        <option value="" :selected="ligne.unite_vente_id === undefined">— Unité —</option>
                        <option value="piece" :selected="ligne.unite_vente_id === null" x-text="produitDe(ligne).unite_base_libelle_complet + ' — ' + montant(produitDe(ligne).prix_piece)"></option>
                        <template x-for="unite in produitDe(ligne).unites" :key="unite.id">
                            <option :value="unite.id" :selected="unite.id === ligne.unite_vente_id" x-text="unite.libelle + ' — ' + montant(unite.prix)"></option>
                        </template>
                    </select>
                </div>

                <div class="text-danger small mt-1" x-show="estDoublon(index)" x-cloak>Déjà dans le devis avec la même unité.</div>

                @if (auth()->user()->can('vente.remise') || $taxes->isNotEmpty())
                    {{-- Remise et taxe : repliées tant qu'elles ne servent pas,
                         toujours dépliées dès qu'une valeur est posée. --}}
                    <button type="button" class="panier-ligne__bascule" @click="ligne.optionsOuvertes = ! ligne.optionsOuvertes"
                            x-show="! (ligne.remise_type || ligne.taxe_id)">
                        <i class="bi" :class="ligne.optionsOuvertes ? 'bi-chevron-up' : 'bi-plus-lg'"></i>
                        {{ auth()->user()->can('vente.remise') ? ($taxes->isNotEmpty() ? 'Remise, taxe' : 'Remise') : 'Taxe' }}
                    </button>

                    <div class="panier-ligne__options" x-show="ligne.optionsOuvertes || ligne.remise_type || ligne.taxe_id" x-cloak>
                        @can('vente.remise')
                            <select x-model="ligne.remise_type" class="form-select form-select-sm" aria-label="Remise">
                                <option value="">Sans remise</option>
                                <option value="montant">Remise ({{ App\Models\Devise::abreviationActuelle() }})</option>
                                <option value="pourcentage">Remise (%)</option>
                            </select>
                            <input type="number" x-model.number="ligne.remise_valeur" aria-label="Valeur de la remise"
                                   @input="if (ligne.remise_type === 'pourcentage' && ligne.remise_valeur > 100) ligne.remise_valeur = 100"
                                   x-show="ligne.remise_type" min="0" :max="ligne.remise_type === 'pourcentage' ? 100 : null" class="form-control form-control-sm" placeholder="Valeur">
                        @endcan
                        @if ($taxes->isNotEmpty())
                            <select x-model="ligne.taxe_id" class="form-select form-select-sm" aria-label="Taxe">
                                <option value="">Aucune taxe</option>
                                @foreach ($taxes as $taxe)
                                    <option value="{{ $taxe->id }}">{{ $taxe->nom }} ({{ $taxe->taux }}%)</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                @endif
            </div>
        </template>

        <div class="panier-totaux">
            <dl class="panier-totaux__lignes">
                <div>
                    <dt>Sous-total (HT)</dt>
                    <dd x-text="montant(sousTotal)"></dd>
                </div>
                <div x-show="totalTaxes > 0" x-cloak>
                    <dt>Total taxes</dt>
                    <dd x-text="montant(totalTaxes)"></dd>
                </div>
                <div class="panier-totaux__net">
                    <dt>Total (indicatif)</dt>
                    <dd x-text="montant(totalNet)"></dd>
                </div>
            </dl>
        </div>
    </div>
</div>

<div class="text-danger small mb-2" x-show="aUneLigneNonChoisie" x-cloak>
    <i class="bi bi-exclamation-triangle-fill me-1"></i>Choisissez une unité pour chaque ligne.
</div>
<div class="text-danger small mb-2" x-show="aUnDoublon" x-cloak>
    <i class="bi bi-exclamation-triangle-fill me-1"></i>Un même produit ne peut pas apparaître deux fois avec la même unité : corrigez avant d'enregistrer.
</div>
