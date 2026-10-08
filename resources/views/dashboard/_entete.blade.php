{{-- En-tête commun aux tableaux de bord : à qui l'on parle, sur quel
     périmètre, et les raccourcis vers ce qu'on vient y faire. $perimetre
     (facultatif) : le magasin, ou « tous magasins ». --}}
<div class="page-entete">
    <div>
        <h2 class="page-entete__titre">Bonjour, {{ $utilisateur->name }}</h2>
        <p class="page-entete__aide">
            @if (! empty($perimetre)) {{ $perimetre }} · @endif{{ ucfirst(now()->translatedFormat('l j F Y')) }}
        </p>
    </div>

    <div class="page-entete__actions">
        @can('caisse.ouvrir')
            <a href="{{ route('sessions.index') }}" class="btn btn-primary"><i class="bi bi-cash-stack me-2"></i>Caisses</a>
        @endcan
        @can('rapport.voir')
            <a href="{{ route('rapports.index') }}" class="btn btn-outline-secondary"><i class="bi bi-bar-chart-line me-2"></i>Rapports</a>
        @endcan
    </div>
</div>
