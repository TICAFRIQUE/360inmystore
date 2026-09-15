@extends('layouts.app')

@section('title', "Rapport de caisse — {$session->caisse->nom}")

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <div class="d-flex align-items-center gap-2">
            <x-bouton-retour :route="route('sessions.show', $session)" />
            <h2 class="h4 mb-0">Rapport de caisse</h2>
        </div>
        <div class="d-flex gap-2">
            <x-export-buttons :pdf-route="route('sessions.rapport.pdf', $session)" :excel-route="route('sessions.rapport.excel', $session)" />
        </div>
    </div>

    {{-- Présentation "fiche" — même esprit que le PDF exporté
         (resources/views/exports/liste.blade.php) : titre en accent, une
         liste de ventes, puis un bilan sous forme de tableau bordé, jamais
         de nouveau calcul (voir SessionCaisseController::bilanRapportSession(),
         réutilisée telle quelle par le PDF/Excel). --}}
    <div class="card">
        <div class="card-body">
            <div class="mb-3">
                <div class="fw-bold fs-4 text-primary">{{ $session->caisse->nom }} — {{ $session->caisse->magasin->nom }}</div>
                <div class="text-secondary small">
                    Caissier : {{ $session->caissier->name }} — Ouverte le {{ $session->date_ouverture->format('d/m/Y à H:i') }}
                    @if ($session->date_cloture)
                        — Clôturée le {{ $session->date_cloture->format('d/m/Y à H:i') }}
                        @if ($session->clotureePar)
                            par {{ $session->clotureePar->name }}
                        @endif
                    @else
                        <span class="badge text-bg-success ms-1">Session en cours</span>
                    @endif
                </div>
            </div>

            <hr>

            @if ($reglements->isNotEmpty())
                <h3 class="h6">Règlements clients</h3>
                <div class="table-responsive mb-4">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Facture</th>
                                <th>Date et heure</th>
                                <th>Moyen(s) de paiement</th>
                                <th class="text-end">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reglements as $reglement)
                                <tr>
                                    <td>{{ $reglement->client->nom }}</td>
                                    <td>
                                        @if ($reglement->vente)
                                            <code>{{ $reglement->vente->numero }}</code>
                                        @else
                                            <span class="text-secondary fst-italic">Dette globale</span>
                                        @endif
                                    </td>
                                    <td>{{ $reglement->created_at->format('d/m/Y H:i') }}</td>
                                    <td>{{ $reglement->paiements->pluck('moyenPaiement.nom')->implode(', ') }}</td>
                                    <td class="text-end">{{ montant($reglement->montant) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <h3 class="h6">Liste des ventes</h3>
            <div class="table-responsive mb-4">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Numéro</th>
                            <th>Date et heure</th>
                            <th class="text-end">Montant dû</th>
                            <th class="text-end">Déjà réglé</th>
                            <th class="text-end">Avoir appliqué</th>
                            <th class="text-end">Reste à payer</th>
                            <th>Livraison</th>
                            <th>Retours</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ventes as $vente)
                            <tr>
                                <td><code>{{ $vente->numero }}</code></td>
                                <td>{{ $vente->created_at->format('d/m/Y H:i') }}</td>
                                <td class="text-end">{{ montant($vente->total_net) }}</td>
                                <td class="text-end text-success">{{ montant($vente->montantRegle()) }}</td>
                                <td class="text-end text-secondary">{{ $vente->avoir_applique > 0 ? montant($vente->avoir_applique) : '—' }}</td>
                                <td class="text-end {{ $vente->soldeDuReel() > 0 ? 'text-danger fw-medium' : 'text-secondary' }}">{{ montant($vente->soldeDuReel()) }}</td>
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
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-secondary py-3">Aucune vente pour l'instant.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($session->mouvementCaisses->isNotEmpty())
                <h3 class="h6">Historique des mouvements de caisse</h3>
                <div class="table-responsive mb-4">
                    <table class="table table-sm align-middle mb-0">
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
            @endif

            <h3 class="h6">Bilan</h3>
            <table class="table table-sm table-bordered mb-0 ms-md-auto" style="max-width: 360px;">
                <tbody>
                    @foreach ($bilan as $label => $valeur)
                        <tr class="{{ $label === 'Total en caisse' ? 'table-success' : '' }}">
                            <td class="{{ $label === 'Total en caisse' ? 'fw-semibold' : 'text-secondary' }}">{{ $label }}</td>
                            <td class="text-end fw-semibold">{{ $valeur }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
