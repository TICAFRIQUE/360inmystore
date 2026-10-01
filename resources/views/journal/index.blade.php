@extends('layouts.app')

@section('title', "Journal d'activité")

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-1">
        <h2 class="h4 mb-0">Journal d'activité</h2>
        <x-export-buttons :pdf-route="route('journal.pdf', request()->query())" :excel-route="route('journal.excel', request()->query())" :tout="true" />
    </div>
    <p class="text-secondary small mb-3">
        Période : du {{ $debut->format('d/m/Y') }} au {{ $fin->format('d/m/Y') }}
    </p>

    <form method="GET" action="{{ route('journal.index') }}" class="row g-2 mb-3 d-print-none">
        <div class="col-auto">
            <input type="date" name="debut" value="{{ $debut->toDateString() }}" class="form-control form-control-sm">
        </div>
        <div class="col-auto">
            <input type="date" name="fin" value="{{ $fin->toDateString() }}" class="form-control form-control-sm">
        </div>
        <div class="col-auto">
            <select name="type" class="form-select form-select-sm">
                <option value="">Tous les types</option>
                @foreach ($types as $fqcn => $libelle)
                    <option value="{{ $fqcn }}" @selected(request('type') === $fqcn)>{{ $libelle }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <select name="causeur_id" class="form-select form-select-sm">
                <option value="">Tous les auteurs</option>
                @foreach ($utilisateurs as $utilisateur)
                    <option value="{{ $utilisateur->id }}" @selected(request('causeur_id') == $utilisateur->id)>{{ $utilisateur->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Filtrer</button>
        </div>
        @if (request()->hasAny(['debut', 'fin', 'type', 'causeur_id']))
            <div class="col-auto">
                <x-bouton-reinitialiser :route="route('journal.index')" />
            </div>
        @endif
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Auteur</th>
                        <th>Action</th>
                        <th>Concerne</th>
                        <th class="text-end d-print-none">Détail</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($activites as $activite)
                        @php
                            $action = \App\Http\Controllers\JournalActiviteController::LIBELLES_EVENEMENT[$activite->description] ?? $activite->description;
                            $sujet = $activite->subject_type ? class_basename($activite->subject_type) : null;
                        @endphp
                        <tr>
                            <td class="text-nowrap">{{ $activite->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $activite->causer?->name ?? '—' }}</td>
                            <td>{{ $action }}</td>
                            <td>
                                {{ $sujet }}
                                @if ($activite->subject_id) <code class="small">#{{ $activite->subject_id }}</code> @endif
                            </td>
                            <td class="text-end d-print-none">
                                @if ($activite->properties->isNotEmpty())
                                    <button type="button" class="btn btn-sm btn-icon btn-outline-secondary"
                                            data-bs-toggle="collapse" data-bs-target="#detail-{{ $activite->id }}"
                                            title="Voir le détail">
                                        <i class="bi bi-chevron-down"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                        @if ($activite->properties->isNotEmpty())
                            <tr class="collapse d-print-none" id="detail-{{ $activite->id }}">
                                <td colspan="5" class="bg-light small">
                                    <pre class="mb-0 small">{{ json_encode($activite->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">Aucune activité sur cette période.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3 d-print-none">
        {{ $activites->links() }}
    </div>
@endsection
