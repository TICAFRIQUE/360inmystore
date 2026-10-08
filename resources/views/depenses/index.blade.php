{{-- Dépenses (voir DepenseController) : ce qui a été payé sur la période,
     réparti par motif, et le détail. Une dépense annulée reste affichée,
     barrée : elle ne compte plus dans les totaux. --}}
@extends('layouts.app')

@section('title', 'Dépenses')

@section('content')
    <div class="page-entete d-print-none">
        <div>
            <h2 class="page-entete__titre">Dépenses</h2>
            <p class="page-entete__aide">Du {{ $debut->format('d/m/Y') }} au {{ $fin->format('d/m/Y') }}</p>
        </div>
        <div class="page-entete__actions">
            @can('depense.creer')
                <a href="{{ route('depenses.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Nouvelle dépense</a>
            @endcan
        </div>
    </div>

    {{-- Récapitulatif : une seule carte basse. Le total à gauche, puis les
         motifs en lignes serrées (motif, montant) — les 6 premiers, la suite
         résumée sous « autres motifs » et dépliable. Sa hauteur ne dépend ni
         du nombre de dépenses ni, repliée, du nombre de motifs. --}}
    @php
        $limiteMotifs = 6;
        $resteMotifs = $parMotif->slice($limiteMotifs);
        // Un seul motif en trop : autant le montrer que le résumer.
        $replie = $resteMotifs->count() > 1;
    @endphp

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3 recap-flux" @if ($replie) x-data="{ tout: false }" @endif>
            <div class="recap-flux__total">
                <span class="recap-flux__icone"><i class="bi bi-arrow-up-right"></i></span>
                <div>
                    <div class="recap-flux__libelle">Total des dépenses</div>
                    <div class="recap-flux__montant">{{ $total > 0 ? '− ' : '' }}{{ montant($total) }}</div>
                    <div class="recap-flux__libelle">{{ $nombre > 0 ? $nombre.' dépense'.($nombre > 1 ? 's payées' : ' payée') : 'Aucune dépense sur la période' }}</div>
                </div>
            </div>

            @if ($parMotif->isNotEmpty())
                <div>
                    <ul class="recap-flux__postes">
                        @foreach ($parMotif as $poste)
                            <li class="recap-flux__poste" @if ($replie && $loop->index >= $limiteMotifs) x-show="tout" x-cloak @endif>
                                <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
                                <span class="recap-flux__nom" title="{{ $poste->motif }}">{{ $poste->motif }}</span>
                                <span class="recap-flux__valeur">{{ montant($poste->total) }}</span>
                            </li>
                        @endforeach
                        @if ($replie)
                            <li class="recap-flux__poste recap-flux__poste--autres" x-show="! tout">
                                <i class="bi bi-three-dots" aria-hidden="true"></i>
                                <span class="recap-flux__nom">{{ $resteMotifs->count() }} autres motifs</span>
                                <span class="recap-flux__valeur">{{ montant($resteMotifs->sum('total')) }}</span>
                            </li>
                        @endif
                    </ul>

                    @if ($replie)
                        <button type="button" class="btn btn-link btn-sm p-0 mt-1 d-print-none" @click="tout = ! tout" :aria-expanded="tout.toString()">
                            <span x-show="! tout">Voir les {{ $parMotif->count() }} motifs<i class="bi bi-chevron-down ms-1"></i></span>
                            <span x-show="tout" x-cloak>Réduire<i class="bi bi-chevron-up ms-1"></i></span>
                        </button>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3 d-print-none">
        <div class="card-body">
            <form method="GET" action="{{ route('depenses.index') }}" class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label for="du" class="form-label small mb-1">Du</label>
                    <input type="date" name="du" id="du" class="form-control form-control-sm" value="{{ $debut->toDateString() }}">
                </div>
                <div class="col-6 col-md-2">
                    <label for="au" class="form-label small mb-1">Au</label>
                    <input type="date" name="au" id="au" class="form-control form-control-sm" value="{{ $fin->toDateString() }}">
                </div>
                <div class="col-6 col-md-2">
                    <label for="motif" class="form-label small mb-1">Motif</label>
                    <select name="motif" id="motif" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        @foreach ($motifs as $motif)
                            <option value="{{ $motif->nom }}" @selected(request('motif') === $motif->nom)>{{ $motif->nom }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($magasins->isNotEmpty())
                    <div class="col-6 col-md-2">
                        <label for="magasin_id" class="form-label small mb-1">Magasin</label>
                        <select name="magasin_id" id="magasin_id" class="form-select form-select-sm">
                            <option value="">Tous</option>
                            @foreach ($magasins as $magasin)
                                <option value="{{ $magasin->id }}" @selected(request('magasin_id') == $magasin->id)>{{ $magasin->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-12 col-md">
                    <label for="recherche" class="form-label small mb-1">Recherche</label>
                    <input type="search" name="recherche" id="recherche" class="form-control form-control-sm" value="{{ request('recherche') }}" placeholder="Motif ou bénéficiaire…">
                </div>
                <div class="col-12 col-md-auto d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">Filtrer</button>
                    <x-export-buttons :pdf-route="route('depenses.pdf', request()->query())" :excel-route="route('depenses.excel', request()->query())" :tout="true" />
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Motif</th>
                        <th>Bénéficiaire</th>
                        <th>Magasin</th>
                        <th>Payée depuis</th>
                        <th>Saisie par</th>
                        <th class="text-end">Montant</th>
                        <th class="text-end d-print-none">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($depenses as $depense)
                        <tr @class(['text-secondary' => $depense->estAnnulee()])>
                            <td>{{ $depense->created_at->format('d/m/Y H:i') }}</td>
                            <td>
                                <span @class(['fw-medium' => ! $depense->estAnnulee(), 'text-decoration-line-through' => $depense->estAnnulee()])>{{ $depense->motif }}</span>
                                @if ($depense->estAnnulee())
                                    <span class="badge text-bg-secondary ms-1">Annulée</span>
                                    <span class="d-block small">{{ $depense->motif_annulation }}</span>
                                @endif
                            </td>
                            <td>{{ $depense->beneficiaire ?? '—' }}</td>
                            <td>{{ $depense->magasin?->nom ?? "Toute l'entreprise" }}</td>
                            <td>{{ $depense->sourceLibelle() }}</td>
                            <td>{{ $depense->auteur?->name }}</td>
                            <td @class(['text-end fw-medium', 'text-decoration-line-through' => $depense->estAnnulee()])>{{ montant($depense->montant) }}</td>
                            <td class="text-end d-print-none">
                                @if ($depense->getFirstMediaUrl('recu'))
                                    <a href="{{ $depense->getFirstMediaUrl('recu') }}" target="_blank" rel="noopener" class="btn btn-sm btn-icon btn-outline-secondary" title="Voir le reçu">
                                        <i class="bi bi-receipt"></i><span class="visually-hidden">Voir le reçu</span>
                                    </a>
                                @endif
                                @can('depense.annuler')
                                    @unless ($depense->estAnnulee())
                                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#annulerDepense"
                                                data-action="{{ route('depenses.annuler', $depense) }}"
                                                data-resume="{{ $depense->motif }} — {{ montant($depense->montant) }}">
                                            Annuler
                                        </button>
                                    @endunless
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <div class="etat-vide">
                                    <i class="bi bi-wallet2"></i>
                                    <p>Aucune dépense sur cette période. Une dépense s'enregistre ici, ou par une sortie de caisse dont le motif est marqué « dépense ».</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($depenses->hasPages())
            <div class="card-body d-print-none">{{ $depenses->links() }}</div>
        @endif
    </div>

    {{-- Annulation : une seule fenêtre pour toute la liste ; elle reçoit la
         dépense visée du bouton qui l'ouvre. --}}
    @can('depense.annuler')
        <div class="modal fade" id="annulerDepense" tabindex="-1" aria-labelledby="annulerDepenseTitre" aria-hidden="true"
             x-data="{ action: '', resume: '' }"
             {{-- x-on: plutôt que @ : « @show » est une directive Blade. --}}
             x-on:show-bs-modal.dot="action = $event.relatedTarget.dataset.action; resume = $event.relatedTarget.dataset.resume">
            <div class="modal-dialog">
                <form method="POST" :action="action" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h3 class="modal-title h5" id="annulerDepenseTitre">Annuler cette dépense ?</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                    </div>
                    <div class="modal-body">
                        <p class="fw-medium mb-2" x-text="resume"></p>
                        <p class="text-secondary">La dépense reste dans la liste, marquée annulée, et son montant est remis en caisse. Cette action ne peut pas être défaite.</p>
                        <label for="raison" class="form-label">Pourquoi ?<span class="required-marker">*</span></label>
                        <input type="text" name="raison" id="raison" class="form-control" maxlength="255" required placeholder="Erreur de montant, saisie en double…">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-link" data-bs-dismiss="modal">Garder la dépense</button>
                        <button type="submit" class="btn btn-danger">Annuler la dépense</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection
