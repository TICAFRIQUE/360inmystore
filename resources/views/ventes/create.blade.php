{{-- Écran de caisse : les produits à gauche (recherche, catégories,
     vignettes), le panier et l'encaissement à droite. Sous lg, les deux
     colonnes s'empilent et une barre fixée en bas rappelle le panier. Toute
     la logique est dans posApp (resources/js/app.js) ; les champs envoyés au
     serveur sont ceux des deux formulaires en bas de page, et eux seuls. --}}
@extends('layouts.app')

@section('title', 'Facture')

@section('content')
    <div x-data="posApp(
        {{ \Illuminate\Support\Js::from($produits) }},
        {{ \Illuminate\Support\Js::from($panierInitial) }},
        {{ \Illuminate\Support\Js::from($venteEnAttente->libelle ?? '') }},
        {{ \Illuminate\Support\Js::from(old('client_id', $devisTransformation->client_id ?? $venteEnAttente->client_id ?? '')) }},
        {{ \Illuminate\Support\Js::from($session->caisse->magasin_id) }},
        {{ \Illuminate\Support\Js::from($session->caisse->magasin->nom) }},
        {{ \Illuminate\Support\Js::from($clientSoldes) }},
        {{ \Illuminate\Support\Js::from($taxes->map(fn ($t) => ['id' => $t->id, 'libelle' => $t->nom, 'taux' => $t->taux])) }}
    )">
        <div class="page-entete">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <x-bouton-retour :route="$devisTransformation ? route('devis.show', $devisTransformation) : route('sessions.show', $session)" />
                    <h2 class="page-entete__titre">Facture — {{ $session->caisse->nom }}</h2>
                </div>
                <p class="page-entete__aide ms-5 ps-1">
                    {{ $session->caisse->magasin->nom }}
                    @if ($venteEnAttente)
                        <span class="badge text-bg-warning ms-2">
                            <i class="bi bi-hourglass-split me-1"></i>Reprise du panier en attente #{{ $venteEnAttente->id }}
                        </span>
                    @endif
                    @if ($devisTransformation)
                        <span class="badge text-bg-info ms-2">
                            <i class="bi bi-file-earmark-text me-1"></i>Transformation du devis {{ $devisTransformation->numero }}
                        </span>
                    @endif
                </p>
            </div>

            @unless ($devisTransformation)
                @can('vente.bon_livraison')
                    <div class="caisse__type">
                        <div class="btn-group" role="radiogroup" aria-label="Type de document">
                            <input type="radio" class="btn-check" name="typeDocument" id="typeDocumentFacture" value="facture" x-model="type" autocomplete="off">
                            <label class="btn btn-outline-primary" for="typeDocumentFacture"><i class="bi bi-receipt me-2"></i>Facture</label>
                            <input type="radio" class="btn-check" name="typeDocument" id="typeDocumentBonLivraison" value="bon_livraison" x-model="type" autocomplete="off">
                            <label class="btn btn-outline-primary" for="typeDocumentBonLivraison"><i class="bi bi-truck me-2"></i>Bon de livraison</label>
                        </div>
                        <p class="caisse__type-aide"
                           x-text="type === 'bon_livraison' ? 'Marchandise livrée tout de suite : la vente sera considérée comme entièrement livrée.' : 'Livraison organisée plus tard.'"></p>
                    </div>
                @endcan
            @endunless
        </div>

        <div class="caisse">
            {{-- Produits --}}
            @include('partials.catalogue-vignettes')

            {{-- Panier et encaissement --}}
            <aside class="caisse__panier" id="panier">
                <div>
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <div class="carte-titre mb-3">
                                <h3 class="carte-titre__texte">
                                    Panier
                                    <span class="badge rounded-pill text-bg-primary ms-1" x-show="panier.length > 0" x-text="panier.length" x-cloak></span>
                                </h3>
                            </div>

                            <div class="etat-vide" x-show="panier.length === 0">
                                <i class="bi bi-cart3"></i>
                                <p>Le panier est vide. Choisissez un produit pour commencer.</p>
                            </div>

                            <template x-for="(ligne, index) in panier" :key="index">
                                <div class="panier-ligne">
                                    <div class="panier-ligne__tete">
                                        <div class="panier-ligne__nom">
                                            <strong x-text="ligne.produitLibelle"></strong>
                                            <span x-show="stockDisponible(ligne) !== null" x-text="'Stock à ce lieu : ' + stockDisponible(ligne)"></span>
                                        </div>
                                        <span class="panier-ligne__total" x-text="montant(totalLigne(ligne))"></span>
                                        <button type="button" class="btn btn-sm btn-icon btn-outline-danger flex-shrink-0" @click="retirerLigne(index)" title="Retirer du panier">
                                            <i class="bi bi-x-lg"></i>
                                            <span class="visually-hidden">Retirer du panier</span>
                                        </button>
                                    </div>

                                    <div class="panier-ligne__reglages">
                                        <div class="panier-ligne__quantite">
                                            <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" :disabled="atteintMin(ligne)" @click="changerQuantite(ligne, -1)" aria-label="Diminuer la quantité">−</button>
                                            <input type="number" class="form-control form-control-sm text-center p-1" min="1" aria-label="Quantité"
                                                   :value="ligne.quantite" @input="definirQuantite(ligne, $event.target.value, $event.target)"
                                                   @blur="validerQuantite(ligne, $event.target.value, $event.target)">
                                            <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" :disabled="atteintMaxStock(ligne)" @click="changerQuantite(ligne, 1)" aria-label="Augmenter la quantité">+</button>
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

                                        <select class="form-select form-select-sm" aria-label="Lieu de prélèvement"
                                                :class="{ 'is-invalid': ligne.magasin_source_id === undefined }"
                                                @focus="chargerSources(ligne)"
                                                @change="choisirSource(ligne, $event.target.value)" required>
                                            <option value="" :selected="ligne.magasin_source_id === undefined">— Lieu de prélèvement —</option>
                                            <template x-for="source in sourcesDisponibles(ligne)" :key="source.id">
                                                <option :value="source.id" :selected="source.id === ligne.magasin_source_id"
                                                        x-text="(source.id === magasinCaisseId ? '📍 ' : 'Depuis : ') + source.nom + ' — ' + source.quantite + ' dispo'"></option>
                                            </template>
                                        </select>
                                    </div>

                                    <div class="text-danger small mt-1" x-show="enRupture(ligne)" x-cloak>
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Stock insuffisant à ce lieu.
                                    </div>
                                    <div class="text-danger small mt-1" x-show="estDoublon(index)" x-cloak>Déjà dans le panier avec la même unité et la même source.</div>

                                    @if (auth()->user()->can('vente.remise') || $taxes->isNotEmpty())
                                        {{-- Remise et taxe : repliées tant qu'elles ne servent pas, pour
                                             garder une ligne courte ; toujours dépliées dès qu'une valeur
                                             est posée, pour ne jamais la cacher. --}}
                                        <button type="button" class="panier-ligne__bascule" @click="ligne.optionsOuvertes = ! ligne.optionsOuvertes"
                                                x-show="! (ligne.remise_type || ligne.prixPersonnalise || ligne.taxe_id)">
                                            <i class="bi" :class="ligne.optionsOuvertes ? 'bi-chevron-up' : 'bi-plus-lg'"></i>
                                            {{ auth()->user()->can('vente.remise') ? ($taxes->isNotEmpty() ? 'Remise, taxe' : 'Remise') : 'Taxe' }}
                                        </button>

                                        <div class="panier-ligne__options" x-show="ligne.optionsOuvertes || ligne.remise_type || ligne.prixPersonnalise || ligne.taxe_id" x-cloak>
                                            @can('vente.remise')
                                                <select :value="ligne.prixPersonnalise ? 'prix' : ligne.remise_type" aria-label="Remise"
                                                        @change="choisirTypeRemise(ligne, $event.target.value)" class="form-select form-select-sm">
                                                    <option value="">Sans remise</option>
                                                    <option value="montant">Remise ({{ App\Models\Devise::abreviationActuelle() }})</option>
                                                    <option value="pourcentage">Remise (%)</option>
                                                    <option value="prix">Prix personnalisé</option>
                                                </select>
                                                <input type="number" x-model.number="ligne.remise_valeur" aria-label="Valeur de la remise"
                                                       @input="if (ligne.remise_type === 'pourcentage' && ligne.remise_valeur > 100) ligne.remise_valeur = 100"
                                                       x-show="ligne.remise_type && !ligne.prixPersonnalise" min="0" :max="ligne.remise_type === 'pourcentage' ? 100 : null" class="form-control form-control-sm" placeholder="Valeur">
                                                <template x-if="ligne.prixPersonnalise">
                                                    <div>
                                                        <input type="number" x-model.number="ligne.prixSaisi" aria-label="Prix personnalisé"
                                                               min="0" class="form-control form-control-sm" placeholder="Prix">
                                                        <div class="form-text mt-0">Catalogue : <span x-text="montant(ligne.prixUnitaire)"></span></div>
                                                    </div>
                                                </template>
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
                                @can('vente.remise')
                                    <div class="panier-totaux__remise">
                                        <label class="form-label mb-0" for="remise-totale-type">Remise sur le total</label>
                                        <select id="remise-totale-type" x-model="remiseTotaleType" class="form-select form-select-sm">
                                            <option value="">Sans remise</option>
                                            <option value="montant">Remise ({{ App\Models\Devise::abreviationActuelle() }})</option>
                                            <option value="pourcentage">Remise (%)</option>
                                        </select>
                                        <input type="number" x-model.number="remiseTotaleValeur" aria-label="Valeur de la remise sur le total"
                                               @input="if (remiseTotaleType === 'pourcentage' && remiseTotaleValeur > 100) remiseTotaleValeur = 100"
                                               x-show="remiseTotaleType" min="0" :max="remiseTotaleType === 'pourcentage' ? 100 : null" class="form-control form-control-sm" placeholder="Valeur">
                                    </div>
                                @endcan

                                <dl class="panier-totaux__lignes">
                                    <div>
                                        <dt>Sous-total (HT)</dt>
                                        <dd x-text="montant(sousTotal)"></dd>
                                    </div>
                                    <div x-show="totalTaxes > 0" x-cloak>
                                        <dt>Total taxes</dt>
                                        <dd x-text="montant(totalTaxes)"></dd>
                                    </div>
                                    @can('vente.remise')
                                        <div x-show="remiseTotaleMontant > 0" x-cloak>
                                            <dt>Remise totale</dt>
                                            <dd x-text="'− ' + montant(remiseTotaleMontant)"></dd>
                                        </div>
                                    @endcan
                                    <div class="panier-totaux__net">
                                        <dt>Net à payer</dt>
                                        <dd x-text="montant(totalNet)"></dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>

                    @if ($devisTransformation)
                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-body">
                                <h3 class="carte-titre__texte mb-3">Client</h3>
                                <p class="mb-0">
                                    <a href="{{ route('clients.show', $devisTransformation->client) }}">{{ $devisTransformation->client->nom }}</a>
                                </p>
                                <div class="form-text small">
                                    Client du devis {{ $devisTransformation->numero }} — non modifiable ici.
                                    @can('vente.credit')
                                        Un solde restant dû sera porté à son compte.
                                    @endcan
                                </div>
                            </div>
                        </div>
                    @elseif (auth()->user()->can('vente.credit'))
                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-body">
                                <h3 class="carte-titre__texte mb-3">Client (vente à crédit)</h3>
                                <div class="d-flex gap-1">
                                    <select id="client-picker" class="form-select form-select-sm" x-model="clientId">
                                        <option value="">— Vente comptant (aucun client) —</option>
                                        @foreach ($clients as $c)
                                            <option value="{{ $c->id }}">{{ $c->nom }}{{ $c->telephone ? ' — '.telephone($c->telephone) : '' }}</option>
                                        @endforeach
                                    </select>
                                    @can('client.gerer')
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-icon flex-shrink-0" title="Nouveau client"
                                                onclick="window.ouvrirClientRapide('client-picker')">
                                            <i class="bi bi-person-plus"></i>
                                            <span class="visually-hidden">Nouveau client</span>
                                        </button>
                                    @endcan
                                </div>
                                <div class="form-text small" x-show="clientId && !avoirDisponible" x-cloak>
                                    Le solde restant dû sera porté au compte de ce client.
                                </div>
                                <div class="form-text small text-success" x-show="clientId && avoirDisponible > 0" x-cloak>
                                    <i class="bi bi-piggy-bank me-1"></i>Ce client a un avoir de <strong x-text="avoirDisponible + ' ' + window.DEVISE_ABREVIATION"></strong>.
                                    <button type="button" class="btn btn-link btn-sm p-0 align-baseline" @click="appliquerAvoir()">
                                        Appliquer l'avoir à cette facture
                                    </button>
                                </div>
                                <div class="form-text small fw-semibold" :class="netApresAvoir > 0 ? 'text-warning' : 'text-success'"
                                     x-show="clientId && avoirDisponible > 0 && totalNet > 0" x-cloak>
                                    <i class="bi bi-check-circle me-1"></i>
                                    <span x-text="avoirApplicable + ' ' + window.DEVISE_ABREVIATION"></span> de l'avoir couvriront cette facture de <span x-text="totalNet + ' ' + window.DEVISE_ABREVIATION"></span>.
                                    <template x-if="netApresAvoir > 0">
                                        <span>Il restera <strong x-text="netApresAvoir + ' ' + window.DEVISE_ABREVIATION"></strong> à régler (en paiement et/ou en solde à crédit).</span>
                                    </template>
                                    <template x-if="netApresAvoir === 0">
                                        <span>Facture entièrement couverte par l'avoir, aucun paiement n'est nécessaire.</span>
                                    </template>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <div class="carte-titre mb-3">
                                <h3 class="carte-titre__texte">Paiement</h3>
                            </div>

                            <template x-for="(paiement, index) in paiements" :key="index">
                                <div class="paiement-ligne">
                                    <select x-model="paiement.moyen_paiement_id" class="form-select" aria-label="Moyen de paiement"
                                            :class="{ 'is-invalid': (Number(paiement.montant) || 0) > 0 && !paiement.moyen_paiement_id }">
                                        <option value="">Moyen de paiement…</option>
                                        @foreach ($moyensPaiement as $moyen)
                                            <option value="{{ $moyen->id }}">{{ $moyen->nom }}</option>
                                        @endforeach
                                    </select>
                                    <input type="number" x-model.number="paiement.montant" min="0" class="form-control" placeholder="Montant" aria-label="Montant">
                                    <button type="button" class="btn btn-outline-secondary btn-icon" @click="completerPaiement(index)" title="Compléter le solde restant">
                                        <i class="bi bi-magic"></i>
                                        <span class="visually-hidden">Compléter le solde restant</span>
                                    </button>
                                    <button type="button" class="btn btn-icon btn-outline-danger" @click="retirerPaiement(index)" title="Retirer ce moyen de paiement"
                                            x-show="paiements.length > 1">
                                        <i class="bi bi-x-lg"></i>
                                        <span class="visually-hidden">Retirer ce moyen de paiement</span>
                                    </button>
                                </div>
                            </template>
                            <button type="button" class="btn btn-sm btn-outline-primary" @click="ajouterPaiement()">
                                <i class="bi bi-plus-lg"></i> Ajouter un moyen de paiement
                            </button>

                            <div class="mt-3 fw-medium" :class="totalPaiements >= totalNet ? 'text-success' : (clientId ? 'text-warning' : 'text-danger')">
                                Total réglé : <span x-text="montant(totalPaiements)"></span>
                                <span x-show="totalPaiements < totalNet"> (net à payer : <span x-text="montant(totalNet)"></span>)</span>
                            </div>
                            <div class="mt-1 small fw-medium text-warning" x-show="clientId && totalPaiements < totalNet && !avoirDisponible" x-cloak>
                                <i class="bi bi-credit-card me-1"></i>Solde à crédit : <span x-text="montant(totalNet - totalPaiements)"></span> sur le compte du client.
                            </div>
                            <div class="mt-1 small fw-medium" :class="resteApresAvoir > 0 ? 'text-warning' : 'text-success'"
                                 x-show="clientId && avoirDisponible > 0 && totalPaiements < totalNet" x-cloak>
                                <i class="bi bi-piggy-bank me-1"></i>
                                <template x-if="resteApresAvoir > 0">
                                    <span>Avoir appliqué : <span x-text="avoirUtiliseSurCetteVente + ' ' + window.DEVISE_ABREVIATION"></span>. Il restera <strong x-text="resteApresAvoir + ' ' + window.DEVISE_ABREVIATION"></strong> de solde à crédit sur le compte du client.</span>
                                </template>
                                <template x-if="resteApresAvoir === 0">
                                    <span>Couvert par l'avoir du client (<span x-text="avoirUtiliseSurCetteVente + ' ' + window.DEVISE_ABREVIATION"></span>) — aucune dette ne sera créée.</span>
                                </template>
                            </div>
                            <div class="caisse__monnaie" x-show="monnaieARendre > 0" x-cloak>
                                <i class="bi bi-cash-coin me-2"></i>Monnaie à rendre : <strong x-text="montant(monnaieARendre)"></strong>
                            </div>
                            <div class="mt-1 small text-danger" x-show="aUnPaiementSansMoyen" x-cloak>
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>Choisissez un moyen de paiement pour chaque montant saisi.
                            </div>
                        </div>
                    </div>

                    <div class="text-danger small mb-2" x-show="aUneLigneNonChoisie" x-cloak>
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Choisissez une unité pour chaque ligne du panier.
                    </div>
                    <div class="text-danger small mb-2" x-show="aUneSourceNonChoisie" x-cloak>
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Choisissez le lieu de prélèvement pour chaque ligne du panier.
                    </div>
                    <div class="text-danger small mb-2" x-show="aUneLigneEnRupture" x-cloak>
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Stock insuffisant pour au moins une ligne au lieu choisi : corrigez avant d'enregistrer.
                    </div>
                    <div class="text-danger small mb-2" x-show="aUnDoublon" x-cloak>
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Un même produit ne peut pas apparaître deux fois avec la même unité et la même source : corrigez avant d'enregistrer.
                    </div>

                    <div class="d-flex gap-2">
                        <form id="formVente" method="POST"
                              action="{{ $devisTransformation ? route('devis.transformer', [$session, $devisTransformation]) : ($venteEnAttente ? route('ventes-en-attente.reprendre', $venteEnAttente) : route('ventes.store', $session)) }}"
                              class="flex-grow-1">
                            @csrf
                            <input type="hidden" name="type" :value="type">
                            <template x-for="(ligne, index) in panier" :key="'v'+index">
                                <span>
                                    <input type="hidden" :name="'lignes['+index+'][produit_id]'" :value="ligne.produit_id">
                                    <input type="hidden" :name="'lignes['+index+'][unite_vente_id]'" :value="ligne.unite_vente_id">
                                    <input type="hidden" :name="'lignes['+index+'][taxe_id]'" :value="ligne.taxe_id">
                                    <input type="hidden" :name="'lignes['+index+'][magasin_source_id]'" :value="ligne.magasin_source_id">
                                    <input type="hidden" :name="'lignes['+index+'][quantite]'" :value="ligne.quantite">
                                    <input type="hidden" :name="'lignes['+index+'][remise_type]'" :value="ligne.prixPersonnalise ? 'montant' : ligne.remise_type">
                                    <input type="hidden" :name="'lignes['+index+'][remise_valeur]'" :value="remiseValeurEffective(ligne)">
                                    <input type="hidden" :name="'lignes['+index+'][prix_personnalise]'" :value="ligne.prixPersonnalise ? 1 : 0">
                                </span>
                            </template>
                            <input type="hidden" name="remise_totale_type" :value="remiseTotaleType">
                            <input type="hidden" name="remise_totale_valeur" :value="remiseTotaleValeur">
                            <template x-for="(paiement, index) in paiementsAppliques" :key="'p'+index">
                                <span>
                                    <input type="hidden" :name="'paiements['+index+'][moyen_paiement_id]'" :value="paiement.moyen_paiement_id">
                                    <input type="hidden" :name="'paiements['+index+'][montant]'" :value="paiement.montant">
                                </span>
                            </template>
                            <input type="hidden" name="montant_recu" :value="totalPaiements">
                            <input type="hidden" name="client_id" :value="clientId">
                            @php
                                // Le crédit reste soumis à vente.credit même en mode devis, où
                                // clientId est toujours renseigné (imposé par le devis) : on ne
                                // peut donc pas se fier à "!clientId" pour bloquer, comme en mode
                                // normal — on substitue un booléen de permission littéral figé au
                                // rendu (non réactif, mais la permission ne change pas en cours de
                                // page).
                                $gardeCredit = $devisTransformation ? \Illuminate\Support\Js::from(auth()->user()->can('vente.credit')) : 'clientId';
                            @endphp
                            <button type="button" class="btn btn-primary btn-lg w-100"
                                    :disabled="panier.length === 0 || (totalPaiements < totalNet && !({{ $gardeCredit }})) || aUnDoublon || aUneLigneNonChoisie || aUneSourceNonChoisie || aUneLigneEnRupture || aUnPaiementSansMoyen"
                                    @click="declencherFinalisation($event)"
                                    data-form-id="formVente"
                                    :data-message="messageConfirmationVente"
                                    data-button-label="Finaliser la vente" data-button-class="btn-primary">
                                <i class="bi bi-check-circle me-1"></i>{{ $devisTransformation ? 'Transformer en vente' : 'Finaliser la vente' }}
                            </button>
                        </form>
                    </div>

                    @unless ($devisTransformation)
                    @can('ventenattente.gerer')
                        <div class="mt-2">
                            <form id="formAttente" method="POST"
                                  action="{{ $venteEnAttente ? route('ventes-en-attente.update', $venteEnAttente) : route('ventes-en-attente.store', $session) }}">
                                @csrf
                                @if ($venteEnAttente) @method('PUT') @endif
                                <template x-for="(ligne, index) in panier" :key="'a'+index">
                                    <span>
                                        <input type="hidden" :name="'lignes['+index+'][produit_id]'" :value="ligne.produit_id">
                                        <input type="hidden" :name="'lignes['+index+'][unite_vente_id]'" :value="ligne.unite_vente_id">
                                        <input type="hidden" :name="'lignes['+index+'][magasin_source_id]'" :value="ligne.magasin_source_id">
                                        <input type="hidden" :name="'lignes['+index+'][quantite]'" :value="ligne.quantite">
                                    </span>
                                </template>
                                <input type="hidden" name="client_id" :value="clientId">
                                <input type="text" name="libelle" x-model="libelleAttente" class="form-control form-control-sm mb-2" placeholder="Note (optionnel, ex. « Client au téléphone »)">
                                <button type="button" class="btn btn-outline-secondary w-100"
                                        :disabled="panier.length === 0 || aUnDoublon || aUneLigneNonChoisie"
                                        @click="declencherMiseEnAttente($event)"
                                        data-form-id="formAttente"
                                        data-message="Mettre ce panier en attente ? Vous pourrez le reprendre plus tard depuis « Ventes en attente »."
                                        data-button-label="Mettre en attente" data-button-class="btn-outline-secondary">
                                    <i class="bi bi-hourglass-split me-1"></i>Mettre en attente
                                </button>
                            </form>
                        </div>
                    @endcan
                    @endunless
                </div>
            </aside>
        </div>

        {{-- Téléphone et tablette : le panier est sous les produits. Ce rappel
             fixé en bas d'écran dit où il en est et y conduit. --}}
        <a href="#panier" class="caisse__rappel" x-show="panier.length > 0" x-cloak>
            <span><i class="bi bi-cart3 me-2"></i><span x-text="panier.length + (panier.length > 1 ? ' lignes' : ' ligne')"></span></span>
            <strong x-text="montant(totalNet)"></strong>
            <span class="caisse__rappel-lien">Voir le panier<i class="bi bi-arrow-down ms-1"></i></span>
        </a>
    </div>

    @can('client.gerer')
        @include('partials.client-rapide-modal')
    @endcan
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (document.getElementById('client-picker')) {
                window.initSelect2('#client-picker', { placeholder: '— Vente comptant (aucun client) —', allowClear: true });
            }
        });
    </script>
@endpush
