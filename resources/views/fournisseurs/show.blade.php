@extends('layouts.app')

@section('title', $fournisseur->nom)

@section('content')
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="d-flex align-items-center gap-2">
                <x-bouton-retour :route="route('fournisseurs.index')" />
                <h2 class="h4 mb-0">{{ $fournisseur->nom }} <code class="fs-6 text-secondary">{{ $fournisseur->code }}</code></h2>
            </div>
            <p class="text-secondary small mb-0 ms-5 ps-1">
                {{ telephone($fournisseur->telephone) ?: 'Aucun téléphone' }}
                @if ($fournisseur->email) — {{ $fournisseur->email }} @endif
                @if ($fournisseur->adresse) — {{ $fournisseur->adresse }} @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            @can('fournisseur.reglement')
                {{-- Règlement global (solde total) volontairement masqué pour
                     l'instant : on règle d'abord par bon de commande précis (bouton
                     dédié dans le tableau ci-dessous, voir resterFournisseurModal
                     en mode ciblé) — reglerIntegralite() reste fonctionnel côté
                     serveur, seule cette entrée est retirée de l'écran. --}}
                @if ($solde < 0)
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#rembourserAvoirFournisseurModal">
                        <i class="bi bi-arrow-return-left me-1"></i>Encaisser l'avoir
                    </button>
                @endif
            @endcan
            @can('fournisseur.gerer')
                <a href="{{ route('fournisseurs.edit', $fournisseur) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-pencil me-1"></i>Modifier
                </a>
            @endcan
        </div>
    </div>

    @php
        $soldeDu = max($solde, 0);
        $avoir = max(-$solde, 0);
    @endphp

    {{-- Ordre logique : activité (total achats, nombre de bons) → ce qui a
         déjà été réglé → ce qui reste (dû, puis avoir — les deux gardés côte
         à côte, issus de la dissociation du même solde). --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4 col-lg-2">
            <x-kpi-card label="Total achats net" icon="bi-graph-up-arrow" color="info"
                :value="montant($totalAchats)" />
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <x-kpi-card label="Bons de commande" icon="bi-truck" color="primary"
                :value="$fournisseur->commande_achats_count" />
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <x-kpi-card label="Total réglé" icon="bi-check2-circle" color="success"
                :value="montant($totalRegle)" />
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <x-kpi-card label="Solde dû" icon="bi-cash-stack" :color="$soldeDu > 0 ? 'danger' : 'secondary'"
                :value="montant($soldeDu)" />
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <x-kpi-card label="Avoir" icon="bi-piggy-bank" :color="$avoir > 0 ? 'success' : 'secondary'"
                :value="montant($avoir)" />
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h3 class="h6 mb-0">Bons de commande</h3>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <form method="GET" action="{{ route('fournisseurs.show', $fournisseur) }}" class="d-flex align-items-center gap-1">
                    <div class="form-check mb-0">
                        <input type="checkbox" name="reception_incomplete" value="1" id="reception-incomplete"
                               class="form-check-input" @checked($receptionIncomplete) onchange="this.form.submit()">
                        <label class="form-check-label small" for="reception-incomplete">Pas encore reçus à 100 %</label>
                    </div>
                </form>
                <x-export-buttons :pdf-route="route('fournisseurs.commandes.pdf', [$fournisseur] + request()->query())"
                    :excel-route="route('fournisseurs.commandes.excel', [$fournisseur] + request()->query())" />
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Numéro</th>
                        <th>Date</th>
                        <th>Destination(s)</th>
                        <th>Statut</th>
                        <th>Réception</th>
                        <th>Retours</th>
                        <th class="text-end">Montant facturé</th>
                        <th class="text-end">Réglé</th>
                        <th class="text-end">Reste dû</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($commandes as $commande)
                        <tr>
                            <td><code>{{ $commande->numero }}</code></td>
                            <td>{{ $commande->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $commande->lignes->pluck('magasinDestination.nom')->filter()->unique()->implode(', ') ?: '—' }}</td>
                            <td>
                                @if ($commande->trashed())
                                    <span class="badge text-bg-danger">Annulée</span>
                                @elseif ($commande->statut === 'validee')
                                    <span class="badge text-bg-success">Validée</span>
                                @else
                                    <span class="badge text-bg-secondary">Brouillon</span>
                                @endif
                            </td>
                            <td>
                                @if ($commande->statut === 'validee')
                                    <span class="small {{ $commande->tauxCompletion() >= 100 ? 'text-success' : ($commande->tauxCompletion() > 0 ? 'text-warning-emphasis' : 'text-secondary') }}">
                                        {{ quantite($commande->quantiteRecuePieces()) }}/{{ quantite($commande->quantiteCommandeePieces()) }} · {{ $commande->tauxCompletion() }} %
                                    </span>
                                @else
                                    <span class="text-secondary">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($commande->retours->isNotEmpty())
                                    <span class="badge text-bg-warning-subtle text-warning-emphasis">
                                        {{ $commande->retours->count() }} · {{ montant($commande->retours->sum('montant_total')) }}
                                    </span>
                                @else
                                    <span class="text-secondary">—</span>
                                @endif
                            </td>
                            <td class="text-end">{{ montant($commande->totalTtcReel()) }}</td>
                            @if ($commande->statut === 'validee')
                                <td class="text-end text-success">{{ montant($commande->montantRegle()) }}</td>
                                <td class="text-end {{ $commande->resteDu() > 0 ? 'text-danger fw-medium' : 'text-secondary' }}">{{ montant($commande->resteDu()) }}</td>
                            @else
                                <td class="text-end text-secondary">—</td>
                                <td class="text-end text-secondary">—</td>
                            @endif
                            <td class="text-end">
                                <a href="{{ route('commande-achats.show', $commande) }}" class="btn btn-sm btn-icon btn-outline-secondary" title="Détail du bon de commande">
                                    <i class="bi bi-eye"></i>
                                    <span class="visually-hidden">Détail</span>
                                </a>
                                @can('fournisseur.reglement')
                                    @if ($commande->statut === 'validee' && ! $commande->trashed() && $commande->resteDu() > 0)
                                        <button type="button" class="btn btn-sm btn-icon btn-outline-reglement" title="Régler cette dette"
                                                data-bs-toggle="modal" data-bs-target="#reglerFournisseurModal"
                                                data-montant="{{ $commande->resteDu() }}"
                                                data-commande="{{ $commande->id }}"
                                                data-numero="{{ $commande->numero }}">
                                            <i class="bi bi-cash-coin"></i>
                                            <span class="visually-hidden">Régler</span>
                                        </button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-secondary py-4">Aucune commande pour ce fournisseur.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($commandes->hasPages())
            <div class="card-body">
                {{ $commandes->onEachSide(1)->links() }}
            </div>
        @endif
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h3 class="h6">Historique du compte</h3>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Référence</th>
                        <th>Auteur</th>
                        <th class="text-end">Montant</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ecritures as $ecriture)
                        <tr class="{{ $ecriture->type->classeLigne() }}">
                            <td>{{ $ecriture->created_at->format('d/m/Y H:i') }}</td>
                            <td><span class="badge {{ $ecriture->type->classeBadge() }}">{{ $ecriture->type->libelle() }}</span></td>
                            @php
                                $commandeLiee = match (true) {
                                    $ecriture->reference instanceof \App\Models\CommandeAchat => $ecriture->reference,
                                    $ecriture->reference instanceof \App\Models\ReglementFournisseur => $ecriture->reference->commandeAchat,
                                    $ecriture->reference instanceof \App\Models\RetourAchat => $ecriture->reference->commandeAchat,
                                    default => null,
                                };
                            @endphp
                            <td>
                                @if ($commandeLiee)
                                    <a href="{{ route('commande-achats.show', $commandeLiee) }}">{{ $commandeLiee->numero }}</a>
                                @elseif ($ecriture->reference instanceof \App\Models\ReglementFournisseur)
                                    <span class="text-secondary fst-italic">Règlement général</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $ecriture->auteur?->name ?? 'Utilisateur supprimé' }}</td>
                            <td class="text-end fw-medium {{ $ecriture->montant > 0 ? 'text-danger' : 'text-success' }}">
                                {{ $ecriture->montant > 0 ? '+' : '' }}{{ montant($ecriture->montant) }}
                            </td>
                            <td class="text-end">
                                @if ($commandeLiee)
                                    <a href="{{ route('commande-achats.show', $commandeLiee) }}" class="btn btn-sm btn-icon btn-outline-secondary" title="Voir le détail du bon de commande">
                                        <i class="bi bi-eye"></i>
                                        <span class="visually-hidden">Détail</span>
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">Aucun mouvement sur ce compte.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($ecritures->hasPages())
            <div class="card-body">
                {{ $ecritures->onEachSide(1)->links() }}
            </div>
        @endif
    </div>

    @can('fournisseur.reglement')
        @if ($solde > 0)
            <div class="modal fade" id="reglerFournisseurModal" tabindex="-1" aria-hidden="true"
                 x-data="{
                     paiements: [{ moyen_paiement_id: '', montant: null }],
                     commandeAchatId: null,
                     commandeNumero: null,
                     detteAffichee: {{ $solde }},
                     especeIds: @json($moyensPaiement->where('est_espece', true)->pluck('id')->values()),
                     get totalPaiements() { return this.paiements.reduce((total, p) => total + (Number(p.montant) || 0), 0); },
                     get contientEspeces() { return this.paiements.some(p => this.especeIds.includes(Number(p.moyen_paiement_id))); },
                     // Un règlement ciblant une commande précise reste partiel
                     // (totalPaiements <= detteAffichee suffit) ; un règlement
                     // global (aucune commande ciblée) doit couvrir le solde à
                     // l'exact franc près — voir ReglementFournisseurService::
                     // reglerIntegralite(), qui répartit automatiquement sur
                     // chaque commande due et refuse tout écart.
                     get montantValide() { return this.commandeAchatId ? this.totalPaiements <= this.detteAffichee : this.totalPaiements === this.detteAffichee; },
                     ajouterPaiement() { this.paiements.push({ moyen_paiement_id: '', montant: null }); },
                     retirerPaiement(index) { if (this.paiements.length > 1) this.paiements.splice(index, 1); },
                     // Uniquement en mode ciblé (un bon de commande précis) :
                     // un règlement global doit, lui, pouvoir dépasser
                     // temporairement le solde pendant la saisie pour que le
                     // message d'erreur (!montantValide) reste visible tant
                     // que le compte n'est pas exact — voir montantValide.
                     // Volontairement PAS combiné à x-model sur le même champ :
                     // les deux écouteraient tous les deux l'évènement 'input'
                     // et, selon l'ordre d'application des directives Alpine,
                     // x-model peut réécrire la valeur clampée avec la valeur
                     // brute juste après — un seul gestionnaire (@input
                     // ci-dessous, qui pilote :value) élimine cette course.
                     // Le gestionnaire @input force AUSSI directement
                     // $event.target.value (pas seulement paiement.montant) :
                     // si la valeur tapée dépasse déjà le plafond dès le
                     // champ pré-rempli au maximum, clamperMontant() renvoie
                     // la MÊME valeur qu'avant — Alpine ne détecte alors
                     // aucun changement réactif et ne réécrit jamais le DOM,
                     // laissant affiché (et surtout SOUMIS, voir FormData)
                     // le chiffre brut tapé malgré un paiement.montant
                     // interne correctement plafonné. Un vrai bug vécu, pas
                     // une précaution théorique.
                     clamperMontant(index, valeurBrute) {
                         if (valeurBrute === '') return null;
                         const valeur = Number(valeurBrute) || 0;
                         if (!this.commandeAchatId) return valeur;
                         const autres = this.paiements.reduce((total, p, i) => i === index ? total : total + (Number(p.montant) || 0), 0);
                         const maxPourCetteLigne = Math.max(0, this.detteAffichee - autres);
                         return Math.min(valeur, maxPourCetteLigne);
                     },
                     onModalShow(event) {
                         this.commandeAchatId = event.relatedTarget?.dataset.commande || null;
                         this.commandeNumero = event.relatedTarget?.dataset.numero || null;
                         const montant = event.relatedTarget?.dataset.montant ? Number(event.relatedTarget.dataset.montant) : null;
                         // Réglement global : un seul paiement, pré-rempli au
                         // solde entier — pour un paiement mixte (plusieurs
                         // moyens), le bouton d'ajout de paiement reste
                         // possible, mais la somme doit toujours retomber
                         // exactement sur le solde (montantValide ci-dessus).
                         this.paiements = [{ moyen_paiement_id: '', montant: this.commandeAchatId ? montant : {{ $solde }} }];
                         this.detteAffichee = this.commandeAchatId ? montant : {{ $solde }};
                     },
                 }"
                 x-init="$el.addEventListener('show.bs.modal', (event) => onModalShow(event))">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="{{ route('reglements-fournisseur.store', $fournisseur) }}">
                            @csrf
                            <input type="hidden" name="commande_achat_id" :value="commandeAchatId">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="bi bi-cash-coin text-reglement me-2"></i>Régler {{ $fournisseur->nom }}
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                            </div>
                            <div class="modal-body">
                                <p class="small text-secondary">
                                    <template x-if="commandeNumero">
                                        <span>Reste dû sur le bon de commande <strong x-text="commandeNumero"></strong> : <strong x-text="detteAffichee.toLocaleString('fr-FR') + ' ' + window.DEVISE_ABREVIATION"></strong></span>
                                    </template>
                                    <template x-if="!commandeNumero">
                                        <span>
                                            Dette totale du fournisseur : <strong>{{ montant($solde) }}</strong> — ce règlement
                                            soldera l'intégralité de la dette, répartie automatiquement sur chaque bon de commande encore dû
                                            (le plus ancien d'abord). Pour un paiement partiel, réglez un bon de commande précis depuis la liste ci-dessous.
                                        </span>
                                    </template>
                                </p>

                                <label class="form-label">Paiement</label>
                                <template x-for="(paiement, index) in paiements" :key="index">
                                    <div class="row g-1 align-items-center mb-2">
                                        <div class="col-6">
                                            <select :name="'paiements['+index+'][moyen_paiement_id]'" x-model="paiement.moyen_paiement_id" class="form-select form-select-sm" required>
                                                <option value="">Moyen…</option>
                                                @foreach ($moyensPaiement as $moyen)
                                                    <option value="{{ $moyen->id }}">{{ $moyen->nom }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-4">
                                            <input type="number" :name="'paiements['+index+'][montant]'" :value="paiement.montant"
                                                   @input="paiement.montant = clamperMontant(index, $event.target.value); $event.target.value = paiement.montant ?? ''"
                                                   min="1" :max="commandeAchatId ? detteAffichee : null"
                                                   class="form-control form-control-sm" placeholder="Montant" required>
                                        </div>
                                        <div class="col-2">
                                            <button type="button" class="btn btn-sm btn-icon btn-outline-danger" @click="retirerPaiement(index)" x-show="paiements.length > 1">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                                <button type="button" class="btn btn-sm btn-outline-reglement" @click="ajouterPaiement()"
                                        :disabled="commandeAchatId && totalPaiements >= detteAffichee">
                                    <i class="bi bi-plus-lg"></i> Ajouter un moyen de paiement
                                </button>

                                <div class="mt-3 small text-secondary" x-show="contientEspeces" x-cloak>
                                    <i class="bi bi-safe me-1"></i>Paiement en espèces : sort de la Caisse Générale.
                                </div>

                                <div class="mt-3 small" :class="!montantValide ? 'text-danger' : 'text-secondary'">
                                    Total réglé : <span x-text="totalPaiements"></span> F
                                    <template x-if="commandeAchatId">
                                        <span x-show="totalPaiements > detteAffichee">— dépasse le montant dû.</span>
                                    </template>
                                    <template x-if="!commandeAchatId">
                                        <span x-show="!montantValide">— un règlement global doit couvrir exactement la dette totale ({{ montant($solde) }}).</span>
                                    </template>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Annuler</button>
                                <button type="submit" class="btn btn-reglement" :disabled="totalPaiements <= 0 || !montantValide">
                                    <i class="bi bi-check-circle me-1"></i>Enregistrer le règlement
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    @endcan

    @can('fournisseur.reglement')
        @if ($solde < 0)
            <div class="modal fade" id="rembourserAvoirFournisseurModal" tabindex="-1" aria-hidden="true"
                 x-data="{
                     paiements: [{ moyen_paiement_id: '', montant: {{ -$solde }} }],
                     avoirDisponible: {{ -$solde }},
                     especeIds: @json($moyensPaiement->where('est_espece', true)->pluck('id')->values()),
                     get totalPaiements() { return this.paiements.reduce((total, p) => total + (Number(p.montant) || 0), 0); },
                     get contientEspeces() { return this.paiements.some(p => this.especeIds.includes(Number(p.moyen_paiement_id))); },
                     ajouterPaiement() { this.paiements.push({ moyen_paiement_id: '', montant: null }); },
                     retirerPaiement(index) { if (this.paiements.length > 1) this.paiements.splice(index, 1); },
                 }">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="{{ route('remboursements-avoir-fournisseur.store', $fournisseur) }}">
                            @csrf
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="bi bi-arrow-return-left text-primary me-2"></i>Encaisser l'avoir de {{ $fournisseur->nom }}
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                            </div>
                            <div class="modal-body">
                                <p class="small text-secondary">
                                    Avoir disponible : <strong>{{ montant(-$solde) }}</strong>
                                    — l'argent que le fournisseur nous reverse, jamais plus que cet avoir.
                                </p>

                                <label class="form-label">Encaissement</label>
                                <template x-for="(paiement, index) in paiements" :key="index">
                                    <div class="row g-1 align-items-center mb-2">
                                        <div class="col-6">
                                            <select :name="'paiements['+index+'][moyen_paiement_id]'" x-model="paiement.moyen_paiement_id" class="form-select form-select-sm" required>
                                                <option value="">Moyen…</option>
                                                @foreach ($moyensPaiement as $moyen)
                                                    <option value="{{ $moyen->id }}">{{ $moyen->nom }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-4">
                                            <input type="number" :name="'paiements['+index+'][montant]'" x-model.number="paiement.montant" min="1" class="form-control form-control-sm" placeholder="Montant" required>
                                        </div>
                                        <div class="col-2">
                                            <button type="button" class="btn btn-sm btn-icon btn-outline-danger" @click="retirerPaiement(index)" x-show="paiements.length > 1">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                                <button type="button" class="btn btn-sm btn-outline-primary" @click="ajouterPaiement()">
                                    <i class="bi bi-plus-lg"></i> Ajouter un moyen de paiement
                                </button>

                                <div class="mt-3 small text-secondary" x-show="contientEspeces" x-cloak>
                                    <i class="bi bi-safe me-1"></i>Encaissement en espèces : entre dans la Caisse Générale.
                                </div>

                                <div class="mt-3 small" :class="totalPaiements > avoirDisponible ? 'text-danger' : 'text-secondary'">
                                    Total encaissé : <span x-text="totalPaiements"></span> F
                                    <span x-show="totalPaiements > avoirDisponible">— dépasse l'avoir disponible.</span>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Annuler</button>
                                <button type="submit" class="btn btn-primary" :disabled="totalPaiements <= 0 || totalPaiements > avoirDisponible">
                                    <i class="bi bi-check-circle me-1"></i>Enregistrer l'encaissement
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    @endcan
@endsection
