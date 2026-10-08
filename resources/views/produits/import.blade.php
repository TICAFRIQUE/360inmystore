{{-- Import du catalogue depuis Excel ou CSV (voir ImportProduitController) :
     trois étapes à l'écran, et le compte rendu des lignes à corriger quand
     le fichier est refusé. --}}
@extends('layouts.app')

@section('title', 'Importer des produits')

@section('content')
    <div class="d-flex align-items-center gap-2 mb-3">
        <x-bouton-retour :route="route('produits.index')" />
        <h2 class="h4 mb-0">Importer des produits</h2>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            @if (session('erreursImport'))
                <div class="alert alert-danger">
                    <p class="fw-medium mb-2">
                        <i class="bi bi-exclamation-triangle me-2"></i>Rien n'a été importé : {{ count(session('erreursImport')) }}
                        {{ count(session('erreursImport')) > 1 ? 'points sont' : 'point est' }} à corriger dans le fichier.
                    </p>
                    <ul class="mb-0 import-erreurs">
                        @foreach (session('erreursImport') as $erreur)
                            <li>{{ $erreur }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <ol class="import-etapes">
                        <li>
                            <strong>Téléchargez le modèle</strong>
                            <span>Un classeur avec les bonnes colonnes, et une feuille « Exemple » déjà remplie.</span>
                            <a href="{{ route('produits.import.modele') }}" class="btn btn-outline-primary" data-sans-spinner>
                                <i class="bi bi-file-earmark-spreadsheet me-2"></i>Télécharger le modèle
                            </a>
                        </li>
                        <li>
                            <strong>Remplissez la feuille « Produits »</strong>
                            <span>Une ligne par produit, sous la ligne d'intitulés. {{ $lignesMax }} produits au plus par fichier.</span>
                        </li>
                        <li>
                            <strong>Envoyez le fichier</strong>
                            <span>Le fichier est vérifié en entier avant tout enregistrement : à la moindre erreur, rien n'est importé et les lignes à corriger vous sont indiquées.</span>

                            <form method="POST" action="{{ route('produits.import.store') }}" enctype="multipart/form-data" class="mt-2">
                                @csrf
                                <div class="mb-3">
                                    <label for="fichier" class="form-label">Fichier Excel ou CSV<span class="required-marker">*</span></label>
                                    <input type="file" name="fichier" id="fichier" class="form-control @error('fichier') is-invalid @enderror"
                                           accept=".xlsx,.xls,.csv" required>
                                    @error('fichier') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="mb-3">
                                    <label for="magasin_id" class="form-label">Magasin ou dépôt qui reçoit le stock initial</label>
                                    <select name="magasin_id" id="magasin_id" class="form-select @error('magasin_id') is-invalid @enderror">
                                        <option value="">— Aucun : importer le catalogue sans stock —</option>
                                        @foreach ($magasins as $magasin)
                                            <option value="{{ $magasin->id }}" @selected(old('magasin_id') == $magasin->id)>{{ $magasin->nom }}</option>
                                        @endforeach
                                    </select>
                                    @error('magasin_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">À choisir seulement si le fichier renseigne la colonne « Stock initial ».</div>
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-2"></i>Importer</button>
                            </form>
                        </li>
                    </ol>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="carte-titre mb-3">
                        <h3 class="carte-titre__texte">Les colonnes du fichier</h3>
                    </div>
                    <dl class="import-colonnes">
                        <dt>Nom<span class="required-marker">*</span>, Catégorie<span class="required-marker">*</span>, Prix de vente<span class="required-marker">*</span></dt>
                        <dd>Les trois seules colonnes obligatoires. Le prix est celui de l'unité de base, en nombre entier.</dd>
                        <dt>Libellé distinctif</dt>
                        <dd>Pour différencier deux produits de même nom (« Bleu », « Sac de 5 kg »…).</dd>
                        <dt>Référence, Code-barres</dt>
                        <dd>Facultatifs. Sans référence, elle est générée. Aucune ne doit déjà exister.</dd>
                        <dt>Sous-catégorie</dt>
                        <dd>Facultative. Catégories et sous-catégories absentes sont créées.</dd>
                        <dt>Unité de base</dt>
                        <dd>Pièce, Kilogramme, Litre, Mètre… « Pièce » si la colonne est vide.</dd>
                        <dt>Seuil d'alerte</dt>
                        <dd>Quantité sous laquelle le produit est signalé. 0 si la colonne est vide.</dd>
                        <dt>Unité de vente 1 et 2, Quantité par unité, Prix unité</dt>
                        <dd>Un conditionnement vendu en plus de l'unité de base : « Carton », 12, 17 000 pour un carton de 12 à 17 000.</dd>
                        <dt>Stock initial, Prix d'achat</dt>
                        <dd>Facultatifs. Le stock entre dans le magasin choisi ; le prix d'achat sert au calcul des marges.</dd>
                    </dl>
                    <p class="form-text mb-0">L'ordre des colonnes est libre. Un produit déjà enregistré n'est jamais modifié par un import.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
