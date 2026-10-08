{{-- Sauvegardes de la base d'une entreprise (voir
     Plateforme\SauvegardeController) : la liste, une sauvegarde à la demande,
     le téléchargement et la restauration. --}}
@extends('layouts.app')

@section('title', 'Sauvegardes')

@section('content')
    <div class="page-entete">
        <div>
            <div class="d-flex align-items-center gap-2">
                <x-bouton-retour :route="route('plateforme.entreprises.index')" />
                <h2 class="page-entete__titre">Sauvegardes — {{ $entreprise->nom }}</h2>
            </div>
            <p class="page-entete__aide ms-5 ps-1">
                Une sauvegarde est faite chaque soir ; les {{ $aGarder }} plus récentes de chaque type sont gardées.
            </p>
        </div>

        <div class="page-entete__actions">
            <form method="POST" action="{{ route('plateforme.sauvegardes.store', $entreprise) }}">
                @csrf
                <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-arrow-down me-2"></i>Sauvegarder maintenant</button>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        @if ($sauvegardes->isEmpty())
            <div class="card-body">
                <div class="etat-vide etat-vide--grand">
                    <i class="bi bi-database"></i>
                    <h3 class="h5">Aucune sauvegarde pour l'instant</h3>
                    <p>La première sera faite ce soir. Vous pouvez aussi en lancer une tout de suite.</p>
                </div>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th class="text-end">Taille</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sauvegardes as $sauvegarde)
                            <tr>
                                <td>
                                    <span class="fw-medium">{{ $sauvegarde['date']->format('d/m/Y à H:i') }}</span>
                                    <span class="d-block small text-secondary">{{ $sauvegarde['date']->diffForHumans() }}</span>
                                </td>
                                <td>
                                    @switch($sauvegarde['type'])
                                        @case('auto') <span class="badge text-bg-primary">Automatique</span> @break
                                        @case('manuelle') <span class="badge text-bg-info">À la demande</span> @break
                                        @default <span class="badge text-bg-secondary">Avant une restauration</span>
                                    @endswitch
                                </td>
                                <td class="text-end text-secondary">{{ number_format($sauvegarde['taille'] / 1024, 0, ',', ' ') }} Ko</td>
                                <td class="text-end">
                                    <a href="{{ route('plateforme.sauvegardes.telecharger', [$entreprise, $sauvegarde['nom']]) }}" class="btn btn-sm btn-outline-secondary" data-sans-spinner>
                                        <i class="bi bi-download me-1"></i>Télécharger
                                    </a>
                                    <x-confirm-button :action="route('plateforme.sauvegardes.restaurer', [$entreprise, $sauvegarde['nom']])"
                                                      :message="'Restaurer la base de « '.$entreprise->nom.' » à son état du '.$sauvegarde['date']->format('d/m/Y à H:i').' ? Tout ce qui a été saisi depuis sera remplacé. L’état actuel est sauvegardé juste avant, pour pouvoir y revenir.'"
                                                      button-label="Restaurer" button-class="btn-sm btn-outline-danger" icon="bi-arrow-counterclockwise" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
