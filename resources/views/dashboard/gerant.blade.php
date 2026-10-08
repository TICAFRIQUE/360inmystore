@extends('layouts.app')

@section('title', 'Tableau de bord')

@section('content')
    @include('dashboard._entete', ['perimetre' => $utilisateur->magasin->nom])

    @include('dashboard._apercu')
@endsection
