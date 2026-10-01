@extends('layouts.app')

@section('title', 'Rôles')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">Rôles</h2>
        <a href="{{ route('roles.create') }}" class="btn btn-primary">Nouveau rôle</a>
    </div>

    <div class="d-flex justify-content-end mb-3">
        <x-export-buttons :pdf-route="route('roles.pdf', request()->query())" :excel-route="route('roles.excel', request()->query())" :tout="true" />
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Permissions</th>
                        <th>Utilisateurs</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($roles as $role)
                        <tr>
                            <td>
                                {{ $role->name }}
                                @if ($role->name === 'Superadmin')
                                    <span class="badge text-bg-dark ms-1">Système</span>
                                @endif
                            </td>
                            <td>{{ $role->name === 'Superadmin' ? 'Accès total' : $role->permissions_count }}</td>
                            <td>{{ $role->users_count }}</td>
                            <td class="text-end">
                                @unless ($role->name === 'Superadmin')
                                    <x-edit-button :href="route('roles.edit', $role)" />
                                    <x-delete-button :action="route('roles.destroy', $role)" :label="'le rôle « '.$role->name.' »'" />
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-secondary py-4">Aucun rôle pour l'instant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
