@extends('layouts.app')

@section('title', $compte->nom)

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-2 mb-3">
        <div>
            <div class="d-flex align-items-center gap-2">
                <x-bouton-retour :route="route('comptabilite.caisses.index')" />
                <h2 class="h4 mb-0">{{ $compte->nom }}</h2>
            </div>
            <p class="text-secondary small mb-0 ms-5 ps-1">
                @if ($compte->type === 'caisse_generale')
                    <span class="badge text-bg-primary">Caisse Générale</span>
                @elseif ($compte->type === 'banque')
                    <span class="badge text-bg-info">Banque</span>
                @else
                    <span class="badge text-bg-secondary">Autre</span>
                @endif
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap flex-shrink-0">
            @can('tresorerie.gerer')
                @if ($compte->type !== 'caisse_generale')
                    <button type="button" class="btn btn-outline-secondary d-print-none" data-bs-toggle="modal" data-bs-target="#modifierCompteModal">
                        <i class="bi bi-pencil me-1"></i>Modifier le compte
                    </button>
                @endif
            @endcan
            <x-export-buttons :pdf-route="route('comptabilite.caisses.show.pdf', array_merge(request()->query(), ['compte' => $compte->id]))"
                :excel-route="route('comptabilite.caisses.show.excel', array_merge(request()->query(), ['compte' => $compte->id]))" />
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <x-kpi-card label="Solde actuel (toujours global)" icon="bi-wallet2" color="success"
                :value="montant($solde)" />
        </div>
    </div>

    @can('tresorerie.gerer')
        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-6">
                <div class="card h-100" x-data="{ ouvert: false, ajoutMotifOuvert: false }">
                    <div class="card-body">
                        <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                            <div>
                                <div class="d-flex align-items-center gap-2 fw-semibold">
                                    <i class="bi bi-arrow-left-right fs-5"></i>Mouvement manuel
                                </div>
                                <div class="small text-secondary mt-1">Entrée ou sortie directe sur ce compte.</div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0" @click="ouvert = !ouvert" x-show="!ouvert">
                                <i class="bi bi-plus-lg me-1"></i>Enregistrer
                            </button>
                        </div>

                        <form method="POST" action="{{ route('comptabilite.mouvements.store', $compte) }}" x-show="ouvert" x-cloak class="mt-2">
                            @csrf
                            <div class="row g-2 align-items-end">
                                <div class="col-6 col-md-3">
                                    <label for="type" class="form-label small mb-1">Type</label>
                                    <select name="type" id="type" class="form-select form-select-sm" required>
                                        <option value="sortie">Sortie</option>
                                        <option value="entree">Entrée</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label for="montant" class="form-label small mb-1">Montant ({{ App\Models\Devise::abreviationActuelle() }})</label>
                                    <input type="number" name="montant" id="montant" min="1" class="form-control form-control-sm" required>
                                </div>
                                @include('partials.champ-motif-mouvement')
                            </div>
                            <div class="d-flex gap-2 mt-2">
                                <button type="submit" class="btn btn-outline-secondary btn-sm">Enregistrer</button>
                                <button type="button" class="btn btn-link btn-sm" @click="ouvert = false">Fermer</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card h-100" x-data="{ ouvert: false }">
                    <div class="card-body">
                        <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                            <div>
                                <div class="d-flex align-items-center gap-2 fw-semibold">
                                    <i class="bi bi-bank fs-5"></i>Virement
                                </div>
                                <div class="small text-secondary mt-1">Transférer vers un autre compte de trésorerie.</div>
                            </div>
                            @if ($autresComptes->isNotEmpty())
                                <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0" @click="ouvert = !ouvert" x-show="!ouvert">
                                    <i class="bi bi-plus-lg me-1"></i>Virer
                                </button>
                            @endif
                        </div>

                        @if ($autresComptes->isEmpty())
                            <p class="text-secondary small fst-italic mb-0">
                                Aucun autre compte pour l'instant.
                                @can('tresorerie.gerer')
                                    <a href="{{ route('comptabilite.caisses.index') }}">Créer un compte bancaire</a>
                                    depuis la liste des caisses et comptes pour pouvoir y virer de l'argent.
                                @endcan
                            </p>
                        @else
                            <form method="POST" action="{{ route('comptabilite.virement.store', $compte) }}" x-show="ouvert" x-cloak class="mt-2">
                                @csrf
                                <div class="row g-2 align-items-end">
                                    <div class="col-12 col-md-5">
                                        <label for="destination_id" class="form-label small mb-1">Vers</label>
                                        <select name="destination_id" id="destination_id" class="form-select form-select-sm" required>
                                            <option value="">Choisir…</option>
                                            @foreach ($autresComptes as $autre)
                                                <option value="{{ $autre->id }}">{{ $autre->nom }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label for="montant_virement" class="form-label small mb-1">Montant ({{ App\Models\Devise::abreviationActuelle() }})</label>
                                        <input type="number" name="montant" id="montant_virement" min="1" class="form-control form-control-sm" required>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label for="motif_virement" class="form-label small mb-1">Motif</label>
                                        <input type="text" name="motif" id="motif_virement" maxlength="255" class="form-control form-control-sm" placeholder="Optionnel">
                                    </div>
                                </div>
                                <div class="d-flex gap-2 mt-2">
                                    <button type="submit" class="btn btn-outline-secondary btn-sm">Virer</button>
                                    <button type="button" class="btn btn-link btn-sm" @click="ouvert = false">Fermer</button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        @if ($compte->type !== 'caisse_generale')
            <div class="modal fade" id="modifierCompteModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="{{ route('comptabilite.comptes.update', $compte) }}">
                            @csrf
                            @method('PUT')
                            <div class="modal-header">
                                <h5 class="modal-title">Modifier le compte</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label for="nom_compte" class="form-label">Nom<span class="required-marker">*</span></label>
                                    <input type="text" name="nom" id="nom_compte" class="form-control" value="{{ $compte->nom }}" required>
                                </div>
                                <div class="mb-0">
                                    <label for="actif" class="form-label">Statut</label>
                                    <select name="actif" id="actif" class="form-select">
                                        <option value="1" @selected($compte->actif)>Actif</option>
                                        <option value="0" @selected(! $compte->actif)>Inactif</option>
                                    </select>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Annuler</button>
                                <button type="submit" class="btn btn-primary">Enregistrer</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    @endcan

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h3 class="h6 mb-0">Historique</h3>
    </div>

    <form method="GET" action="{{ route('comptabilite.caisses.show', $compte) }}" class="row g-2 mb-3">
        <div class="col-auto">
            <input type="date" name="debut" value="{{ request('debut') }}" class="form-control form-control-sm" placeholder="Du">
        </div>
        <div class="col-auto">
            <input type="date" name="fin" value="{{ request('fin') }}" class="form-control form-control-sm" placeholder="Au">
        </div>
        <div class="col-auto">
            <select name="type" class="form-select form-select-sm">
                <option value="">Tous les types</option>
                @foreach (\App\Enums\EcritureCompteTresorerieType::cases() as $type)
                    <option value="{{ $type->value }}" @selected(request('type') === $type->value)>{{ $type->libelle() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filtrer</button>
        </div>
        @if (request()->hasAny(['debut', 'fin', 'type']))
            <div class="col-auto">
                <x-bouton-reinitialiser :route="route('comptabilite.caisses.show', $compte)" />
            </div>
        @endif
    </form>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Motif</th>
                            <th>Auteur</th>
                            <th class="text-end">Montant</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ecritures as $ecriture)
                            <tr>
                                <td>{{ $ecriture->created_at->format('d/m/Y H:i') }}</td>
                                <td><span class="badge {{ $ecriture->type->classeBadge() }}">{{ $ecriture->type->libelle() }}</span></td>
                                <td>{{ $ecriture->motif ?? '—' }}</td>
                                <td>{{ $ecriture->auteur?->name ?? 'Utilisateur supprimé' }}</td>
                                <td class="text-end fw-medium {{ $ecriture->montant >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ $ecriture->montant >= 0 ? '+' : '' }}{{ montant($ecriture->montant) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-secondary py-4">Aucun mouvement pour l'instant.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <th colspan="4" class="text-end">Totaux (période filtrée)</th>
                            <th class="text-end">
                                <span class="text-success">+ {{ montant($totalEntrees) }}</span>
                                ·
                                <span class="text-danger">− {{ montant($totalSorties) }}</span>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        @if ($ecritures instanceof \Illuminate\Pagination\LengthAwarePaginator && $ecritures->hasPages())
            <div class="card-body pt-0 d-print-none">
                {{ $ecritures->onEachSide(1)->links() }}
            </div>
        @endif
    </div>
@endsection
