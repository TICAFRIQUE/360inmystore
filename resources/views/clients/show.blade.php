@extends('layouts.app')

@section('title', $client->nom)

@section('content')
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="d-flex align-items-center gap-2">
                <x-bouton-retour :route="route('clients.index')" />
                <h2 class="h4 mb-0">{{ $client->nom }} <code class="fs-6 text-secondary">{{ $client->code }}</code></h2>
            </div>
            <p class="text-secondary small mb-0 ms-5 ps-1">
                {{ telephone($client->telephone) ?: 'Aucun téléphone' }}
                @if ($client->adresse) — {{ $client->adresse }} @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            @can('client.reglement')
                @if ($solde > 0 && $sessionOuverte)
                    <button type="button" class="btn btn-reglement" data-bs-toggle="modal" data-bs-target="#reglerClientModal">
                        <i class="bi bi-cash-coin me-1"></i>Régler
                    </button>
                @elseif ($solde > 0)
                    <a href="{{ route('sessions.index') }}" class="btn btn-outline-secondary" title="Ouvrez une session de caisse pour régler ce client">
                        <i class="bi bi-cash-coin me-1"></i>Ouvrir une caisse pour régler
                    </a>
                @endif
                @if ($solde < 0)
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#rembourserAvoirModal">
                        <i class="bi bi-arrow-return-left me-1"></i>Rembourser l'avoir
                    </button>
                @endif
            @endcan
            @can('client.gerer')
                <a href="{{ route('clients.edit', $client) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-pencil me-1"></i>Modifier
                </a>
            @endcan
        </div>
    </div>

    @php
        $soldeDu = max($solde, 0);
        $avoir = max(-$solde, 0);
    @endphp

    {{-- Ordre logique : activité (CA, nombre de ventes) → ce qui a déjà été
         réglé → ce qui reste (dû, puis avoir — les deux gardés côte à côte,
         issus de la dissociation du même solde) → politique de crédit. --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4 col-lg-2">
            <x-kpi-card label="Total ventes net" icon="bi-graph-up-arrow" color="info"
                :value="montant($totalVentes)" />
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <x-kpi-card label="Ventes" icon="bi-receipt" color="primary"
                :value="$client->ventes_count" />
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
        <div class="col-6 col-md-4 col-lg-2">
            <x-kpi-card label="Limite de crédit" icon="bi-shield-check" color="secondary"
                :value="$client->limite_credit !== null ? montant($client->limite_credit) : 'Illimitée'" />
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center">
            <h3 class="h6 mb-0">Ventes</h3>
            <x-export-buttons :pdf-route="route('clients.ventes.pdf', $client)" :excel-route="route('clients.ventes.excel', $client)" />
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Numéro</th>
                        <th>Date</th>
                        <th>Magasin</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Réglé</th>
                        <th class="text-end">Reste dû</th>
                        <th>Statut</th>
                        <th>Livraison</th>
                        <th>Retourné</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ventes as $vente)
                        @php
                            $venduPieces = $vente->lignes->sum('quantite_pieces');
                            $retournePieces = $vente->retours->flatMap->lignes->sum('quantite_pieces');
                        @endphp
                        <tr>
                            <td>
                                <code>{{ $vente->numero }}</code>
                                @if ($vente->client_id !== $client->id)
                                    <span class="badge text-bg-info-subtle text-info-emphasis ms-1"
                                          title="Vente comptant ({{ $vente->client->nom ?? 'sans client' }}) — un retour dessus a été crédité à {{ $client->nom }}, ce n'est pas un achat de ce client.">
                                        Retour attribué
                                    </span>
                                @endif
                            </td>
                            <td>{{ $vente->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $vente->magasin->nom }}</td>
                            <td class="text-end">{{ montant($vente->total_net) }}</td>
                            @if (! $vente->trashed())
                                <td class="text-end text-success">{{ montant($vente->montantRegle()) }}</td>
                                <td class="text-end {{ $vente->soldeDuReel() > 0 ? 'text-danger fw-medium' : 'text-secondary' }}">{{ montant($vente->soldeDuReel()) }}</td>
                            @else
                                <td class="text-end text-secondary">—</td>
                                <td class="text-end text-secondary">—</td>
                            @endif
                            <td>
                                @if ($vente->trashed())
                                    <span class="badge text-bg-danger">Annulée</span>
                                @else
                                    <span class="badge text-bg-success">Validée</span>
                                @endif
                            </td>
                            <td>
                                @if ($vente->livraisonEngagee())
                                    <span class="badge {{ $vente->entierementLivree() ? 'text-bg-success-subtle text-success-emphasis' : 'text-bg-warning-subtle text-warning-emphasis' }}">
                                        {{ quantite($vente->quantiteLivreePieces()) }}/{{ quantite($vente->lignes->sum('quantite_pieces')) }} pièce(s)
                                    </span>
                                @else
                                    <span class="text-secondary">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($retournePieces <= 0)
                                    <span class="text-secondary">—</span>
                                @else
                                    <span class="badge {{ $retournePieces >= $venduPieces ? 'text-bg-danger-subtle text-danger-emphasis' : 'text-bg-warning-subtle text-warning-emphasis' }}">
                                        {{ quantite($retournePieces) }}/{{ quantite($venduPieces) }} pièce(s)
                                    </span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('ventes.ticket', $vente) }}" class="btn btn-sm btn-icon btn-outline-secondary" title="Détail de la vente">
                                    <i class="bi bi-eye"></i>
                                    <span class="visually-hidden">Détail</span>
                                </a>
                                @can('client.reglement')
                                    @if (! $vente->trashed() && $vente->soldeDuReel() > 0 && $sessionOuverte)
                                        <button type="button" class="btn btn-sm btn-icon btn-outline-reglement" title="Régler cette dette"
                                                data-bs-toggle="modal" data-bs-target="#reglerClientModal"
                                                data-montant="{{ min($vente->soldeDuReel(), $solde) }}"
                                                data-vente="{{ $vente->id }}"
                                                data-numero="{{ $vente->numero }}">
                                            <i class="bi bi-cash-coin"></i>
                                            <span class="visually-hidden">Régler</span>
                                        </button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-secondary py-4">Aucune vente pour ce client.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($ventes->hasPages())
            <div class="card-body">
                {{ $ventes->onEachSide(1)->links() }}
            </div>
        @endif
    </div>

    <div class="card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center">
            <h3 class="h6 mb-0">Devis</h3>
            <x-export-buttons :pdf-route="route('clients.devis.pdf', $client)" :excel-route="route('clients.devis.excel', $client)" />
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Numéro</th>
                        <th>Date</th>
                        <th>Statut</th>
                        <th>Valide jusqu'au</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($devis as $unDevis)
                        <tr>
                            <td><code>{{ $unDevis->numero }}</code></td>
                            <td>{{ $unDevis->created_at->format('d/m/Y H:i') }}</td>
                            <td><span class="badge {{ $unDevis->statutEffectif()->classeBadge() }}">{{ $unDevis->statutEffectif()->libelle() }}</span></td>
                            <td>{{ $unDevis->date_validite->format('d/m/Y') }}</td>
                            <td class="text-end">
                                <a href="{{ route('devis.show', $unDevis) }}" class="btn btn-sm btn-icon btn-outline-secondary" title="Détail du devis">
                                    <i class="bi bi-eye"></i>
                                    <span class="visually-hidden">Détail</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">Aucun devis pour ce client.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($devis->hasPages())
            <div class="card-body">
                {{ $devis->onEachSide(1)->links() }}
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
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ecritures as $ecriture)
                        @php
                            $venteLiee = match (true) {
                                $ecriture->reference instanceof \App\Models\Vente => $ecriture->reference,
                                $ecriture->reference instanceof \App\Models\ReglementClient => $ecriture->reference->vente,
                                $ecriture->reference instanceof \App\Models\RetourVente => $ecriture->reference->vente,
                                default => null,
                            };
                        @endphp
                        <tr>
                            <td>{{ $ecriture->created_at->format('d/m/Y H:i') }}</td>
                            <td>
                                <span class="badge {{ $ecriture->type->classeBadge() }}">{{ $ecriture->type->libelle() }}</span>
                                @if ($venteLiee && $ecriture->type === \App\Enums\EcritureCompteClientType::VenteCredit && $venteLiee->avoir_applique > 0)
                                    <div class="small text-success mt-1">
                                        <i class="bi bi-piggy-bank me-1"></i>
                                        @if ($venteLiee->soldeDuReel() > 0)
                                            {{ montant($venteLiee->avoir_applique) }} couverts par avoir
                                        @else
                                            Couverte par avoir — aucune dette restante
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if ($venteLiee)
                                    <a href="{{ route('ventes.ticket', $venteLiee) }}">{{ $venteLiee->numero }}</a>
                                @elseif ($ecriture->reference instanceof \App\Models\ReglementClient)
                                    <span class="text-secondary fst-italic">Règlement général</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $ecriture->auteur?->name ?? 'Utilisateur supprimé' }}</td>
                            <td class="text-end fw-medium {{ $ecriture->montant > 0 ? 'text-danger' : 'text-success' }}">
                                {{ $ecriture->montant > 0 ? '+' : '' }}{{ montant($ecriture->montant) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">Aucun mouvement sur ce compte.</td>
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

    @can('client.reglement')
        @if ($solde > 0 && $sessionOuverte)
            <div class="modal fade" id="reglerClientModal" tabindex="-1" aria-hidden="true"
                 x-data="{
                     paiements: [{ moyen_paiement_id: '', montant: null }],
                     venteId: null,
                     venteNumero: null,
                     detteAffichee: {{ $solde }},
                     get totalPaiements() { return this.paiements.reduce((total, p) => total + (Number(p.montant) || 0), 0); },
                     ajouterPaiement() { this.paiements.push({ moyen_paiement_id: '', montant: null }); },
                     retirerPaiement(index) { if (this.paiements.length > 1) this.paiements.splice(index, 1); },
                 }"
                 x-init="$el.addEventListener('show.bs.modal', (event) => {
                     venteId = event.relatedTarget?.dataset.vente || null;
                     venteNumero = event.relatedTarget?.dataset.numero || null;
                     const montant = event.relatedTarget?.dataset.montant ? Number(event.relatedTarget.dataset.montant) : null;
                     paiements = [{ moyen_paiement_id: '', montant: montant }];
                     detteAffichee = venteId ? montant : {{ $solde }};
                 })">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="{{ route('reglements.store', $sessionOuverte) }}">
                            @csrf
                            <input type="hidden" name="client_id" value="{{ $client->id }}">
                            <input type="hidden" name="vente_id" :value="venteId">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="bi bi-cash-coin text-reglement me-2"></i>Régler {{ $client->nom }}
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                            </div>
                            <div class="modal-body">
                                <p class="small text-secondary">
                                    <template x-if="venteNumero">
                                        <span>Reste dû sur la facture <strong x-text="venteNumero"></strong> : <strong x-text="detteAffichee.toLocaleString('fr-FR') + ' ' + window.DEVISE_ABREVIATION"></strong></span>
                                    </template>
                                    <template x-if="!venteNumero">
                                        <span>Dette totale du client : <strong>{{ montant($solde) }}</strong></span>
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
                                            <input type="number" :name="'paiements['+index+'][montant]'" x-model.number="paiement.montant" min="1" class="form-control form-control-sm" placeholder="Montant" required>
                                        </div>
                                        <div class="col-2">
                                            <button type="button" class="btn btn-sm btn-icon btn-outline-danger" @click="retirerPaiement(index)" x-show="paiements.length > 1">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                                <button type="button" class="btn btn-sm btn-outline-reglement" @click="ajouterPaiement()">
                                    <i class="bi bi-plus-lg"></i> Ajouter un moyen de paiement
                                </button>

                                <div class="mt-3 small" :class="totalPaiements > detteAffichee ? 'text-danger' : 'text-secondary'">
                                    Total réglé : <span x-text="totalPaiements"></span> F
                                    <span x-show="totalPaiements > detteAffichee">— dépasse le montant dû.</span>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Annuler</button>
                                <button type="submit" class="btn btn-reglement" :disabled="totalPaiements <= 0">
                                    <i class="bi bi-check-circle me-1"></i>Enregistrer le règlement
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    @endcan

    @can('client.reglement')
        @if ($solde < 0)
            <div class="modal fade" id="rembourserAvoirModal" tabindex="-1" aria-hidden="true"
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
                        <form method="POST" action="{{ route('remboursements-avoir-client.store', $client) }}">
                            @csrf
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="bi bi-arrow-return-left text-primary me-2"></i>Rembourser {{ $client->nom }}
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                            </div>
                            <div class="modal-body">
                                <p class="small text-secondary">
                                    Avoir disponible : <strong>{{ montant(-$solde) }}</strong>
                                    — l'argent remis au client, jamais plus que cet avoir.
                                </p>

                                <label class="form-label">Remboursement</label>
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
                                    <i class="bi bi-safe me-1"></i>Remboursement en espèces : sort de la Caisse Générale.
                                </div>

                                <div class="mt-3 small" :class="totalPaiements > avoirDisponible ? 'text-danger' : 'text-secondary'">
                                    Total remboursé : <span x-text="totalPaiements"></span> F
                                    <span x-show="totalPaiements > avoirDisponible">— dépasse l'avoir disponible.</span>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Annuler</button>
                                <button type="submit" class="btn btn-primary" :disabled="totalPaiements <= 0 || totalPaiements > avoirDisponible">
                                    <i class="bi bi-check-circle me-1"></i>Enregistrer le remboursement
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    @endcan
@endsection
