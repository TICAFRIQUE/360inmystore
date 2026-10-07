{{-- Bouton d'une carte de formule dans l'espace d'une entreprise (voir
     x-tarifs) : seul le propriétaire de l'espace choisit et paie ; la formule
     gratuite démarre l'essai sans paiement. --}}
@if (auth()->user()->hasRole('Superadmin'))
    @if ($formule->estGratuite())
        <form method="POST" action="{{ route('abonnement.essai', $formule->id) }}">
            @csrf
            <button type="submit" class="btn btn-outline-primary w-100">Commencer l'essai gratuit</button>
        </form>
    @else
        <a href="{{ route('abonnement.paiement.create', $formule->id) }}" class="btn btn-primary w-100">
            <i class="bi bi-credit-card me-1"></i>Choisir et payer
        </a>
    @endif
@endif
