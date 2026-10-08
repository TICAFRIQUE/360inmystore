@extends('layouts.app')

@section('title', 'Nouveau produit')

@section('content')
    <div class="d-flex align-items-center gap-2 mb-3">
        <x-bouton-retour :route="route('produits.index')" />
        <h2 class="h4 mb-0">Nouveau produit</h2>
    </div>

    {{-- Un produit a toujours une catégorie : sans aucune catégorie, la
         liste du formulaire serait vide et l'enregistrement impossible. --}}
    @if ($categories->isEmpty())
        <div class="alert alert-warning mx-auto d-flex flex-wrap align-items-center justify-content-between gap-2" style="max-width: 1000px;">
            <span><i class="bi bi-diagram-3 me-2"></i>Créez d'abord une catégorie : chaque produit doit être rangé dans l'une d'elles.</span>
            @can('categorie.gerer')
                <a href="{{ route('categories.create') }}" class="btn btn-sm btn-warning">Créer une catégorie</a>
            @endcan
        </div>
    @endif

    <div class="card mx-auto" style="max-width: 1000px;">
        <div class="card-body">
            <form method="POST" action="{{ route('produits.store') }}" enctype="multipart/form-data">
                @csrf
                @include('produits._form')
                <button type="submit" class="btn btn-primary">Enregistrer</button>
                <a href="{{ route('produits.index') }}" class="btn btn-link">Annuler</a>
            </form>
        </div>
    </div>
@endsection
