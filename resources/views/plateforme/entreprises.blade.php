@extends('layouts.app')

@section('title', 'Entreprises')

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h2 class="h4 mb-0">Entreprises</h2>
            <div class="text-secondary small">Chaque entreprise a son espace en <code>{adresse}.{{ $domaine }}</code></div>
        </div>
        @if (config('plateforme.inscription.ouverte'))
            <a href="{{ route('plateforme.inscription') }}" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">
                <i class="bi bi-box-arrow-up-right me-1"></i>Page d'inscription
            </a>
        @endif
    </div>

    <x-recherche-form :action="route('plateforme.entreprises.index')" placeholder="Nom, adresse, propriétaire ou téléphone…"
        :autres-params="['statut', 'abonnement']">
        <div>
            <label for="statut" class="form-label small mb-1">Statut</label>
            <select name="statut" id="statut" class="form-select" onchange="this.form.submit()">
                <option value="">Tous</option>
                <option value="active" @selected(request('statut') === 'active')>Active</option>
                <option value="suspendue" @selected(request('statut') === 'suspendue')>Suspendue</option>
            </select>
        </div>
        <div>
            <label for="abonnement" class="form-label small mb-1">Abonnement</label>
            <select name="abonnement" id="abonnement" class="form-select" onchange="this.form.submit()">
                <option value="">Tous</option>
                <option value="en_cours" @selected(request('abonnement') === 'en_cours')>En cours</option>
                <option value="sans_formule" @selected(request('abonnement') === 'sans_formule')>Formule non choisie</option>
                <option value="expire" @selected(request('abonnement') === 'expire')>Expiré</option>
            </select>
        </div>
        <x-slot:export>
            <x-export-buttons :pdf-route="route('plateforme.entreprises.pdf', request()->query())" :excel-route="route('plateforme.entreprises.excel', request()->query())" />
        </x-slot:export>
    </x-recherche-form>

    {{-- cible : entreprise visée par la fenêtre ouverte (suspension ou
         activation d'abonnement) — une seule paire de fenêtres pour toute la
         liste, dont l'adresse d'envoi est recalculée à l'ouverture. --}}
    <div x-data="{
            routes: {
                suspendre: '{{ route('plateforme.entreprises.suspendre', ['entreprise' => '__ID__']) }}',
                activer: '{{ route('plateforme.entreprises.activer', ['entreprise' => '__ID__']) }}',
            },
            cible: { id: null, nom: '' },
            formules: {{ $formules->map->only(['id', 'nom', 'jours', 'illimite', 'prix'])->values()->toJson() }},
            formuleId: '',
            get formule() { return this.formules.find(f => f.id == this.formuleId) ?? null; },
            action(nom) { return this.routes[nom].replace('__ID__', this.cible.id); },
            viser(id, nom) { this.cible = { id, nom }; this.formuleId = ''; },
         }">
        <div class="card mb-3">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <x-th-tri champ="nom" label="Entreprise" />
                            <th>Propriétaire</th>
                            <th>Pays</th>
                            <x-th-tri champ="statut" label="Statut" />
                            <x-th-tri champ="abonnement_fin" label="Abonnement" />
                            <x-th-tri champ="created_at" label="Créée le" />
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($entreprises as $entreprise)
                            <tr>
                                <td>
                                    <div class="fw-medium">{{ $entreprise->nom }}</div>
                                    <a href="{{ $entreprise->url() }}" target="_blank" rel="noopener" class="small">{{ $entreprise->sous_domaine }}.{{ $domaine }}</a>
                                </td>
                                <td>
                                    <div>{{ $entreprise->proprietaire_nom }}</div>
                                    <div class="small text-secondary">{{ telephone($entreprise->telephone) }}</div>
                                </td>
                                <td>{{ \App\Support\Telephone::PAYS[$entreprise->pays] ?? $entreprise->pays }}</td>
                                <td>
                                    @if ($entreprise->estActive())
                                        <span class="badge text-bg-success">Active</span>
                                    @else
                                        <span class="badge text-bg-danger">Suspendue</span>
                                        @if ($entreprise->motif_suspension)
                                            <div class="small text-secondary">{{ $entreprise->motif_suspension }}</div>
                                        @endif
                                    @endif
                                </td>
                                <td>
                                    @if ($entreprise->formule_nom)
                                        <div>{{ $entreprise->formule_nom }}</div>
                                    @endif
                                    <div class="small {{ $entreprise->abonnementExpire() ? 'text-danger' : ($entreprise->sansFormule() ? 'text-warning-emphasis' : 'text-secondary') }}">
                                        {{ $entreprise->libelleAbonnement() }}
                                    </div>
                                </td>
                                <td>{{ $entreprise->created_at->format('d/m/Y') }}</td>
                                <td class="text-end">
                                    @if ($entreprise->estActive())
                                        <div class="dropdown d-inline-block">
                                            {{-- strategy fixed : sans ça, le menu est rogné par le
                                                 conteneur défilant du tableau (.table-responsive). --}}
                                            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"
                                                    data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false">
                                                Actions
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#activerModal"
                                                            @click="viser({{ $entreprise->id }}, @js($entreprise->nom))">
                                                        <i class="bi bi-calendar-check me-2"></i>Activer un abonnement
                                                    </button>
                                                </li>
                                                <li>
                                                    {{-- data-sans-spinner : la réponse s'ouvre dans un nouvel
                                                         onglet, cette page-ci ne se recharge pas. --}}
                                                    <form method="POST" action="{{ route('plateforme.entreprises.ouvrir', $entreprise) }}" target="_blank" data-sans-spinner>
                                                        @csrf
                                                        <button type="submit" class="dropdown-item">
                                                            <i class="bi bi-box-arrow-in-right me-2"></i>Ouvrir l'espace (maintenance)
                                                        </button>
                                                    </form>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <button type="button" class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#suspendreModal"
                                                            @click="viser({{ $entreprise->id }}, @js($entreprise->nom))">
                                                        <i class="bi bi-lock me-2"></i>Suspendre
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    @else
                                        <x-confirm-button :action="route('plateforme.entreprises.reactiver', $entreprise)"
                                            message="Rouvrir l'espace de cette entreprise ? Ses utilisateurs pourront de nouveau se connecter."
                                            button-label="Réactiver" button-class="btn-sm btn-outline-success" icon="bi-unlock" />
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-secondary py-4">Aucune entreprise.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $entreprises->links() }}

        <div class="modal fade" id="suspendreModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" :action="action('suspendre')" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">Suspendre <span x-text="cible.nom"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-secondary small">
                            Plus aucun utilisateur de cette entreprise ne pourra se connecter. Ses données sont
                            conservées, et l'espace peut être rouvert à tout moment.
                        </p>
                        <label for="motif" class="form-label">Motif<span class="required-marker">*</span></label>
                        <input type="text" name="motif" id="motif" class="form-control" maxlength="255" required
                               placeholder="Ex. impayé, demande du client…">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-danger">Suspendre</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="activerModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" :action="action('activer')" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">Activer l'abonnement de <span x-text="cible.nom"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-secondary small">
                            Pour un abonnement réglé en direct (espèces, Mobile Money…) ou des jours offerts.
                            Le paiement est inscrit au registre et l'espace est prolongé aussitôt.
                        </p>
                        <div class="mb-3">
                            <label for="formule_id" class="form-label">Formule</label>
                            <select name="formule_id" id="formule_id" class="form-select" x-model="formuleId"
                                    @change="if (formule) { $refs.montant.value = formule.prix; }">
                                <option value="">— Durée et montant libres —</option>
                                @foreach ($formules as $formule)
                                    <option value="{{ $formule->id }}">
                                        {{ $formule->nom }} ({{ $formule->illimite ? 'illimité' : $formule->jours.' j' }} — {{ montant($formule->prix) }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-check mb-3" x-show="!formuleId" x-cloak>
                            <input type="checkbox" name="illimite" value="1" class="form-check-input" id="illimite">
                            <label class="form-check-label" for="illimite">Illimité</label>
                        </div>
                        <div class="mb-3" x-show="!formuleId" x-cloak>
                            <label for="jours" class="form-label">Jours</label>
                            <input type="number" name="jours" id="jours" class="form-control" min="1">
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <label for="montant" class="form-label">Montant reçu ({{ App\Models\Devise::abreviationActuelle() }})<span class="required-marker">*</span></label>
                                <input type="number" name="montant" id="montant" class="form-control" min="0" required x-ref="montant">
                            </div>
                            <div class="col-sm-6">
                                <label for="moyen" class="form-label">Reçu par<span class="required-marker">*</span></label>
                                <select name="moyen" id="moyen" class="form-select" required>
                                    @foreach ($moyens as $code => $libelle)
                                        <option value="{{ $code }}">{{ $libelle }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="remplacer" value="1" class="form-check-input" id="remplacer">
                            <label class="form-check-label" for="remplacer">Remplacer la durée en cours</label>
                            <div class="form-text">Sinon, les jours restants de l'abonnement actuel s'ajoutent à la nouvelle durée.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Activer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
