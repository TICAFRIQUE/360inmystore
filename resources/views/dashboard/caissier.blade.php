@extends('layouts.app')

@section('title', 'Tableau de bord')

@section('content')
    <div class="page-entete">
        <div>
            <h2 class="page-entete__titre">Bonjour, {{ $utilisateur->name }}</h2>
            <p class="page-entete__aide">
                @if ($session)
                    <span class="badge text-bg-success me-1"><i class="bi bi-circle-fill me-1"></i>Session ouverte</span>
                    {{ $session->caisse->nom }} · {{ $session->caisse->magasin->nom }}
                @else
                    {{ ucfirst(now()->translatedFormat('l j F Y')) }}
                @endif
            </p>
        </div>

        @if ($session)
            <div class="page-entete__actions">
                @can('vente.creer')
                    <a href="{{ route('ventes.create', $session) }}" class="btn btn-primary">
                        <i class="bi bi-cart-plus me-2"></i>Vendre
                    </a>
                @endcan
                <a href="{{ route('sessions.show', $session) }}" class="btn btn-outline-secondary">Voir la session</a>
            </div>
        @endif
    </div>

    <x-alerte-abonnement-expire />

    @if (! $session)
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="etat-vide etat-vide--grand">
                    <i class="bi bi-cash-coin"></i>
                    <h3 class="h5">Aucune session ouverte</h3>
                    <p>Ouvrez une session sur une caisse libre de votre magasin pour commencer à vendre.</p>
                    @can('caisse.ouvrir')
                        <a href="{{ route('sessions.index') }}" class="btn btn-primary">Aller aux caisses</a>
                    @endcan
                </div>
            </div>
        </div>
    @else
        <x-alerte-session-ancienne :session="$session" />

        @if ($venteEnAttenteCount > 0)
            <div class="alert alert-warning d-flex justify-content-between align-items-center gap-3">
                <span><i class="bi bi-hourglass-split me-2"></i>{{ $venteEnAttenteCount }} panier{{ $venteEnAttenteCount > 1 ? 's' : '' }} en attente : à finaliser ou à annuler avant de clôturer.</span>
                <a href="{{ route('ventes-en-attente.index', $session) }}" class="btn btn-sm btn-warning flex-shrink-0">Voir</a>
            </div>
        @endif

        <div class="row row-cols-2 row-cols-xl-4 g-3 mb-3">
            <div class="col">
                <x-kpi-card stacked label="Chiffre d'affaires" :value="montant($totalVentes)" icon="bi-receipt" color="primary"
                            hint="Depuis l'ouverture de la session" />
            </div>
            <div class="col">
                <x-kpi-card stacked label="Ventes" :value="$nombreVentes" icon="bi-cart-check" color="primary"
                            :hint="'Depuis '.$session->date_ouverture->format($session->estOuverteDepuisJourPrecedent() ? 'd/m H:i' : 'H:i')" />
            </div>
            <div class="col">
                <x-kpi-card stacked label="Panier moyen" :value="montant($panierMoyen)" icon="bi-basket" color="info"
                            hint="Par vente" />
            </div>
            <div class="col">
                <x-kpi-card stacked label="Ventes en attente" :value="$venteEnAttenteCount" icon="bi-hourglass-split"
                            :color="$venteEnAttenteCount > 0 ? 'warning' : 'secondary'"
                            :hint="$venteEnAttenteCount > 0 ? 'Paniers mis de côté' : 'Aucun panier de côté'"
                            :href="$venteEnAttenteCount > 0 ? route('ventes-en-attente.index', $session) : null" />
            </div>
        </div>

        <div class="row g-3">
            {{-- Ces trois chiffres décomposent le chiffre d'affaires ci-dessus :
                 espèces (réellement dans le tiroir), dû (crédit encore ouvert)
                 et avoir (compensé, jamais encaissé) — volontairement séparés
                 pour ne jamais laisser croire que le CA est de l'argent en
                 caisse. --}}
            <div class="col-12 col-xl-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="carte-titre">
                            <div>
                                <h3 class="carte-titre__texte">D'où vient le chiffre d'affaires</h3>
                                <p class="carte-titre__aide">Ce qui est vendu n'est pas forcément dans le tiroir.</p>
                            </div>
                        </div>

                        <div class="apercu-financier apercu-financier--trois">
                            <div class="apercu-financier__bloc">
                                <span class="apercu-financier__libelle"><i class="bi bi-cash-stack text-success"></i>Total en caisse</span>
                                <span class="apercu-financier__valeur">{{ montant($totalEspeces) }}</span>
                                <span class="apercu-financier__aide">Espèces réellement encaissées.</span>
                            </div>
                            <div class="apercu-financier__bloc">
                                <span class="apercu-financier__libelle">
                                    <i class="bi {{ $totalDu > 0 ? 'bi-exclamation-circle text-warning' : 'bi-people text-secondary' }}"></i>Total dû
                                </span>
                                <span class="apercu-financier__valeur">{{ montant($totalDu) }}</span>
                                <span class="apercu-financier__aide">Ventes à crédit, pas encore réglées.</span>
                            </div>
                            <div class="apercu-financier__bloc">
                                <span class="apercu-financier__libelle"><i class="bi bi-piggy-bank text-info"></i>Avoirs appliqués</span>
                                <span class="apercu-financier__valeur">{{ montant($avoirApplique) }}</span>
                                <span class="apercu-financier__aide">Réglé par un avoir, sans argent encaissé.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-5">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="carte-titre">
                            <div>
                                <h3 class="carte-titre__texte">Encaissements par moyen de paiement</h3>
                                <p class="carte-titre__aide">Ventes et règlements clients de cette session</p>
                            </div>
                        </div>

                        @if ($parMoyen->isEmpty())
                            <div class="etat-vide">
                                <i class="bi bi-credit-card"></i>
                                <p>Aucun encaissement pour l'instant sur cette session.</p>
                            </div>
                        @else
                            <x-liste-barres :lignes="$parMoyen->sortByDesc('total')->map(fn ($moyen) => [
                                'libelle' => $moyen->nom,
                                'valeur' => (int) $moyen->total,
                                'texte' => montant($moyen->total),
                            ])->values()" />
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection
