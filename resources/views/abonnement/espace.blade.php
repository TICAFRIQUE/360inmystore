{{-- Abonnement vu depuis l'espace d'une entreprise (plateforme SaaS) : choix
     de la formule à la première connexion, puis suivi et renouvellement.
     C'est aussi l'unique page accessible tant qu'aucune formule n'est en
     cours (voir AssureAbonnementActif). --}}
@extends('layouts.app')

@section('title', $derniere ? 'Mon abonnement' : 'Choisir ma formule')

@section('content')
    @if (! $derniere)
        <div class="text-center mb-4">
            <h2 class="h3 mb-2">Bienvenue ! Choisissez votre formule pour commencer</h2>
            <p class="text-secondary mb-0">Votre espace est prêt. Il s'ouvre dès que votre formule est choisie.</p>
        </div>
    @else
        <h2 class="h4 mb-3">Mon abonnement</h2>

        @if ($bloquant)
            <div class="alert alert-danger">
                <h5 class="alert-heading"><i class="bi bi-exclamation-triangle me-1"></i>Abonnement expiré</h5>
                <p class="mb-0">
                    Votre abonnement est arrivé à échéance : votre espace est fermé jusqu'à son renouvellement.
                    Vos données sont conservées.
                </p>
            </div>
        @endif

        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-secondary small">Formule actuelle</div>
                        <div class="fs-5 fw-semibold">{{ $derniere->formule?->nom ?? 'Durée libre' }}</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-secondary small">Échéance</div>
                        <div class="fs-5 fw-semibold">
                            @if ($derniere->illimite)
                                <span class="badge text-bg-success">Sans limite de durée</span>
                            @else
                                {{ $derniere->date_fin->format('d/m/Y') }}
                                <span class="fs-6 fw-normal {{ $bloquant ? 'text-danger' : 'text-secondary' }}">
                                    — {{ $bloquant ? 'expirée' : $joursRestants.' jour'.($joursRestants > 1 ? 's' : '').' restant'.($joursRestants > 1 ? 's' : '') }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <h3 class="h5 mb-3">{{ $bloquant ? 'Renouveler mon abonnement' : 'Changer ou prolonger ma formule' }}</h3>
    @endif

    @if ($formules->isEmpty())
        <div class="card mb-4">
            <div class="card-body text-center text-secondary py-4">Aucune formule n'est proposée pour le moment.</div>
        </div>
    @else
        <div class="mb-3">
            <x-tarifs :formules="$formules" action="abonnement._action-formule" :actuelle="$derniere?->formule" />
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-body text-secondary small">
            <i class="bi bi-info-circle me-1"></i>
            @if ($paiementEnLigne)
                En renouvelant avant l'échéance, les jours restants de votre abonnement en cours sont conservés.
            @else
                Seul le propriétaire de l'espace peut choisir ou renouveler la formule.
            @endif
            @if ($configuration->telephone || $configuration->whatsapp)
                Une question ? Contactez {{ config('app.name') }}
                @if ($configuration->telephone) au <strong>{{ telephone($configuration->telephone) }}</strong>@endif
                @if ($configuration->whatsapp) (WhatsApp : <strong>{{ telephone($configuration->whatsapp) }}</strong>)@endif.
            @endif
            @if ($configuration->message)
                <br>{{ $configuration->message }}
            @endif
        </div>
    </div>

    @if ($derniere)
        <div class="card">
            <div class="card-header">Historique</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Formule</th>
                            <th>Durée</th>
                            <th class="text-end">Montant</th>
                            <th>Échéance</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($historique as $activation)
                            <tr>
                                <td>{{ $activation->date_debut->format('d/m/Y') }}</td>
                                <td>{{ $activation->formule?->nom ?? 'Durée libre' }}</td>
                                <td>{{ $activation->illimite ? 'Illimitée' : $activation->jours.' jours' }}</td>
                                <td class="text-end">{{ montant($activation->montant) }}</td>
                                <td>{{ $activation->illimite ? '—' : $activation->date_fin->format('d/m/Y') }}</td>
                                <td class="small text-secondary">{{ $activation->note }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $historique->links() }}</div>
    @endif
@endsection
