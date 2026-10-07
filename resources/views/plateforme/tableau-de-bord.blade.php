@extends('layouts.app')

@section('title', 'Tableau de bord')

@section('content')
    <div class="mb-4">
        <h2 class="h4 mb-1">Bonjour, {{ auth()->user()->name }}</h2>
        <p class="text-secondary mb-0">Votre plateforme {{ config('app.name') }} en un coup d'œil.</p>
    </div>

    @if ($paiementSimule)
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong>Paiement en ligne en mode simulation.</strong> Un client qui « paie » depuis son espace prolonge
            son abonnement sans rien débourser. Enregistrez les paiements réellement reçus depuis
            <a href="{{ route('plateforme.entreprises.index') }}" class="alert-link">Entreprises</a> (« Activer un abonnement »).
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <x-kpi-card label="Entreprises" :value="$indicateurs['total']" icon="bi-buildings" color="primary" />
        </div>
        <div class="col-6 col-xl-3">
            <x-kpi-card label="Nouvelles ce mois" :value="$indicateurs['nouvelles_mois']" icon="bi-person-plus" color="info" />
        </div>
        <div class="col-6 col-xl-3">
            <x-kpi-card label="Abonnement en cours" :value="$indicateurs['abonnees']" icon="bi-check-circle" color="success" />
        </div>
        <div class="col-6 col-xl-3">
            <x-kpi-card label="Encaissé ce mois" :value="montant($indicateurs['encaisse_mois'])" icon="bi-cash-coin" color="success" />
        </div>
        <div class="col-6 col-xl-3">
            <a href="{{ route('plateforme.entreprises.index', ['abonnement' => 'sans_formule']) }}" class="text-decoration-none">
                <x-kpi-card label="Formule non choisie" :value="$indicateurs['sans_formule']" icon="bi-hourglass" color="secondary" />
            </a>
        </div>
        <div class="col-6 col-xl-3">
            <a href="{{ route('plateforme.entreprises.index', ['abonnement' => 'expire']) }}" class="text-decoration-none">
                <x-kpi-card label="Abonnement expiré" :value="$indicateurs['expirees']" icon="bi-hourglass-bottom" color="warning" />
            </a>
        </div>
        <div class="col-6 col-xl-3">
            <a href="{{ route('plateforme.entreprises.index', ['statut' => 'suspendue']) }}" class="text-decoration-none">
                <x-kpi-card label="Suspendues" :value="$indicateurs['suspendues']" icon="bi-lock" color="danger" />
            </a>
        </div>
        <div class="col-6 col-xl-3">
            <x-kpi-card label="Encaissé depuis le début" :value="montant($indicateurs['encaisse_total'])" icon="bi-piggy-bank" color="primary" />
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">Inscriptions et encaissements (6 derniers mois)</div>
                <div class="card-body">
                    {{-- Hauteur fixée par le conteneur : sans ça, Chart.js
                         dimensionne le graphique d'après le <canvas> seul. --}}
                    <div style="position: relative; height: 16rem;">
                        <canvas id="chart-plateforme"
                                data-mois='@json($graphique['mois'])'
                                data-inscriptions='@json($graphique['inscriptions'])'
                                data-encaisse='@json($graphique['encaisse'])'></canvas>
                    </div>
                    <p class="text-secondary small mb-0 mt-2">Encaissements hors paiements simulés.</p>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header">Dernières inscriptions</div>
                <ul class="list-group list-group-flush">
                    @forelse ($inscriptions as $entreprise)
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div class="overflow-hidden">
                                <div class="fw-medium text-truncate">{{ $entreprise->nom }}</div>
                                <div class="small text-secondary text-truncate">{{ $entreprise->proprietaire_nom }} · {{ telephone($entreprise->telephone) }}</div>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <div class="small">{{ $entreprise->created_at->format('d/m/Y') }}</div>
                                <div class="small {{ $entreprise->sansFormule() ? 'text-warning-emphasis' : 'text-secondary' }}">{{ $entreprise->formule_nom ?? 'Formule non choisie' }}</div>
                            </div>
                        </li>
                    @empty
                        <li class="list-group-item text-center text-secondary py-4">Aucune entreprise pour l'instant.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Échéances dans les {{ $joursEcheanceProche }} jours</div>
                <ul class="list-group list-group-flush">
                    @forelse ($echeances as $entreprise)
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div class="overflow-hidden">
                                <div class="fw-medium text-truncate">{{ $entreprise->nom }}</div>
                                <div class="small text-secondary">{{ telephone($entreprise->telephone) }}</div>
                            </div>
                            <span class="badge text-bg-warning flex-shrink-0">{{ $entreprise->abonnement_fin->format('d/m/Y') }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-center text-secondary py-4">Aucune échéance proche.</li>
                    @endforelse
                </ul>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Abonnements expirés</div>
                <ul class="list-group list-group-flush">
                    @forelse ($expirees as $entreprise)
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div class="overflow-hidden">
                                <div class="fw-medium text-truncate">{{ $entreprise->nom }}</div>
                                <div class="small text-secondary">{{ telephone($entreprise->telephone) }}</div>
                            </div>
                            <span class="badge text-bg-danger flex-shrink-0">Depuis le {{ $entreprise->abonnement_fin->format('d/m/Y') }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-center text-secondary py-4">Aucun abonnement expiré.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Derniers paiements</span>
            <a href="{{ route('plateforme.paiements.index') }}" class="small">Tout voir</a>
        </div>
        @include('plateforme._paiements-table', ['paiements' => $paiements])
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const el = document.getElementById('chart-plateforme');
            if (! el) return;

            // Chart.js dessine sur un <canvas> : les variables CSS ne
            // l'atteignent pas toutes seules — on relit la couleur primaire à
            // l'exécution (voir dashboard/_apercu.blade.php).
            const racine = getComputedStyle(document.documentElement);
            const primaire = (racine.getPropertyValue('--bs-primary') || '#2563eb').trim();

            new Chart(el, {
                data: {
                    labels: JSON.parse(el.dataset.mois),
                    datasets: [
                        { type: 'bar', label: 'Inscriptions', data: JSON.parse(el.dataset.inscriptions), backgroundColor: primaire, yAxisID: 'y', borderRadius: 4 },
                        { type: 'line', label: 'Encaissé ({{ App\Models\Devise::abreviationActuelle() }})', data: JSON.parse(el.dataset.encaisse), borderColor: '#15803d', backgroundColor: '#15803d', tension: 0.3, yAxisID: 'y1' },
                    ],
                },
                options: {
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Inscriptions' } },
                        y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Encaissé' } },
                    },
                },
            });
        });
    </script>
@endpush
