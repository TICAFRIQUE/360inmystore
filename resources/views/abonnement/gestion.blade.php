@extends('layouts.app')

@section('title', 'Gestion abonnement')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Gestion abonnement</h2>
        <a href="{{ route('abonnement.mon') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Mon abonnement
        </a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Formule actuelle</div>
                    <div class="fs-5 fw-semibold">
                        {{ $derniere?->formule?->nom ?? ($derniere ? 'Montant libre' : 'Aucun abonnement configuré') }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Jours restants</div>
                    <div class="fs-5 fw-semibold">
                        @if ($derniere?->illimite)
                            <span class="badge text-bg-success">Illimité</span>
                        @elseif ($joursRestants === null)
                            —
                        @else
                            {{ $joursRestants }} jour{{ $joursRestants > 1 ? 's' : '' }}
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- ============== Activer / changer d'offre ============== --}}
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Activer l'abonnement du client</div>
                <div class="card-body" x-data="{
                    formuleId: '',
                    formules: {{ $formules->where('actif', true)->values()->toJson() }},
                    get formule() { return this.formules.find(f => f.id == this.formuleId) ?? null; },
                }">
                    <form method="POST" action="{{ route('abonnement.activer') }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">Formule</label>
                            <select name="formule_id" class="form-select" x-model="formuleId"
                                @change="if (formule) { $refs.jours.value = formule.jours ?? ''; $refs.montant.value = formule.prix; $refs.illimite.checked = !!formule.illimite; }">
                                <option value="">— Montant/durée libre —</option>
                                @foreach ($formules->where('actif', true) as $formule)
                                    <option value="{{ $formule->id }}">
                                        {{ $formule->nom }}
                                        ({{ $formule->illimite ? 'illimité' : $formule->jours.' j' }} —
                                        {{ montant($formule->prix) }})
                                    </option>
                                @endforeach
                            </select>
                            {{-- La formule choisie fixe déjà la durée (jours ou illimité) — la case
                                 et le champ ci-dessous ne servent qu'au cas "montant/durée libre". --}}
                            <p class="form-text mb-0" x-show="formule" x-cloak>
                                <span x-text="formule?.illimite ? 'Illimité' : (formule?.jours + ' jours')"></span>
                            </p>
                        </div>
                        <div class="form-check mb-3" x-show="!formuleId" x-cloak>
                            <input type="checkbox" name="illimite" value="1" class="form-check-input" id="illimite" x-ref="illimite">
                            <label class="form-check-label" for="illimite">Illimité</label>
                        </div>
                        <div class="mb-3" x-show="!formuleId" x-cloak>
                            <label class="form-label">Jours</label>
                            <input type="number" name="jours" class="form-control" min="1" x-ref="jours">
                        </div>
                        @if ($joursRestants)
                            <div class="form-check mb-3">
                                <input type="checkbox" name="remplacer" value="1" class="form-check-input" id="remplacer">
                                <label class="form-check-label" for="remplacer">
                                    Remplacer la durée actuelle (ignorer les {{ $joursRestants }}
                                    jour{{ $joursRestants > 1 ? 's' : '' }} restant{{ $joursRestants > 1 ? 's' : '' }})
                                </label>
                                <div class="form-text">
                                    À cocher pour un vrai changement d'offre (ex. repasser en Essai) —
                                    sinon les jours restants s'ajoutent à la nouvelle durée (renouvellement).
                                </div>
                            </div>
                        @endif
                        <div class="mb-3">
                            <label class="form-label">Montant payé ({{ App\Models\Devise::abreviationActuelle() }})</label>
                            <input type="number" name="montant" class="form-control" min="0" required x-ref="montant">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Note (optionnel)</label>
                            <input type="text" name="note" class="form-control" maxlength="255">
                        </div>
                        <button type="submit" class="btn btn-primary">Activer</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- ============== Coordonnées de contact ============== --}}
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Coordonnées de contact (page de blocage)</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('abonnement.configuration.update') }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">Téléphone</label>
                            <x-champ-telephone :valeur="old('telephone', $configuration->telephone)" id="configuration-telephone" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label">WhatsApp</label>
                            <x-champ-telephone nom="whatsapp" :valeur="old('whatsapp', $configuration->whatsapp)" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Message personnalisé (optionnel)</label>
                            <textarea name="message" class="form-control" rows="3" maxlength="1000">{{ $configuration->message }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-outline-secondary">Enregistrer</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- ============== Formules ============== --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header">Formules</div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Durée</th>
                                <th>Prix</th>
                                <th>Statut</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($formules as $formule)
                                <tr>
                                    <td>{{ $formule->nom }}</td>
                                    <td>{{ $formule->illimite ? 'Illimité' : $formule->jours.' jours' }}</td>
                                    <td>{{ montant($formule->prix) }}</td>
                                    <td>
                                        @if ($formule->actif)
                                            <span class="badge text-bg-success">Active</span>
                                        @else
                                            <span class="badge text-bg-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal" data-bs-target="#modifierFormuleModal"
                                            data-formule-id="{{ $formule->id }}"
                                            data-nom="{{ $formule->nom }}"
                                            data-jours="{{ $formule->jours }}"
                                            data-illimite="{{ $formule->illimite ? '1' : '0' }}"
                                            data-prix="{{ $formule->prix }}">
                                            Modifier
                                        </button>
                                        <form method="POST" action="{{ route('abonnement.formules.basculer', $formule) }}" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                {{ $formule->actif ? 'Désactiver' : 'Activer' }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                    <form method="POST" action="{{ route('abonnement.formules.store') }}" class="row g-2 align-items-end"
                        x-data="{ illimite: false }">
                        @csrf
                        <div class="col-sm-4">
                            <label class="form-label small mb-1">Nom</label>
                            <input type="text" name="nom" class="form-control form-control-sm" required maxlength="255" placeholder="ex. 30 jours">
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small mb-1">Jours</label>
                            <input type="number" name="jours" class="form-control form-control-sm" min="1" :disabled="illimite">
                        </div>
                        <div class="col-sm-2">
                            <div class="form-check mt-4">
                                <input type="checkbox" name="illimite" value="1" class="form-check-input" id="nouvelleIllimitee" x-model="illimite">
                                <label class="form-check-label small" for="nouvelleIllimitee">Illimité</label>
                            </div>
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small mb-1">Prix ({{ App\Models\Devise::abreviationActuelle() }})</label>
                            <input type="number" name="prix" class="form-control form-control-sm" min="0" required>
                        </div>
                        <div class="col-sm-2">
                            <button type="submit" class="btn btn-sm btn-primary w-100">Ajouter</button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Modale partagée, une seule pour toutes les lignes du tableau —
                 même patron que reglerClientModal (clients/show.blade.php) :
                 les data-* du bouton "Modifier" cliqué alimentent l'état Alpine
                 via l'évènement natif show.bs.modal, pas un modal par ligne. --}}
            <div class="modal fade" id="modifierFormuleModal" tabindex="-1" aria-hidden="true"
                 x-data="{ formuleId: null, nom: '', jours: null, illimite: false, prix: null }"
                 x-init="$el.addEventListener('show.bs.modal', (event) => {
                     formuleId = event.relatedTarget?.dataset.formuleId || null;
                     nom = event.relatedTarget?.dataset.nom || '';
                     jours = event.relatedTarget?.dataset.jours || null;
                     illimite = event.relatedTarget?.dataset.illimite === '1';
                     prix = event.relatedTarget?.dataset.prix || null;
                 })">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" :action="`{{ url('abonnement/formules') }}/${formuleId}`">
                            @csrf
                            @method('PUT')
                            <div class="modal-header">
                                <h5 class="modal-title">Modifier la formule</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Nom</label>
                                    <input type="text" name="nom" class="form-control" required maxlength="255" x-model="nom">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Jours</label>
                                    <input type="number" name="jours" class="form-control" min="1" :disabled="illimite" x-model="jours">
                                </div>
                                <div class="form-check mb-3">
                                    <input type="checkbox" name="illimite" value="1" class="form-check-input" id="modifierFormuleIllimite" x-model="illimite">
                                    <label class="form-check-label" for="modifierFormuleIllimite">Illimité</label>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label">Prix ({{ App\Models\Devise::abreviationActuelle() }})</label>
                                    <input type="number" name="prix" class="form-control" min="0" required x-model="prix">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                                <button type="submit" class="btn btn-primary">Enregistrer</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============== Historique ============== --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header">Historique complet</div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Formule</th>
                                <th>Jours</th>
                                <th>Montant</th>
                                <th>Fin</th>
                                <th>Auteur</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($historique as $activation)
                                <tr>
                                    <td>{{ $activation->created_at->format('d/m/Y H:i') }}</td>
                                    <td>{{ $activation->formule?->nom ?? 'Montant libre' }}</td>
                                    <td>
                                        @if ($activation->illimite)
                                            Illimité
                                        @else
                                            {{ $activation->jours }}
                                            @if ($activation->jours_restants_reportes > 0)
                                                <span class="text-muted small">(+{{ $activation->jours_restants_reportes }} reportés)</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td>{{ montant($activation->montant) }}</td>
                                    <td>{{ $activation->date_fin?->format('d/m/Y') ?? 'Illimité' }}</td>
                                    <td>{{ $activation->auteur?->name }}</td>
                                    <td class="text-muted small">{{ $activation->note }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">Aucune activation enregistrée.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($historique->hasPages())
                    <div class="card-footer">
                        {{ $historique->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
