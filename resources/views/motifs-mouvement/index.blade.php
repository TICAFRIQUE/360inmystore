@extends('layouts.app')

@section('title', 'Motifs de mouvement')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Motifs de mouvement</h2>
        <a href="{{ route('motifs-mouvement.create') }}" class="btn btn-primary">Nouveau motif</a>
    </div>
    <p class="text-secondary small mb-3">
        Libellés proposés dans le formulaire d'entrée/sortie d'une caisse ou d'un compte de trésorerie —
        un motif reste un texte libre à la saisie (règle 19), ce référentiel ne fait qu'harmoniser les libellés déjà utilisés.
    </p>

    <x-recherche-form :action="route('motifs-mouvement.index')" placeholder="Rechercher un motif…">
        <x-slot:export>
            <x-export-buttons :pdf-route="route('motifs-mouvement.pdf', request()->query())" :excel-route="route('motifs-mouvement.excel', request()->query())" :tout="true" />
        </x-slot:export>
    </x-recherche-form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <x-th-tri champ="nom" label="Nom" />
                        <x-th-tri champ="actif" label="Statut" />
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($motifs as $motif)
                        <tr>
                            <td>
                                {{ $motif->nom }}
                                @if ($motif->est_depense) <span class="badge text-bg-info ms-1">Dépense</span> @endif
                            </td>
                            <td>
                                @if ($motif->actif)
                                    <span class="badge text-bg-success">Actif</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <x-edit-button :href="route('motifs-mouvement.edit', $motif)" />
                                <x-delete-button :action="route('motifs-mouvement.destroy', $motif)" :label="'le motif « '.$motif->nom.' »'" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-secondary py-4">Aucun motif pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $motifs->links() }}
    </div>
@endsection
