@extends('layouts.app')

@section('title', 'Nouvelle caisse')

@section('content')
    <div class="d-flex align-items-center gap-2 mb-3">
        <x-bouton-retour :route="route('caisses.index')" />
        <h2 class="h4 mb-0">Nouvelle caisse</h2>
    </div>

    {{-- Une caisse appartient à un magasin : sans magasin, la liste du
         formulaire serait vide et rien ne dirait pourquoi. --}}
    @if ($magasins->isEmpty())
        <div class="alert alert-warning mx-auto d-flex flex-wrap align-items-center justify-content-between gap-2" style="max-width: 480px;">
            <span><i class="bi bi-shop me-2"></i>Créez d'abord un magasin : une caisse lui est toujours rattachée.</span>
            <a href="{{ route('magasins.create') }}" class="btn btn-sm btn-warning">Créer un magasin</a>
        </div>
    @endif

    <div class="card mx-auto" style="max-width: 480px;">
        <div class="card-body">
            <form method="POST" action="{{ route('caisses.store') }}">
                @csrf
                @include('caisses._form')
            </form>
        </div>
    </div>
@endsection
