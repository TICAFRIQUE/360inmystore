@php
    $utilisateurBarre = auth()->user();
    $initiales = collect(preg_split('/\s+/', trim($utilisateurBarre->name)))
        ->filter()
        ->take(2)
        ->map(fn (string $mot) => mb_strtoupper(mb_substr($mot, 0, 1)))
        ->implode('');
@endphp
<div class="d-flex align-items-center gap-1 gap-sm-2">
{{-- Raccourci permanent vers la session de caisse en cours du caissier
     connecté (même donnée que l'entrée "Vente → Facture/BL" du menu, voir
     AppServiceProvider — sessionCaissierOuverteMenu) : accessible en un clic
     depuis n'importe quel écran, y compris menu réduit. --}}
@if ($sessionCaissierOuverteMenu)
    <a href="{{ route('sessions.show', $sessionCaissierOuverteMenu) }}" class="erp-topbar__session" title="Aller à ma session de caisse en cours">
        <span class="erp-topbar__session-point" aria-hidden="true"></span>
        <span class="d-none d-md-inline">Caisse ouverte</span>
        <i class="bi bi-cash-stack d-md-none" aria-hidden="true"></i>
        <span class="visually-hidden d-md-none">Caisse ouverte</span>
    </a>
@endif

@can('rapport.voir')
    @include('partials._cloche-notifications', [
        'type' => \App\Notifications\EcartCaisseDetecte::class,
        'icon' => 'bi-exclamation-triangle',
        'titre' => 'Écarts de caisse',
    ])
    @include('partials._cloche-notifications', [
        'type' => \App\Notifications\SessionOuverteTropLongtemps::class,
        'icon' => 'bi-hourglass-split',
        'titre' => 'Sessions ouvertes',
    ])
    @include('partials._cloche-notifications', [
        'type' => \App\Notifications\VenteSignalee::class,
        'icon' => 'bi-flag',
        'titre' => 'Ventes signalées',
    ])
    @include('partials._cloche-stock-seuil')
@endcan

<div class="dropdown">
    <button class="erp-topbar__compte dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <span class="erp-avatar" aria-hidden="true">{{ $initiales }}</span>
        <span class="d-none d-sm-flex flex-column align-items-start lh-sm">
            <span class="erp-topbar__compte-nom">{{ $utilisateurBarre->name }}</span>
            @if ($utilisateurBarre->magasin)
                <span class="erp-topbar__compte-magasin">{{ $utilisateurBarre->magasin->nom }}</span>
            @endif
        </span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li>
            <div class="dropdown-header">
                <div class="text-body fw-medium">{{ $utilisateurBarre->name }}</div>
                <div>{{ '@'.$utilisateurBarre->username }}@if ($utilisateurBarre->magasin) · {{ $utilisateurBarre->magasin->nom }}@endif</div>
            </div>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <a class="dropdown-item" href="{{ route('guide') }}">
                <i class="bi bi-question-circle me-2"></i>Guide d'utilisation
            </a>
        </li>
        <li>
            <a class="dropdown-item" href="{{ route('abonnement.mon') }}">
                <i class="bi bi-calendar-check me-2"></i>Mon abonnement
            </a>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="dropdown-item">
                    <i class="bi bi-box-arrow-right me-2"></i>Se déconnecter
                </button>
            </form>
        </li>
    </ul>
</div>
</div>
