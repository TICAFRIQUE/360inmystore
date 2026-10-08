{{-- Partagé entre dashboard.gerant et dashboard.superadmin (voir
     DashboardController::donneesMagasin). Lecture de haut en bas : ce qui se
     vend (jour, semaine, mois), la tendance, où est l'argent du mois, ce qui
     demande une action (stock, caisses, dettes), puis le détail. --}}
@php
    $peut = fn (string $permission) => auth()->user()->can($permission);

    $totalPeriode = array_sum($evolutionVentes['valeurs']);
    $meilleur = $totalPeriode > 0 ? max($evolutionVentes['valeurs']) : 0;
    $meilleurJour = $totalPeriode > 0 ? $evolutionVentes['labels'][array_search($meilleur, $evolutionVentes['valeurs'])] : null;

    $totalMoyens = max(1, $repartitionMoyens->sum('total'));

    $aideJour = $nombreVentesJour.' vente'.($nombreVentesJour > 1 ? 's' : '')." aujourd'hui";
@endphp

<x-alerte-abonnement-expire />
<x-alerte-sessions-anciennes :sessions="$sessionsAnciennes" />

@include('dashboard._premiers-pas')

{{-- Ventes --}}
<div class="row row-cols-2 row-cols-xl-4 g-3 mb-3">
    <div class="col">
        <x-kpi-card stacked label="Ventes du jour" :value="montant($caJour)" icon="bi-calendar-day" color="primary"
                    :hint="$aideJour" />
    </div>
    <div class="col">
        <x-kpi-card stacked label="Ventes de la semaine" :value="montant($caSemaine)" icon="bi-calendar-week" color="primary"
                    :trend="$evolutionSemaine" trend-label="vs semaine dernière" hint="Depuis lundi" />
    </div>
    <div class="col">
        <x-kpi-card stacked label="Ventes du mois" :value="montant($caMois)" icon="bi-calendar3" color="primary"
                    :trend="$evolutionMois" trend-label="vs mois dernier" hint="Depuis le 1er du mois" />
    </div>
    <div class="col">
        <x-kpi-card stacked label="Panier moyen" :value="montant($panierMoyenMois)" icon="bi-basket" color="info"
                    :hint="$nombreVentesMois.' vente'.($nombreVentesMois > 1 ? 's' : '').' ce mois'" />
    </div>
</div>

{{-- Période d'analyse : elle porte sur la courbe, les moyens de paiement,
     l'aperçu financier et les meilleures ventes (chacun la rappelle dans son
     sous-titre) — pas sur les chiffres du haut, qui ont chacun la leur. Un
     simple lien par période : la page se recharge avec ?periode=… --}}
<div class="filtre-periode">
    <span class="filtre-periode__libelle"><i class="bi bi-calendar-range me-2"></i>Période d'analyse</span>
    <div class="filtre-periode__choix" role="group" aria-label="Période d'analyse">
        @foreach ($periodes as $cle => $libelle)
            <a href="{{ route('dashboard', ['periode' => $cle]) }}" @class(['btn btn-sm', 'btn-primary' => $periode['cle'] === $cle, 'btn-outline-secondary' => $periode['cle'] !== $cle])
               @if ($periode['cle'] === $cle) aria-current="true" @endif>{{ $libelle }}</a>
        @endforeach
    </div>
</div>

