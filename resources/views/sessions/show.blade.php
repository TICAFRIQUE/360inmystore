@extends('layouts.app')

@section('title', "Session — {$session->caisse->nom}")

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-2 mb-3">
        <div>
            <div class="d-flex align-items-center gap-2">
                <x-bouton-retour :route="route('sessions.index')" />
                <h2 class="h4 mb-0">{{ $session->caisse->nom }} — {{ $session->caisse->magasin->nom }}</h2>
            </div>
            <p class="text-secondary small mb-0 ms-5 ps-1">
                Ouverte par {{ $session->caissier->name }} le {{ $session->date_ouverture->format('d/m/Y à H:i') }}
                @if ($session->date_cloture)
                    <span class="badge text-bg-secondary ms-2">Clôturée</span>
                @else
                    <span class="badge text-bg-success ms-2">Ouverte</span>
                @endif
            </p>
            @can('rapport.voir')
                @if ($sessionsAujourdhui > 0)
                    <p class="small mb-0 ms-5 ps-1">
                        <a href="{{ route('rapports.ventes', ['caisse_id' => $session->caisse_id, 'debut' => $session->date_ouverture->toDateString(), 'fin' => $session->date_ouverture->toDateString()]) }}">
                            <i class="bi bi-clock-history me-1"></i>Voir les autres sessions de cette caisse aujourd'hui ({{ $sessionsAujourdhui }})
                        </a>
                    </p>
                @endif
            @endcan
        </div>

        <div class="d-flex flex-wrap gap-2">
            @if ($session->date_cloture)
                <a href="{{ route('sessions.rapport', $session) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-text me-1"></i>Rapport de caisse
                </a>
            @endif
            @if (! $session->date_cloture)
                @can('ventenattente.gerer')
                    <a href="{{ route('ventes-en-attente.index', $session) }}" class="btn btn-outline-warning position-relative">
                        <i class="bi bi-hourglass-split me-1"></i>Ventes en attente
                        @if ($venteEnAttentesVisibles > 0)
                            <span class="badge rounded-pill text-bg-warning ms-1">{{ $venteEnAttentesVisibles }}</span>
                        @endif
                    </a>
                @endcan
                @can('vente.creer')
                    <a href="{{ route('ventes.create', $session) }}" class="btn btn-primary">
                        <i class="bi bi-cart-plus me-1"></i>Vendre
                    </a>
                @endcan
                @can('client.reglement')
                    <a href="{{ route('reglements.create', $session) }}" class="btn btn-outline-primary">
                        <i class="bi bi-credit-card me-1"></i>Encaisser un règlement
                    </a>
                    {{-- Un remboursement d'avoir exige désormais une session ouverte
                         (règle 20, tiroir de cette session) — visible seulement ici,
                         pas sur une session clôturée où il serait de toute façon
                         impossible à traiter. --}}
                    <a href="{{ route('remboursements-avoir-client.index') }}" class="btn btn-outline-info">
                        <i class="bi bi-piggy-bank me-1"></i>Avoirs clients
                    </a>
                @endcan
                @can('caisse.cloturer')
                    @if ($session->vente_en_attentes_count > 0)
                        <button type="button" class="btn btn-outline-secondary disabled" disabled
                                title="Impossible de clôturer : {{ $session->vente_en_attentes_count }} vente(s) en attente à finaliser ou annuler d'abord.">
                            <i class="bi bi-lock me-1"></i>Clôturer
                        </button>
                    @else
                        <a href="{{ route('sessions.cloturer.form', $session) }}" class="btn btn-outline-secondary">
                            <i class="bi bi-lock me-1"></i>Clôturer
                        </a>
                    @endif
                @endcan
            @elseif (! $session->date_fermeture)
                @can('caisse.fermer')
                    <x-confirm-button :action="route('sessions.fermer', $session)"
                        message="Fermer cette session ? La caisse redeviendra libre pour une nouvelle ouverture."
                        button-label="Fermer la session" button-class="btn-outline-danger" icon="bi-door-closed" />
                @endcan
            @endif
        </div>
    </div>

    {{-- Ligne opérationnelle (fond, nombre de ventes, ventes en attente) suivie
         de la "story" financière de la session, dans l'ordre : ce qui a été
         vendu (brut) → ce qui est reparti en retour/avoir → ce qu'il reste
         réellement dans le tiroir. Une seule ligne, colonnes flexibles
         (col-md sans chiffre) : s'adapte à 6 cartes comme à n'importe quel
         autre nombre. --}}
    <div class="row g-2 mb-2">
        <div class="col-6 col-md">
            <x-kpi-card compact label="Fond de caisse" icon="bi-cash-stack" color="primary"
                :value="montant($session->fond_de_caisse)" />
        </div>
        <div class="col-6 col-md">
            <x-kpi-card compact label="Total ventes" icon="bi-receipt" color="info"
                :value="$session->ventes_count" />
        </div>
        <div class="col-6 col-md">
            <x-kpi-card compact label="Ventes en attente" icon="bi-hourglass-split" color="warning"
                :value="$venteEnAttentesVisibles" />
        </div>
        <div class="col-6 col-md">
            <x-kpi-card compact label="Total vendu" icon="bi-graph-up-arrow" color="success"
                :value="montant($totalVentes)" />
        </div>
        <div class="col-6 col-md position-relative">
            <x-kpi-card compact label="Retour/avoir" icon="bi-arrow-return-left" :color="$totalRetours > 0 ? 'warning' : 'secondary'"
                :value="montant($totalRetours)" />
            @if ($nbRetours > 0)
                <span class="badge rounded-pill text-bg-warning position-absolute top-0 end-0 mt-n1 me-1">{{ $nbRetours }}</span>
            @endif
        </div>
        @if ($soldeTheorique !== null)
            <div class="col-6 col-md">
                {{-- Le seul chiffre "caisse" affiché : ce qu'il devrait réellement
                     y avoir dans le tiroir maintenant (fond + espèces encaissées
                     − sorties, remboursements d'avoir inclus) — celui utilisé à
                     la clôture pour comparer avec le comptage réel. --}}
                <x-kpi-card compact solid label="Total en caisse" icon="bi-wallet2" color="success"
                    :value="montant($soldeTheorique)" />
            </div>
        @endif
    </div>

    {{-- Décomposition (à gauche, cartes réduites) et Répartition par moyen de
         paiement (à droite) sur la même ligne — la décomposition explique
         "Total vendu" ci-dessus (dû, avoir compensé, règlements clients,
         jamais des espèces dans le tiroir, règle 10), la répartition montre
         comment les clients ont réellement payé. --}}
    <div class="row g-2 mb-3 align-items-start">
        <div class="col-md-8">
            <p class="text-secondary small mb-1">Décomposition</p>
            <div class="row g-2">
                <div class="col-4">
                    <x-kpi-card compact label="Total dû (crédit)" icon="bi-credit-card" :color="$totalDu > 0 ? 'warning' : 'secondary'"
                        :value="montant($totalDu)" />
                </div>
                <div class="col-4">
                    <x-kpi-card compact label="Avoirs appliqués" icon="bi-piggy-bank" color="info"
                        :value="montant($avoirApplique)" />
                </div>
                <div class="col-4">
                    <x-kpi-card compact label="Règlements clients" icon="bi-cash-coin" color="primary"
                        :value="montant($totalReglementsClient)" />
                </div>
            </div>
        </div>
        @if ($paiementsParMoyen->isNotEmpty())
            <div class="col-md-4">
                <p class="text-secondary small mb-1">Répartition par moyen de paiement</p>
                <div class="card">
                    <div class="card-body p-2 px-3">
                        <div class="d-flex flex-wrap column-gap-4 row-gap-1">
                            @foreach ($paiementsParMoyen as $paiement)
                                <div>
                                    <div class="text-secondary" style="font-size: .75rem;">{{ $paiement->moyenPaiement->nom }}</div>
                                    <div class="fw-medium" style="font-size: 1.05rem;">{{ montant($paiement->total) }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if ($session->date_cloture)
        <div class="card mb-2">
            <div class="card-body p-2 px-3">
                <div class="text-secondary small mb-1">Clôture</div>
                <div class="d-flex flex-wrap column-gap-4 row-gap-1">
                    <div>
                        <div class="text-secondary" style="font-size: .75rem;">Théorique</div>
                        <div class="fw-medium" style="font-size: 1.05rem;">{{ montant($session->fond_de_caisse + $session->total_ventes_especes + $session->total_reglements_especes + $session->total_entrees_especes - $session->total_sorties_especes) }}</div>
                    </div>
                    @if ($session->total_reglements_especes > 0)
                        <div>
                            <div class="text-secondary" style="font-size: .75rem;">Règlements clients (espèces)</div>
                            <div class="fw-medium" style="font-size: 1.05rem;">{{ montant($session->total_reglements_especes) }}</div>
                        </div>
                    @endif
                    @if ($session->total_entrees_especes > 0)
                        <div>
                            <div class="text-secondary" style="font-size: .75rem;">Entrées de caisse</div>
                            <div class="fw-medium" style="font-size: 1.05rem;">{{ montant($session->total_entrees_especes) }}</div>
                        </div>
                    @endif
                    @if ($session->total_sorties_especes > 0)
                        <div>
                            <div class="text-secondary" style="font-size: .75rem;">Sorties de caisse</div>
                            <div class="fw-medium" style="font-size: 1.05rem;">− {{ montant($session->total_sorties_especes) }}</div>
                        </div>
                    @endif
                    <div>
                        <div class="text-secondary" style="font-size: .75rem;">Compté</div>
                        <div class="fw-medium" style="font-size: 1.05rem;">{{ montant($session->montant_compte) }}</div>
                    </div>
                    <div>
                        <div class="text-secondary" style="font-size: .75rem;">Écart</div>
                        <div class="fw-medium {{ $session->ecart === 0 ? '' : ($session->ecart > 0 ? 'text-success' : 'text-danger') }}" style="font-size: 1.05rem;">
                            {{ $session->ecart > 0 ? '+' : '' }}{{ montant($session->ecart) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($peutMouvementer && ! $session->date_cloture)
        <div class="card mb-2 shadow-sm" x-data="{ ouvert: false, ajoutMotifOuvert: false }">
            <div class="card-body p-2 px-3">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <div class="d-flex align-items-center gap-2 fw-semibold">
                            <i class="bi bi-arrow-left-right me-1"></i>Mouvement de caisse
                        </div>
                        <div class="small text-secondary">
                            Entrée (appoint…) ou sortie (paiement fournisseur en espèces, dépense diverse…) du tiroir.
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0" @click="ouvert = !ouvert" x-show="!ouvert">
                        <i class="bi bi-plus-lg me-1"></i>Enregistrer
                    </button>
                </div>

                <form method="POST" action="{{ route('sessions.mouvements.store', $session) }}" x-show="ouvert" x-cloak class="mt-2">
                    @csrf
                    <div class="row g-2 align-items-end">
                        <div class="col-6 col-md-3">
                            <label for="type" class="form-label small mb-1">Type</label>
                            <select name="type" id="type" class="form-select form-select-sm" required>
                                <option value="sortie">Sortie</option>
                                <option value="entree">Entrée</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="montant" class="form-label small mb-1">Montant ({{ App\Models\Devise::abreviationActuelle() }})</label>
                            <input type="number" name="montant" id="montant" min="1" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="motif" class="form-label small mb-1">Motif</label>
                            <div class="d-flex gap-1">
                                <select name="motif" id="motif" x-ref="motifSelect" class="form-select form-select-sm" required>
                                    <option value="">— Choisir —</option>
                                    @foreach ($motifs as $m)
                                        <option value="{{ $m->nom }}">{{ $m->nom }}</option>
                                    @endforeach
                                </select>
                                <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0" title="Nouveau motif"
                                        @click="ajoutMotifOuvert = !ajoutMotifOuvert">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                            <div class="input-group input-group-sm mt-1" x-show="ajoutMotifOuvert" x-cloak>
                                <input type="text" x-ref="motifNouveau" class="form-control" placeholder="Nouveau motif…" maxlength="255"
                                       @keydown.enter.prevent="window.ajouterMotifRapide($refs.motifSelect, $refs.motifNouveau, () => ajoutMotifOuvert = false)">
                                <button type="button" class="btn btn-outline-primary"
                                        @click="window.ajouterMotifRapide($refs.motifSelect, $refs.motifNouveau, () => ajoutMotifOuvert = false)">
                                    Ajouter
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Enregistrer le mouvement</button>
                        <button type="button" class="btn btn-link btn-sm" @click="ouvert = false">Fermer</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <x-recherche-form :action="route('sessions.show', $session)" placeholder="Numéro de vente…" />

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <x-th-tri champ="numero" label="Numéro" />
                        <th>Type</th>
                        <x-th-tri champ="created_at" label="Date et heure" />
                        <x-th-tri champ="total_net" label="Montant dû" />
                        <th class="text-end">Déjà réglé</th>
                        <th class="text-end">Avoir appliqué</th>
                        <th class="text-end">Reste à payer</th>
                        <th>Statut</th>
                        <th>Livraison</th>
                        <th>Retours</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ventes as $vente)
                        <tr>
                            <td><code>{{ $vente->numero }}</code></td>
                            <td><span class="badge {{ $vente->type->classeBadge() }}">{{ $vente->type->libelle() }}</span></td>
                            <td>{{ $vente->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ montant($vente->total_net) }}</td>
                            <td class="text-end text-success">{{ montant($vente->montantRegle()) }}</td>
                            <td class="text-end text-secondary">{{ $vente->avoir_applique > 0 ? montant($vente->avoir_applique) : '—' }}</td>
                            <td class="text-end {{ $vente->soldeDuReel() > 0 ? 'text-danger fw-medium' : 'text-secondary' }}">{{ montant($vente->soldeDuReel()) }}</td>
                            <td><span class="badge text-bg-success">Validée</span></td>
                            <td>
                                @if ($vente->livraisonEngagee())
                                    <span class="badge {{ $vente->entierementLivree() ? 'text-bg-success-subtle text-success-emphasis' : 'text-bg-warning-subtle text-warning-emphasis' }}">
                                        {{ quantite($vente->quantiteLivreePieces()) }}/{{ quantite($vente->lignes->sum('quantite_pieces')) }}
                                    </span>
                                @else
                                    <span class="text-secondary">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($vente->retours->isNotEmpty())
                                    <span class="badge text-bg-warning-subtle text-warning-emphasis">
                                        {{ $vente->retours->count() }} · {{ montant($vente->retours->sum('montant_total')) }}
                                    </span>
                                @else
                                    <span class="text-secondary">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('ventes.ticket', $vente) }}" class="btn btn-sm btn-icon btn-outline-secondary" title="Détail de la vente">
                                    <i class="bi bi-eye"></i>
                                    <span class="visually-hidden">Détail</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="text-center text-secondary py-4">Aucune vente pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $ventes->links() }}
    </div>

    {{-- Tableau distinct de la liste des ventes ci-dessus : mouvements
         manuels (entrée/sortie) ET remboursements d'avoir client générés
         depuis un retour (règle 20) — jamais mélangés aux ventes. --}}
    @if ($session->mouvementCaisses->isNotEmpty())
        <p class="fw-semibold mb-2 mt-4">Historique des mouvements de caisse</p>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Motif</th>
                            <th>Auteur</th>
                            <th>Date</th>
                            <th class="text-end">Montant</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($session->mouvementCaisses as $mouvement)
                            <tr>
                                <td>
                                    <span class="badge {{ $mouvement->type->classeBadge() }}">
                                        {{ $mouvement->reference_type === \App\Models\RemboursementAvoirClient::class ? 'Remboursement avoir' : $mouvement->type->libelle() }}
                                    </span>
                                </td>
                                <td>{{ $mouvement->motif }}</td>
                                <td>{{ $mouvement->auteur->name ?? 'utilisateur supprimé' }}</td>
                                <td>{{ $mouvement->created_at->format('d/m/Y H:i') }}</td>
                                <td class="text-end fw-medium">{{ $mouvement->type->value === 'entree' ? '+ ' : '− ' }}{{ montant($mouvement->montant) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="mt-3">
        <a href="{{ route('sessions.index') }}" class="btn btn-link ps-0">Retour aux caisses</a>
    </div>
@endsection
