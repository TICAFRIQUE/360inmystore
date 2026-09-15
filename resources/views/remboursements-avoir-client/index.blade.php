@extends('layouts.app')

@section('title', 'Avoirs clients')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Avoirs clients</h2>
    </div>

    <p class="text-secondary small mb-3">
        Tous les clients actifs ayant un avoir disponible (suite à un ou plusieurs
        retours) — remboursable directement depuis cette liste, sans passer par la
        fiche de chaque client.
    </p>

    @unless ($sessionOuverte)
        <div class="alert alert-warning d-flex align-items-center justify-content-between">
            <span><i class="bi bi-exclamation-triangle me-1"></i>Aucune session de caisse ouverte : le remboursement sort du tiroir de votre session, ouvrez-en une pour continuer.</span>
            <a href="{{ route('sessions.index') }}" class="btn btn-sm btn-outline-secondary flex-shrink-0 ms-2">Ouvrir une caisse</a>
        </div>
    @endunless

    <div class="card"
         x-data="{
            actionModele: '{{ route('remboursements-avoir-client.store', ['client' => '__ID__']) }}',
            clientId: null,
            clientNom: '',
            avoirDisponible: 0,
            paiements: [{ moyen_paiement_id: '', montant: null }],
            especeIds: @json($moyensPaiement->where('est_espece', true)->pluck('id')->values()),
            get totalPaiements() { return this.paiements.reduce((total, p) => total + (Number(p.montant) || 0), 0); },
            get contientEspeces() { return this.paiements.some(p => this.especeIds.includes(Number(p.moyen_paiement_id))); },
            get formAction() { return this.actionModele.replace('__ID__', this.clientId); },
            ajouterPaiement() { this.paiements.push({ moyen_paiement_id: '', montant: null }); },
            retirerPaiement(index) { if (this.paiements.length > 1) this.paiements.splice(index, 1); },
            ouvrir(id, nom, avoir) {
                this.clientId = id;
                this.clientNom = nom;
                this.avoirDisponible = avoir;
                this.paiements = [{ moyen_paiement_id: '', montant: avoir }];
            },
         }">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Code</th>
                        <th class="text-end">Avoir disponible</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($clients as $client)
                        <tr>
                            <td>{{ $client->nom }}</td>
                            <td><code>{{ $client->code }}</code></td>
                            <td class="text-end text-info fw-medium">{{ montant(-$client->solde) }}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#rembourserAvoirModal"
                                        @click="ouvrir({{ $client->id }}, @js($client->nom), {{ -$client->solde }})"
                                        @disabled(! $sessionOuverte) title="{{ $sessionOuverte ? '' : 'Ouvrez une session de caisse pour rembourser' }}">
                                    <i class="bi bi-arrow-return-left me-1"></i>Rembourser
                                </button>
                                <a href="{{ route('clients.show', $client) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-secondary py-4">Aucun client n'a d'avoir disponible pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="modal fade" id="rembourserAvoirModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" :action="formAction">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-arrow-return-left text-primary me-2"></i>Rembourser <span x-text="clientNom"></span>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-secondary">
                                Avoir disponible : <strong x-text="avoirDisponible + ' F'"></strong>
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
    </div>
@endsection