{{-- Tendance et moyens de paiement --}}
<div class="row g-3 mb-3">
    <div class="col-12 col-xl-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="carte-titre">
                    <div>
                        <h3 class="carte-titre__texte">Évolution des ventes</h3>
                        <p class="carte-titre__aide">{{ $periode['libelle'] }}, par {{ $evolutionVentes['unite'] }}</p>
                    </div>
                    <dl class="carte-titre__chiffres">
                        <div>
                            <dt>Total</dt>
                            <dd>{{ montant($totalPeriode) }}</dd>
                        </div>
                        <div>
                            <dt>Moyenne par {{ $evolutionVentes['unite'] }}</dt>
                            <dd>{{ montant((int) round($totalPeriode / max(1, count($evolutionVentes['valeurs'])))) }}</dd>
                        </div>
                        @if ($meilleurJour)
                            <div>
                                <dt>{{ $evolutionVentes['unite'] === 'heure' ? 'Meilleure heure' : 'Meilleur jour' }}</dt>
                                <dd>{{ $meilleurJour }} · {{ montant($meilleur) }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>

                @if ($totalPeriode === 0)
                    <div class="etat-vide">
                        <i class="bi bi-graph-up"></i>
                        <p>Aucune vente sur cette période. La courbe apparaîtra dès la première vente.</p>
                    </div>
                @else
                    <div class="graphique">
                        <canvas id="chart-evolution" role="img"
                                aria-label="Courbe des ventes par {{ $evolutionVentes['unite'] }}, {{ $periode['libelle'] }}. Total : {{ montant($totalPeriode) }}."
                                data-labels="{{ json_encode($evolutionVentes['labels']) }}"
                                data-valeurs="{{ json_encode($evolutionVentes['valeurs']) }}"
                                data-devise="{{ App\Models\Devise::abreviationActuelle() }}"></canvas>
                    </div>
                    {{-- Les mêmes chiffres, pour qui ne voit pas la courbe. --}}
                    <table class="visually-hidden">
                        <caption>Ventes par {{ $evolutionVentes['unite'] }}, {{ $periode['libelle'] }}</caption>
                        <thead><tr><th>{{ ucfirst($evolutionVentes['unite']) }}</th><th>Ventes</th></tr></thead>
                        <tbody>
                            @foreach ($evolutionVentes['labels'] as $i => $jour)
                                <tr><td>{{ $jour }}</td><td>{{ montant($evolutionVentes['valeurs'][$i]) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="carte-titre">
                    <div>
                        <h3 class="carte-titre__texte">Moyens de paiement</h3>
                        <p class="carte-titre__aide">{{ $periode['libelle'] }} · ventes et règlements clients</p>
                    </div>
                </div>

                @if ($repartitionMoyens->isEmpty())
                    <div class="etat-vide">
                        <i class="bi bi-credit-card"></i>
                        <p>Aucun encaissement sur cette période.</p>
                    </div>
                @else
                    <x-liste-barres :lignes="$repartitionMoyens->sortByDesc('total')->map(fn ($moyen) => [
                        'libelle' => $moyen->nom,
                        'valeur' => (int) $moyen->total,
                        'texte' => montant($moyen->total),
                        'detail' => round($moyen->total / $totalMoyens * 100).' %',
                    ])->values()" />

                    <div class="carte-total">
                        <span>Total encaissé</span>
                        <strong>{{ montant($repartitionMoyens->sum('total')) }}</strong>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Aperçu financier : chacun de ces 4 chiffres mesure une chose différente
     du même mois (facturé, réellement en caisse, dû, compensé par avoir) —
     côte à côte dans une même carte pour qu'on voie d'un coup d'œil qu'ils
     ne se recoupent pas (le CA n'est pas de l'argent en caisse, un avoir non
     plus, voir CLAUDE.md règle 10). --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="carte-titre">
            <div>
                <h3 class="carte-titre__texte">Aperçu financier</h3>
                <p class="carte-titre__aide">{{ $periode['libelle'] }} · quatre chiffres à ne pas confondre : ce qui est vendu n'est pas forcément encaissé.</p>
            </div>
        </div>

        <div class="apercu-financier">
            <div class="apercu-financier__bloc">
                <span class="apercu-financier__libelle"><i class="bi bi-receipt text-primary"></i>Total ventes</span>
                <span class="apercu-financier__valeur">{{ montant($caPeriode) }}</span>
                <span class="apercu-financier__aide">Tout ce qui a été facturé, crédit et avoirs compris.</span>
            </div>
            <div class="apercu-financier__bloc">
                <span class="apercu-financier__libelle"><i class="bi bi-cash-stack text-success"></i>Total en caisse</span>
                <span class="apercu-financier__valeur">{{ montant($totalEspecesPeriode) }}</span>
                <span class="apercu-financier__aide">Espèces réellement encaissées, ventes et règlements.</span>
            </div>
            <div class="apercu-financier__bloc">
                <span class="apercu-financier__libelle">
                    <i class="bi {{ $creancesClients > 0 ? 'bi-exclamation-circle text-warning' : 'bi-people text-secondary' }}"></i>Total dû par les clients
                </span>
                <span class="apercu-financier__valeur">{{ montant($creancesClients) }}</span>
                <span class="apercu-financier__aide">
                    Créances en cours à ce jour, tous magasins.
                    @if ($creancesClients > 0 && $peut('client.voir'))
                        <a href="{{ route('clients.index') }}">Voir les clients</a>
                    @endif
                </span>
            </div>
            <div class="apercu-financier__bloc">
                <span class="apercu-financier__libelle"><i class="bi bi-piggy-bank text-info"></i>Avoirs appliqués</span>
                <span class="apercu-financier__valeur">{{ montant($avoirAppliquePeriode) }}</span>
                <span class="apercu-financier__aide">Part des ventes réglée par un avoir, sans argent encaissé.</span>
            </div>
        </div>
    </div>
</div>

{{-- À surveiller --}}
<div class="row row-cols-2 row-cols-xl-4 g-3 mb-3">
    <div class="col">
        <x-kpi-card stacked label="Produits sous le seuil" :value="$produitsSousSeuilCount" icon="bi-exclamation-triangle"
                    :color="$produitsSousSeuilCount > 0 ? 'danger' : 'success'"
                    :hint="$produitsSousSeuilCount > 0 ? 'À réapprovisionner' : 'Aucune alerte de stock'"
                    :href="$peut('stock.voir') ? route('stock.index') : null" />
    </div>
    <div class="col">
        <x-kpi-card stacked label="Caisses ouvertes" :value="$caissesOuvertes->count()" icon="bi-cash-coin" color="primary"
                    :hint="$caissesOuvertes->isEmpty() ? 'Aucune session en cours' : 'Session'.($caissesOuvertes->count() > 1 ? 's' : '').' en cours'"
                    :href="$peut('caisse.ouvrir') ? route('sessions.index') : null" />
    </div>
    <div class="col">
        <x-kpi-card stacked label="Valeur du stock" :value="montant($valeurStock)" icon="bi-boxes" color="info"
                    hint="Au coût moyen pondéré"
                    :href="$peut('stock.voir') ? route('stock.index') : null" />
    </div>
    <div class="col">
        <x-kpi-card stacked label="Dette fournisseurs" :value="montant($detteFournisseurs)" icon="bi-truck"
                    :color="$detteFournisseurs > 0 ? 'warning' : 'secondary'"
                    :hint="$detteFournisseurs > 0 ? 'Reste à payer, tous fournisseurs' : 'Rien à payer'"
                    :href="$peut('fournisseur.voir') ? route('fournisseurs.index') : null" />
    </div>
</div>

{{-- Produits --}}
<div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="carte-titre">
                    <div>
                        <h3 class="carte-titre__texte">Meilleures ventes</h3>
                        <p class="carte-titre__aide">{{ $periode['libelle'] }}, par montant vendu</p>
                    </div>
                </div>

                @if ($topProduits->isEmpty())
                    <div class="etat-vide">
                        <i class="bi bi-trophy"></i>
                        <p>Aucune vente sur cette période.</p>
                    </div>
                @else
                    <x-liste-barres rang :lignes="$topProduits->map(fn ($produit) => [
                        'libelle' => $produit->libelle_distinctif ? $produit->nom.' — '.$produit->libelle_distinctif : $produit->nom,
                        'valeur' => (int) $produit->total,
                        'texte' => montant($produit->total),
                        'detail' => '× '.quantite($produit->pieces_vendues),
                    ])" />
                @endif
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="carte-titre">
                    <div>
                        <h3 class="carte-titre__texte">Stock à réapprovisionner</h3>
                        <p class="carte-titre__aide">Les plus bas d'abord</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        @if ($produitsSousSeuilCount > $produitsSousSeuil->count() && $peut('stock.voir'))
                            <a href="{{ route('stock.index') }}" class="btn btn-sm btn-outline-secondary">Voir les {{ $produitsSousSeuilCount }}</a>
                        @endif
                        {{-- Réapprovisionnement en un clic : un bon de commande
                             prérempli avec tout ce qui est sous son seuil. --}}
                        @if ($produitsSousSeuilCount > 0 && $peut('achat.creer'))
                            <a href="{{ route('commande-achats.create', ['reappro' => 1]) }}" class="btn btn-sm btn-primary">
                                <i class="bi bi-cart-plus me-1"></i>Commander
                            </a>
                        @endif
                    </div>
                </div>

                @if ($produitsSousSeuil->isEmpty())
                    <div class="etat-vide">
                        <i class="bi bi-check2-circle"></i>
                        <p>Aucun produit sous son seuil d'alerte.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Produit</th>
                                    <th>Magasin</th>
                                    <th class="text-end">Stock</th>
                                    <th class="text-end">Seuil</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($produitsSousSeuil as $stock)
                                    <tr>
                                        <td>{{ $stock->produit->libelle_affichage }}</td>
                                        <td class="text-secondary">{{ $stock->magasin->nom }}</td>
                                        <td class="text-end">
                                            @if ($stock->quantite <= 0)
                                                <span class="badge text-bg-danger">Rupture</span>
                                            @else
                                                <span class="fw-medium text-danger">{{ quantite($stock->quantite) }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end text-secondary">{{ $stock->produit->seuil_alerte }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Caisses --}}
<div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="carte-titre">
                    <div>
                        <h3 class="carte-titre__texte">Caisses ouvertes</h3>
                        <p class="carte-titre__aide">Sessions en cours</p>
                    </div>
                </div>

                @if ($caissesOuvertes->isEmpty())
                    <div class="etat-vide">
                        <i class="bi bi-cash-coin"></i>
                        <p>Aucune caisse ouverte pour le moment.</p>
                    </div>
                @else
                    <ul class="liste-simple">
                        @foreach ($caissesOuvertes as $caisse)
                            @php $sessionOuverte = $caisse->sessionCaisses->first(); @endphp
                            <li>
                                <span class="liste-simple__pastille"><i class="bi bi-cash-coin"></i></span>
                                <div class="liste-simple__texte">
                                    <strong>{{ $caisse->nom }}</strong>
                                    <span>{{ $caisse->magasin->nom }} · {{ $sessionOuverte->caissier->name }}</span>
                                </div>
                                <span class="liste-simple__fin">
                                    @if ($sessionOuverte->estOuverteDepuisJourPrecedent())
                                        <span class="badge text-bg-danger">Depuis le {{ $sessionOuverte->date_ouverture->format('d/m') }}</span>
                                    @else
                                        <span class="text-secondary">Depuis {{ $sessionOuverte->date_ouverture->format('H:i') }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="carte-titre">
                    <div>
                        <h3 class="carte-titre__texte">Écarts de caisse récents</h3>
                        <p class="carte-titre__aide">Différence entre le montant compté et le montant attendu à la clôture</p>
                    </div>
                </div>

                @if ($ecartsCaisse->isEmpty())
                    <div class="etat-vide">
                        <i class="bi bi-check2-circle"></i>
                        <p>Aucun écart sur les dernières clôtures.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Caisse</th>
                                    <th>Caissier</th>
                                    <th>Clôturée le</th>
                                    <th class="text-end">Écart</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ecartsCaisse as $s)
                                    <tr>
                                        <td>{{ $s->caisse->nom }}</td>
                                        <td class="text-secondary">{{ $s->caissier->name }}</td>
                                        <td class="text-secondary">{{ $s->date_cloture->format('d/m/Y H:i') }}</td>
                                        <td class="text-end fw-medium {{ $s->ecart > 0 ? 'text-success' : 'text-danger' }}">
                                            {{ $s->ecart > 0 ? '+' : '' }}{{ montant($s->ecart) }}
                                            <span class="d-block small fw-normal text-secondary">{{ $s->ecart > 0 ? 'Excédent' : 'Manquant' }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@if ($mouvementsCaisseJour->isNotEmpty())
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="carte-titre">
                <div>
                    <h3 class="carte-titre__texte">Mouvements de caisse aujourd'hui</h3>
                    <p class="carte-titre__aide">Entrées et sorties manuelles, regroupées par motif</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Motif</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($mouvementsCaisseJour as $ligne)
                            <tr>
                                <td><span class="badge {{ $ligne->type->classeBadge() }}">{{ $ligne->type->libelle() }}</span></td>
                                <td>{{ $ligne->motif }}</td>
                                <td class="text-end fw-medium">{{ montant($ligne->total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const evolutionEl = document.getElementById('chart-evolution');
            if (! evolutionEl) return;

            // Chart.js dessine sur un <canvas> : les variables CSS du thème
            // (voir partials/couleur-primaire) ne l'atteignent jamais toutes
            // seules — on les relit à l'exécution pour rester cohérent avec
            // la couleur primaire configurée en Paramètres.
            const theme = getComputedStyle(document.documentElement);
            const lire = (nom, repli) => (theme.getPropertyValue(nom) || repli).trim();
            const primaire = lire('--bs-primary', '#2563eb');
            const primaireRvb = lire('--bs-primary-rgb', '37, 99, 235');
            const grille = lire('--bs-border-color', '#e4e7ec');
            const texte = lire('--bs-secondary-color', '#5b6779');
            const devise = evolutionEl.dataset.devise;

            const nombre = new Intl.NumberFormat('fr-FR');
            const compact = new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 });

            new Chart(evolutionEl, {
                type: 'line',
                data: {
                    labels: JSON.parse(evolutionEl.dataset.labels),
                    datasets: [{
                        label: 'Ventes',
                        data: JSON.parse(evolutionEl.dataset.valeurs),
                        borderColor: primaire,
                        borderWidth: 2,
                        backgroundColor: `rgba(${primaireRvb}, 0.1)`,
                        fill: true,
                        // Courbe lissée sans jamais dépasser les valeurs réelles
                        // (un lissage ordinaire creuse sous le zéro ou invente
                        // des pics entre deux jours).
                        cubicInterpolationMode: 'monotone',
                        // Points masqués au repos (la courbe suffit), révélés
                        // au survol avec un anneau blanc pour rester lisibles.
                        // … sauf quand la période n'a qu'un ou deux relevés : une
                        // courbe aussi courte ne se verrait pas sans ses points.
                        pointRadius: JSON.parse(evolutionEl.dataset.valeurs).length < 3 ? 4 : 0,
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: primaire,
                        pointHoverBorderColor: '#fff',
                        pointHoverBorderWidth: 2,
                    }],
                },
                options: {
                    maintainAspectRatio: false,
                    // Le survol vise un jour, pas la courbe : le point le plus
                    // proche en abscisse répond, où que soit le pointeur.
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            displayColors: false,
                            padding: 10,
                            callbacks: { label: (contexte) => `${nombre.format(contexte.parsed.y)} ${devise}` },
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            border: { color: grille },
                            ticks: { color: texte, maxRotation: 0, autoSkipPadding: 12 },
                        },
                        y: {
                            beginAtZero: true,
                            border: { display: false },
                            grid: { color: grille },
                            ticks: { color: texte, maxTicksLimit: 5, callback: (valeur) => compact.format(valeur) },
                        },
                    },
                },
            });
        });
    </script>
@endpush
