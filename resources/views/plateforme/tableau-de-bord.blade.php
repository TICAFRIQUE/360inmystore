{{-- Tableau de bord de l'éditeur (domaine principal). Même présentation que
     les tableaux de bord d'un commerce (voir dashboard/_apercu) : ce qui
     compte d'abord, ce qui demande une action ensuite, puis le détail. --}}
@extends('layouts.app')

@section('title', 'Tableau de bord')

@section('content')
    <div class="page-entete">
        <div>
            <h2 class="page-entete__titre">Bonjour, {{ auth()->user()->name }}</h2>
            <p class="page-entete__aide">Votre plateforme {{ config('app.name') }} · {{ ucfirst(now()->translatedFormat('l j F Y')) }}</p>
        </div>
        <div class="page-entete__actions">
            <a href="{{ route('plateforme.entreprises.index') }}" class="btn btn-primary"><i class="bi bi-buildings me-2"></i>Entreprises</a>
            <a href="{{ route('plateforme.paiements.index') }}" class="btn btn-outline-secondary"><i class="bi bi-cash-coin me-2"></i>Paiements</a>
        </div>
    </div>

    @if ($paiementSimule)
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong>Paiement en ligne en mode simulation.</strong> Un client qui « paie » depuis son espace prolonge
            son abonnement sans rien débourser. Enregistrez les paiements réellement reçus depuis
            <a href="{{ route('plateforme.entreprises.index') }}" class="alert-link">Entreprises</a> (« Activer un abonnement »).
        </div>
    @endif

    {{-- Où en est la plateforme --}}
    <div class="row row-cols-2 row-cols-xl-4 g-3 mb-3">
        <div class="col">
            <x-kpi-card stacked label="Entreprises" :value="$indicateurs['total']" icon="bi-buildings" color="primary"
                        :hint="$indicateurs['nouvelles_mois'].' nouvelle'.($indicateurs['nouvelles_mois'] > 1 ? 's' : '').' ce mois'"
                        :href="route('plateforme.entreprises.index')" />
        </div>
        <div class="col">
            <x-kpi-card stacked label="Abonnement en cours" :value="$indicateurs['abonnees']" icon="bi-check-circle" color="success"
                        hint="Espaces actifs et à jour" />
        </div>
        <div class="col">
            <x-kpi-card stacked label="Encaissé ce mois" :value="montant($indicateurs['encaisse_mois'])" icon="bi-cash-coin" color="primary"
                        hint="Hors paiements simulés" :href="route('plateforme.paiements.index')" />
        </div>
        <div class="col">
            <x-kpi-card stacked label="Encaissé depuis le début" :value="montant($indicateurs['encaisse_total'])" icon="bi-piggy-bank" color="info"
                        hint="Hors paiements simulés" />
        </div>
    </div>

    {{-- Ce qui demande une action --}}
    <div class="row row-cols-2 row-cols-xl-4 g-3 mb-3">
        <div class="col">
            <x-kpi-card stacked label="Formule non choisie" :value="$indicateurs['sans_formule']" icon="bi-hourglass"
                        :color="$indicateurs['sans_formule'] > 0 ? 'warning' : 'secondary'"
                        :hint="$indicateurs['sans_formule'] > 0 ? 'Inscrites, à relancer' : 'Personne en attente'"
                        :href="route('plateforme.entreprises.index', ['abonnement' => 'sans_formule'])" />
        </div>
        <div class="col">
            <x-kpi-card stacked label="Abonnement expiré" :value="$indicateurs['expirees']" icon="bi-hourglass-bottom"
                        :color="$indicateurs['expirees'] > 0 ? 'danger' : 'secondary'"
                        :hint="$indicateurs['expirees'] > 0 ? 'À renouveler' : 'Aucun abonnement expiré'"
                        :href="route('plateforme.entreprises.index', ['abonnement' => 'expire'])" />
        </div>
        <div class="col">
            <x-kpi-card stacked :label="'Échéance sous '.$joursEcheanceProche.' jours'" :value="$echeances->count()" icon="bi-calendar-event"
                        :color="$echeances->isNotEmpty() ? 'warning' : 'secondary'"
                        :hint="$echeances->isNotEmpty() ? 'À prévenir avant la coupure' : 'Aucune échéance proche'" />
        </div>
        <div class="col">
            <x-kpi-card stacked label="Suspendues" :value="$indicateurs['suspendues']" icon="bi-lock" color="secondary"
                        hint="Espaces fermés par vos soins"
                        :href="route('plateforme.entreprises.index', ['statut' => 'suspendue'])" />
        </div>
    </div>

    {{-- Deux mesures sans rapport d'échelle (un nombre d'inscriptions, un
         montant) : un graphique chacune, jamais deux axes sur le même. --}}
    <div class="row g-3 mb-3">
        @foreach ([
            ['id' => 'chart-inscriptions', 'titre' => 'Inscriptions', 'valeurs' => $graphique['inscriptions'], 'unite' => '',
                'total' => array_sum($graphique['inscriptions']), 'vide' => 'Aucune inscription sur la période.', 'icone' => 'bi-person-plus'],
            ['id' => 'chart-encaissements', 'titre' => 'Encaissements', 'valeurs' => $graphique['encaisse'], 'unite' => App\Models\Devise::abreviationActuelle(),
                'total' => montant(array_sum($graphique['encaisse'])), 'vide' => 'Aucun encaissement réel sur la période.', 'icone' => 'bi-cash-coin'],
        ] as $courbe)
            <div class="col-12 col-md-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="carte-titre">
                            <div>
                                <h3 class="carte-titre__texte">{{ $courbe['titre'] }}</h3>
                                <p class="carte-titre__aide">Par mois, 6 derniers mois{{ $courbe['unite'] ? ' · hors paiements simulés' : '' }}</p>
                            </div>
                            <dl class="carte-titre__chiffres">
                                <div>
                                    <dt>Total</dt>
                                    <dd>{{ $courbe['total'] }}</dd>
                                </div>
                            </dl>
                        </div>

                        @if (array_sum($courbe['valeurs']) === 0)
                            <div class="etat-vide">
                                <i class="bi {{ $courbe['icone'] }}"></i>
                                <p>{{ $courbe['vide'] }}</p>
                            </div>
                        @else
                            <div class="graphique graphique--court">
                                <canvas id="{{ $courbe['id'] }}" role="img" data-graphique-mensuel
                                        aria-label="{{ $courbe['titre'] }} par mois sur les 6 derniers mois. Total : {{ $courbe['total'] }}."
                                        data-mois='@json($graphique['mois'])'
                                        data-valeurs='@json($courbe['valeurs'])'
                                        data-unite="{{ $courbe['unite'] }}"></canvas>
                            </div>
                            <table class="visually-hidden">
                                <caption>{{ $courbe['titre'] }} par mois</caption>
                                <thead><tr><th>Mois</th><th>{{ $courbe['titre'] }}</th></tr></thead>
                                <tbody>
                                    @foreach ($graphique['mois'] as $i => $libelle)
                                        <tr><td>{{ $libelle }}</td><td>{{ $courbe['valeurs'][$i] }} {{ $courbe['unite'] }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach

        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="carte-titre">
                        <div>
                            <h3 class="carte-titre__texte">Dernières inscriptions</h3>
                            <p class="carte-titre__aide">Les plus récentes d'abord</p>
                        </div>
                    </div>

                    @if ($inscriptions->isEmpty())
                        <div class="etat-vide">
                            <i class="bi bi-buildings"></i>
                            <p>Aucune entreprise pour l'instant.</p>
                        </div>
                    @else
                        <ul class="liste-simple">
                            @foreach ($inscriptions as $entreprise)
                                <li>
                                    <span class="liste-simple__pastille"><i class="bi bi-shop"></i></span>
                                    <div class="liste-simple__texte">
                                        <strong class="text-truncate">{{ $entreprise->nom }}</strong>
                                        <span class="text-truncate">{{ $entreprise->proprietaire_nom }} · {{ telephone($entreprise->telephone) }}</span>
                                    </div>
                                    <span class="liste-simple__fin text-end">
                                        <span class="d-block">{{ $entreprise->created_at->format('d/m/Y') }}</span>
                                        @if ($entreprise->sansFormule())
                                            <span class="badge text-bg-warning">Formule non choisie</span>
                                        @else
                                            <span class="text-secondary">{{ $entreprise->formule_nom }}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        @foreach ([
            ['titre' => 'Échéances dans les '.$joursEcheanceProche.' jours', 'aide' => 'Abonnements qui arrivent à leur terme', 'liste' => $echeances,
                'vide' => 'Aucune échéance proche.', 'ton' => 'warning', 'prefixe' => 'Le '],
            ['titre' => 'Abonnements expirés', 'aide' => "Espaces fermés jusqu'au renouvellement", 'liste' => $expirees,
                'vide' => 'Aucun abonnement expiré.', 'ton' => 'danger', 'prefixe' => 'Depuis le '],
        ] as $bloc)
            <div class="col-12 col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="carte-titre">
                            <div>
                                <h3 class="carte-titre__texte">{{ $bloc['titre'] }}</h3>
                                <p class="carte-titre__aide">{{ $bloc['aide'] }}</p>
                            </div>
                        </div>

                        @if ($bloc['liste']->isEmpty())
                            <div class="etat-vide">
                                <i class="bi bi-check2-circle"></i>
                                <p>{{ $bloc['vide'] }}</p>
                            </div>
                        @else
                            <ul class="liste-simple">
                                @foreach ($bloc['liste'] as $entreprise)
                                    <li>
                                        <span class="liste-simple__pastille"><i class="bi bi-shop"></i></span>
                                        <div class="liste-simple__texte">
                                            <strong class="text-truncate">{{ $entreprise->nom }}</strong>
                                            <span>{{ telephone($entreprise->telephone) }}</span>
                                        </div>
                                        <span class="liste-simple__fin">
                                            <span class="badge text-bg-{{ $bloc['ton'] }}">{{ $bloc['prefixe'] }}{{ $entreprise->abonnement_fin->format('d/m/Y') }}</span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body pb-0">
            <div class="carte-titre mb-3">
                <div>
                    <h3 class="carte-titre__texte">Derniers paiements</h3>
                    <p class="carte-titre__aide">Tous les paiements, simulés compris</p>
                </div>
                <a href="{{ route('plateforme.paiements.index') }}" class="btn btn-sm btn-outline-secondary">Tout voir</a>
            </div>
        </div>
        @include('plateforme._paiements-table', ['paiements' => $paiements])
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Chart.js dessine sur un <canvas> : les variables CSS du thème ne
            // l'atteignent pas toutes seules — on les relit à l'exécution
            // (voir dashboard/_apercu.blade.php).
            const theme = getComputedStyle(document.documentElement);
            const lire = (nom, repli) => (theme.getPropertyValue(nom) || repli).trim();
            const primaire = lire('--bs-primary', '#2563eb');
            const grille = lire('--bs-border-color', '#e4e7ec');
            const texte = lire('--bs-secondary-color', '#5b6779');

            const nombre = new Intl.NumberFormat('fr-FR');
            const compact = new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 });

            document.querySelectorAll('[data-graphique-mensuel]').forEach((el) => {
                const unite = el.dataset.unite;

                new Chart(el, {
                    type: 'bar',
                    data: {
                        labels: JSON.parse(el.dataset.mois),
                        datasets: [{
                            data: JSON.parse(el.dataset.valeurs),
                            backgroundColor: primaire,
                            // Barres fines, arrondies du côté de la valeur.
                            maxBarThickness: 24,
                            borderRadius: { topLeft: 4, topRight: 4 },
                        }],
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                displayColors: false,
                                padding: 10,
                                callbacks: { label: (contexte) => `${nombre.format(contexte.parsed.y)} ${unite}`.trim() },
                            },
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                border: { color: grille },
                                // Chaque mois est libellé, sans l'année (rappelée au survol) :
                                // six libellés complets ne tiendraient pas sous les barres.
                                ticks: { color: texte, maxRotation: 0, autoSkip: false, callback(valeur) { return this.getLabelForValue(valeur).split(' ')[0]; } },
                            },
                            y: {
                                beginAtZero: true,
                                border: { display: false },
                                grid: { color: grille },
                                // Un nombre d'inscriptions se compte en entiers.
                                ticks: { color: texte, maxTicksLimit: 5, precision: unite ? undefined : 0, callback: (valeur) => compact.format(valeur) },
                            },
                        },
                    },
                });
            });
        });
    </script>
@endpush
