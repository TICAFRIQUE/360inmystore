@extends('layouts.app')

@section('title', 'Moyens de paiement')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Moyens de paiement</h2>
        <a href="{{ route('moyens-paiement.create') }}" class="btn btn-primary">Nouveau moyen de paiement</a>
    </div>

    <div class="d-flex justify-content-end mb-3">
        <x-export-buttons :pdf-route="route('moyens-paiement.pdf', request()->query())" :excel-route="route('moyens-paiement.excel', request()->query())" :tout="true" />
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Statut</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($moyensPaiement as $moyenPaiement)
                        <tr>
                            <td>
                                {{ $moyenPaiement->nom }}
                                @if ($moyenPaiement->est_espece)
                                    <span class="badge text-bg-info ms-1">Espèces (tiroir)</span>
                                @endif
                            </td>
                            <td>
                                @if ($moyenPaiement->actif)
                                    <span class="badge text-bg-success">Actif</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @unless ($moyenPaiement->est_espece)
                                    <x-edit-button :href="route('moyens-paiement.edit', $moyenPaiement)" />
                                    <x-delete-button :action="route('moyens-paiement.destroy', $moyenPaiement)" :label="'le moyen de paiement « '.$moyenPaiement->nom.' »'" />
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-secondary py-4">Aucun moyen de paiement.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
