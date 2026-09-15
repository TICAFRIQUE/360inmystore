@extends('layouts.app')

@section('title', 'Retours')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Retours</h2>
    </div>

    <p class="text-secondary small mb-3">
        Factures du jour ayant encore au moins un article retournable (comptant comme
        crédit) — un retour n'est possible que le jour même de la vente. Ouvrez une
        facture pour y enregistrer le retour, comme depuis sa fiche.
    </p>

    @unless ($sessionOuverte)
        <div class="alert alert-warning d-flex align-items-center justify-content-between">
            <span><i class="bi bi-exclamation-triangle me-1"></i>Aucune session de caisse ouverte : impossible d'enregistrer un retour, ouvrez-en une pour continuer.</span>
            <a href="{{ route('sessions.index') }}" class="btn btn-sm btn-outline-secondary flex-shrink-0 ms-2">Ouvrir une caisse</a>
        </div>
    @endunless

    <x-recherche-form :action="route('retours.rechercher')" placeholder="Numéro de facture…" />

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Numéro</th>
                        <th>Client</th>
                        <th>Date</th>
                        <th class="text-end">Retourné</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ventes as $vente)
                        @php
                            $venduPieces = $vente->lignes->sum('quantite_pieces');
                            $retournePieces = $vente->retours->flatMap->lignes->sum('quantite_pieces');
                        @endphp
                        <tr>
                            <td><code>{{ $vente->numero }}</code></td>
                            <td>
                                {{ $vente->client->nom ?? 'Comptant' }}
                                @if (! $vente->client && $vente->retours->isNotEmpty())
                                    <div class="small text-secondary">
                                        Avoir crédité à
                                        {{ $vente->retours->pluck('client.nom')->unique()->join(', ') }}
                                    </div>
                                @endif
                            </td>
                            <td>{{ $vente->created_at->format('d/m/Y H:i') }}</td>
                            <td class="text-end">
                                <span class="{{ $retournePieces > 0 ? 'text-warning-emphasis fw-medium' : 'text-secondary' }}">
                                    {{ quantite($retournePieces) }}/{{ quantite($venduPieces) }}
                                </span>
                            </td>
                            <td class="text-end">{{ montant($vente->total_net) }}</td>
                            <td class="text-end">
                                <a href="{{ route('ventes.ticket', $vente) }}" class="btn btn-sm btn-outline-info">
                                    <i class="bi bi-arrow-return-left me-1"></i>Faire le retour
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">Aucune facture retournable pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $ventes->links() }}
    </div>
@endsection
