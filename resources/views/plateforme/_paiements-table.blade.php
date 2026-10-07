{{-- Tableau des paiements d'abonnement, partagé par le tableau de bord
     (derniers paiements) et le registre complet. --}}
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead>
            <tr>
                <th>Date</th>
                <th>Entreprise</th>
                <th>Formule</th>
                <th>Origine</th>
                <th>Moyen</th>
                <th>Référence</th>
                <th>Statut</th>
                <th class="text-end">Montant</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($paiements as $paiement)
                <tr>
                    <td>{{ $paiement->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $paiement->entreprise->nom }}</td>
                    <td>{{ $paiement->formule_nom }}</td>
                    <td>
                        @if ($paiement->estSimule())
                            <span class="badge text-bg-warning">Simulation</span>
                        @else
                            {{ $paiement->libelleOrigine() }}
                        @endif
                    </td>
                    <td>{{ $paiement->libelleMoyen() }}</td>
                    <td><code>{{ $paiement->reference }}</code></td>
                    <td>
                        @if ($paiement->statut === \App\Models\Plateforme\PaiementAbonnement::REUSSI)
                            <span class="badge text-bg-success">Réussi</span>
                        @else
                            <span class="badge text-bg-secondary">Refusé</span>
                        @endif
                    </td>
                    <td class="text-end {{ $paiement->estSimule() ? 'text-secondary' : '' }}">{{ montant($paiement->montant) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-secondary py-4">Aucun paiement.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
