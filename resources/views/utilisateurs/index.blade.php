@extends('layouts.app')

@section('title', 'Utilisateurs')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Utilisateurs</h2>
        <a href="{{ route('utilisateurs.create') }}" class="btn btn-primary">Nouvel utilisateur</a>
    </div>

    @if (session('codeGenere'))
        <div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2"
             x-data="{ copied: false, code: {{ Js::from(session('codeGenere')) }} }">
            <div>
                <strong><i class="bi bi-key-fill me-1"></i>Code généré pour {{ session('utilisateurGenere') }} :</strong>
                <code class="fs-5 ms-2">{{ session('codeGenere') }}</code>
                <div class="small text-secondary mt-1">Il ne sera plus affiché ensuite — notez-le ou copiez-le maintenant.</div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-dark"
                    @click="navigator.clipboard.writeText(code); copied = true; setTimeout(() => copied = false, 2000)">
                <i class="bi" :class="copied ? 'bi-check-lg' : 'bi-clipboard'"></i>
                <span x-text="copied ? 'Copié !' : 'Copier'"></span>
            </button>
        </div>
    @endif

    <x-recherche-form :action="route('utilisateurs.index')" placeholder="Nom ou nom d'utilisateur…">
        <x-slot:export>
            <x-export-buttons :pdf-route="route('utilisateurs.pdf', request()->query())" :excel-route="route('utilisateurs.excel', request()->query())" :tout="true" />
        </x-slot:export>
    </x-recherche-form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <x-th-tri champ="name" label="Nom" />
                        <x-th-tri champ="username" label="Nom d'utilisateur" />
                        <th>Rôle</th>
                        <th>Magasin</th>
                        <x-th-tri champ="actif" label="Statut" />
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($utilisateurs as $utilisateur)
                        <tr>
                            <td>{{ $utilisateur->name }}</td>
                            <td>{{ $utilisateur->username }}</td>
                            <td>
                                @foreach ($utilisateur->roles as $role)
                                    <span class="badge text-bg-primary">{{ $role->name }}</span>
                                @endforeach
                            </td>
                            <td>{{ $utilisateur->magasin->nom ?? '—' }}</td>
                            <td>
                                @if ($utilisateur->actif)
                                    <span class="badge text-bg-success">Actif</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                {{-- Un superadmin ne se gère pas depuis cet écran, sauf par
                                     lui-même (voir UtilisateurController::edit()). --}}
                                @if (! $utilisateur->hasRole('Superadmin') || $utilisateur->id === auth()->id())
                                    <x-edit-button :href="route('utilisateurs.edit', $utilisateur)" />
                                    @unless ($utilisateur->hasRole('Superadmin') || $utilisateur->id === auth()->id())
                                        <x-delete-button :action="route('utilisateurs.destroy', $utilisateur)" :label="'l\'utilisateur « '.$utilisateur->name.' »'" />
                                    @endunless
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">Aucun utilisateur pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $utilisateurs->links() }}
    </div>
@endsection
