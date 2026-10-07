@extends('layouts.app')

@section('title', 'Paramètres')

@section('content')
    <h2 class="h4 mb-3">Paramètres</h2>

    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-body">
                    <h3 class="h6 mb-3">Identité de l'application</h3>

                    <img src="{{ $parametre->logoUrl() }}" alt="Logo actuel" class="rounded mb-3 d-block" style="max-height: 120px;">

                    <form method="POST" action="{{ route('parametres.update') }}" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="logo" class="form-label">Logo</label>
                            <input type="file" name="logo" id="logo" accept="image/*"
                                   class="form-control @error('logo') is-invalid @enderror">
                            <div class="form-text">Remplace le logo affiché dans l'application (menu, connexion, e-mails).</div>
                            @error('logo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label for="nom" class="form-label">Nom<span class="required-marker">*</span></label>
                            <input type="text" name="nom" id="nom" class="form-control @error('nom') is-invalid @enderror"
                                   value="{{ old('nom', $parametre->nom) }}" required>
                            @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label for="slogan" class="form-label">Slogan</label>
                            <input type="text" name="slogan" id="slogan" class="form-control @error('slogan') is-invalid @enderror"
                                   value="{{ old('slogan', $parametre->slogan) }}">
                            @error('slogan') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-4" x-data="{ valeur: '{{ old('couleur_primaire', $parametre->couleur_primaire ?: \App\Support\PaletteCouleur::DEFAUT) }}' }">
                            <label for="couleur_primaire" class="form-label">Couleur primaire</label>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <input type="color" class="form-control form-control-color" style="width: 3rem;"
                                       x-model="valeur" aria-label="Sélecteur de couleur primaire">
                                <input type="text" name="couleur_primaire" id="couleur_primaire"
                                       class="form-control @error('couleur_primaire') is-invalid @enderror" style="max-width: 9rem;"
                                       x-model="valeur" pattern="^#[0-9A-Fa-f]{6}$" maxlength="7" placeholder="{{ \App\Support\PaletteCouleur::DEFAUT }}">
                                <span class="rounded border flex-shrink-0" style="width: 2.25rem; height: 2.25rem;"
                                      x-bind:style="'background-color: ' + valeur"></span>
                                <button type="button" class="btn btn-outline-secondary btn-sm" @click="valeur = '{{ \App\Support\PaletteCouleur::DEFAUT }}'">
                                    Réinitialiser
                                </button>
                            </div>
                            @error('couleur_primaire') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                            <div class="form-text">Remplace la couleur par défaut de l'application (boutons, liens, menu, formulaires…).</div>
                        </div>

                        <div class="mb-3">
                            <label for="numero" class="form-label">Numéro de téléphone</label>
                            <x-champ-telephone nom="numero" :valeur="old('numero', $parametre->numero)" />
                        </div>

                        <div class="mb-3">
                            <label for="pays" class="form-label">Pays<span class="required-marker">*</span></label>
                            <select name="pays" id="pays" class="form-select @error('pays') is-invalid @enderror" required>
                                @foreach (\App\Support\Telephone::options() as $option)
                                    <option value="{{ $option['code'] }}" @selected(old('pays', $parametre->pays ?? \App\Support\Telephone::PAYS_DEFAUT) === $option['code'])>
                                        {{ $option['nom'] }} ({{ $option['indicatif'] }})
                                    </option>
                                @endforeach
                            </select>
                            @error('pays') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text">Indicatif proposé par défaut dans tous les champs téléphone (clients, fournisseurs, magasins…).</div>
                        </div>

                        <div class="mb-3">
                            <label for="adresse" class="form-label">Adresse</label>
                            <input type="text" name="adresse" id="adresse" class="form-control @error('adresse') is-invalid @enderror"
                                   value="{{ old('adresse', $parametre->adresse) }}">
                            @error('adresse') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label for="devise_id" class="form-label">Devise</label>
                            <select name="devise_id" id="devise_id" class="form-select @error('devise_id') is-invalid @enderror">
                                @foreach ($devises as $devise)
                                    <option value="{{ $devise->id }}" @selected(old('devise_id', $parametre->devise_id) == $devise->id)>
                                        {{ $devise->nom }} ({{ $devise->abreviation }})
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                Affichage uniquement — les montants restent des nombres entiers, aucune conversion.
                                <a href="{{ route('devises.index') }}">Gérer les devises</a>
                            </div>
                            @error('devise_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-4">
                            <label for="duree_validite_devis_jours" class="form-label">Durée de validité d'un devis (jours)<span class="required-marker">*</span></label>
                            <input type="number" name="duree_validite_devis_jours" id="duree_validite_devis_jours" min="1" max="365"
                                   class="form-control @error('duree_validite_devis_jours') is-invalid @enderror"
                                   value="{{ old('duree_validite_devis_jours', $parametre->duree_validite_devis_jours) }}" required>
                            <div class="form-text">Passé ce délai, un devis non transformé passe automatiquement au statut « Expiré ».</div>
                            @error('duree_validite_devis_jours') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card mb-4">
                <div class="card-body">
                    <h3 class="h6">Sauvegarde de la base de données</h3>
                    <p class="text-secondary small">
                        Chaque sauvegarde est nommée automatiquement avec la date et l'heure
                        (ex. « sauvegarde-2026-09-15-174534.sql »).
                    </p>

                    {{-- Plateforme : pas de dossier de sauvegarde ni de
                         restauration depuis l'espace d'une entreprise (voir
                         ParametreController::interdireDansUnEspaceHeberge()) —
                         seule la sauvegarde téléchargée reste proposée. --}}
                    @unless (plateforme()->estEntreprise())
                    <form method="POST" action="{{ route('parametres.dossier-sauvegarde') }}" class="mb-3">
                        @csrf
                        @method('PUT')
                        <label for="dossier_sauvegarde" class="form-label">Dossier de sauvegarde sur cet ordinateur</label>
                        <div class="input-group">
                            <input type="text" name="dossier_sauvegarde" id="dossier_sauvegarde"
                                   class="form-control @error('dossier_sauvegarde') is-invalid @enderror"
                                   value="{{ old('dossier_sauvegarde', $parametre->dossier_sauvegarde) }}"
                                   placeholder="Ex. C:\Sauvegardes 360inmystore">
                            <button type="submit" class="btn btn-outline-secondary">Enregistrer</button>
                            @error('dossier_sauvegarde') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="form-text">
                            @if ($parametre->dossier_sauvegarde)
                                Chaque sauvegarde est écrite directement dans ce dossier, sur cet
                                ordinateur (plus de téléchargement navigateur). Laisser vide pour
                                revenir au téléchargement.
                            @else
                                Vide par défaut : chaque sauvegarde est proposée en téléchargement.
                                Renseigner un dossier existant sur cet ordinateur (ex. un disque
                                externe ou un dossier partagé) pour qu'elle y soit écrite
                                directement à chaque fois.
                            @endif
                        </div>
                    </form>
                    @endunless

                    <form method="POST" action="{{ route('parametres.backup') }}" data-telechargement>
                        @csrf
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-download me-1"></i>
                            {{ $parametre->dossier_sauvegarde ? 'Sauvegarder maintenant' : 'Télécharger une sauvegarde' }}
                        </button>
                    </form>
                </div>
            </div>

            @unless (plateforme()->estEntreprise())
            <div class="card border-danger-subtle">
                <div class="card-body">
                    <h3 class="h6">Restauration de la base de données</h3>
                    <p class="text-secondary small mb-3">
                        Importe un fichier de sauvegarde (.sql) et <strong>remplace intégralement</strong>
                        les données actuelles par son contenu. Une sauvegarde de sécurité de l'état
                        actuel est créée automatiquement juste avant, au cas où le fichier importé
                        s'avérerait mauvais.
                    </p>

                    <form id="restaurerForm" method="POST" action="{{ route('parametres.restaurer') }}"
                          enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label for="fichier" class="form-label">Fichier de sauvegarde (.sql)</label>
                            <input type="file" name="fichier" id="fichier" accept=".sql"
                                   class="form-control @error('fichier') is-invalid @enderror" required>
                            @error('fichier') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <button type="button" class="btn btn-danger"
                                data-bs-toggle="modal" data-bs-target="#confirmActionModal"
                                data-form-id="restaurerForm"
                                data-message="Voulez-vous vraiment restaurer la base de données à partir de ce fichier ? Toutes les données actuelles (ventes, stock, clients…) seront remplacées par celles du fichier importé. Une sauvegarde de l'état actuel sera conservée automatiquement avant de le faire."
                                data-button-label="Restaurer"
                                data-button-class="btn-danger">
                            <i class="bi bi-upload me-1"></i>Restaurer la base de données
                        </button>
                    </form>
                </div>
            </div>
            @endunless
        </div>
    </div>
@endsection
