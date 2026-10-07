@extends('layouts.app')

@section('title', 'Mon abonnement')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Mon abonnement</h2>
        @if ($estGestionnaire)
            <a href="{{ route('abonnement.gestion') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-gear me-1"></i>Gestion abonnement
            </a>
        @endif
    </div>

    <x-alerte-abonnement-expire />

    @if ($bloquant)
        <div class="alert alert-danger">
            <h5 class="alert-heading"><i class="bi bi-exclamation-triangle me-1"></i>Abonnement expiré</h5>
            <p class="mb-2">
                Votre abonnement à l'application est arrivé à échéance. Merci de
                procéder au paiement pour continuer à l'utiliser, ou de contacter
                le développeur ou l'administrateur.
            </p>
            @if ($configuration->message)
                <p class="mb-2">{{ $configuration->message }}</p>
            @endif
            @if ($configuration->telephone || $configuration->whatsapp)
                <ul class="mb-0">
                    @if ($configuration->telephone)
                        <li>Téléphone : <strong>{{ telephone($configuration->telephone) }}</strong></li>
                    @endif
                    @if ($configuration->whatsapp)
                        <li>WhatsApp : <strong>{{ telephone($configuration->whatsapp) }}</strong></li>
                    @endif
                </ul>
            @endif
        </div>
    @endif

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
        <div class="col-sm-6 col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Statut</div>
                    <div class="fs-5 fw-semibold">
                        @if ($bloquant)
                            <span class="badge text-bg-danger">Expiré</span>
                        @else
                            <span class="badge text-bg-success">Actif</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    @unless ($estGestionnaire)
        <div class="mb-4">
            <h3 class="h5 mb-3">Formules disponibles</h3>

            @if ($formules->isEmpty())
                <div class="card">
                    <div class="card-body text-center text-muted py-4">Aucune formule disponible pour le moment.</div>
                </div>
            @else
                <div class="row g-3 mb-3">
                    @foreach ($formules as $formule)
                        @php $estActuelle = $derniere?->formule_id === $formule->id; @endphp
                        <div class="col-sm-6 col-lg-4">
                            <div class="formule-pricing-card @if($estActuelle) formule-pricing-card--actuelle @endif">
                                @if ($estActuelle)
                                    <span class="badge text-bg-primary formule-pricing-card__badge">Votre formule actuelle</span>
                                @endif
                                <div class="formule-pricing-card__nom">{{ $formule->nom }}</div>
                                <div class="formule-pricing-card__prix">{{ montant($formule->prix) }}</div>
                                <div class="formule-pricing-card__duree">
                                    <i class="bi bi-clock-history me-1"></i>
                                    {{ $formule->illimite ? 'Sans limite de durée' : $formule->jours.' jours' }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="card">
                <div class="card-body text-muted small">
                    <i class="bi bi-info-circle me-1"></i>
                    Le paiement en ligne n'est pas encore disponible — pour
                    choisir une formule ou reconduire votre abonnement actuel,
                    contactez l'administrateur
                    @if ($configuration->telephone)
                        au <strong>{{ telephone($configuration->telephone) }}</strong>
                    @endif
                    @if ($configuration->whatsapp)
                        (WhatsApp : <strong>{{ telephone($configuration->whatsapp) }}</strong>)
                    @endif
                    @if (! $configuration->telephone && ! $configuration->whatsapp)
                        ou le développeur
                    @endif
                    .
                    @if ($configuration->message)
                        <br>{{ $configuration->message }}
                    @endif
                </div>
            </div>
        </div>
    @endunless

    <div class="card">
        <div class="card-header">Historique</div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Formule</th>
                        <th>Jours</th>
                        <th>Montant</th>
                        <th>Fin</th>
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
                            <td class="text-muted small">{{ $activation->note }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">Aucune activation enregistrée.</td>
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
@endsection
