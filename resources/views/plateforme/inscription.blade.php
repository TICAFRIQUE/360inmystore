@extends('layouts.plateforme')

@section('title', 'Créer un compte')

@section('entete')
    <a href="{{ route('accueil') }}" class="btn btn-sm btn-link text-secondary">J'ai déjà un espace</a>
@endsection

@section('content')
    <div class="plateforme-public__carte plateforme-public__carte--large">
        <h1 class="h4 mb-1">Créer un compte</h1>
        <p class="text-secondary mb-4">Votre espace est créé tout de suite ; vous choisissez votre formule juste après.</p>

        @if (session('erreur'))
            <div class="alert alert-danger">{{ session('erreur') }}</div>
        @endif

        {{-- adressePersonnalisee : tant que l'adresse n'a pas été modifiée à
             la main, elle suit le nom du commerce (sans accents ni espaces). --}}
        <form method="POST" action="{{ route('plateforme.inscription.store') }}" data-attente-longue
              x-data="{
                  nom: @js(old('nom', '')),
                  adresse: @js(old('sous_domaine', '')),
                  adressePersonnalisee: @js(old('sous_domaine') !== null),
                  enAdresse(texte) {
                      return texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
                          .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 30);
                  },
              }">
            @csrf

            <h2 class="h6 text-secondary">Votre commerce</h2>
            <div class="mb-3">
                <label for="nom" class="form-label">Nom du commerce<span class="required-marker">*</span></label>
                <input type="text" name="nom" id="nom" class="form-control @error('nom') is-invalid @enderror" maxlength="120" required autofocus
                       x-model="nom" @input="if (! adressePersonnalisee) adresse = enAdresse(nom)">
                @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="mb-4">
                <label for="sous_domaine" class="form-label">Adresse de votre espace<span class="required-marker">*</span></label>
                <div class="input-group @error('sous_domaine') has-validation @enderror">
                    <input type="text" name="sous_domaine" id="sous_domaine" class="form-control @error('sous_domaine') is-invalid @enderror"
                           minlength="3" maxlength="30" pattern="[a-z0-9]+(-[a-z0-9]+)*" autocapitalize="none" autocomplete="off" required
                           x-model="adresse" @input="adressePersonnalisee = true">
                    <span class="input-group-text">.{{ $domaine }}</span>
                    @error('sous_domaine') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="form-text">Lettres minuscules, chiffres et tirets. C'est l'adresse que vous et vos employés utiliserez pour vous connecter.</div>
            </div>

            <h2 class="h6 text-secondary">Vous</h2>
            <div class="mb-3">
                <label for="proprietaire_nom" class="form-label">Votre nom<span class="required-marker">*</span></label>
                <input type="text" name="proprietaire_nom" id="proprietaire_nom" class="form-control @error('proprietaire_nom') is-invalid @enderror"
                       value="{{ old('proprietaire_nom') }}" maxlength="120" autocomplete="name" required>
                @error('proprietaire_nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="mb-4">
                <label for="telephone" class="form-label">Téléphone<span class="required-marker">*</span></label>
                <x-champ-telephone :valeur="old('telephone')" requis />
                <div class="form-text">Le pays choisi devient celui de votre commerce. Un seul espace par numéro.</div>
            </div>

            <h2 class="h6 text-secondary">Votre connexion</h2>
            <div class="mb-3">
                <label for="username" class="form-label">Nom d'utilisateur<span class="required-marker">*</span></label>
                <input type="text" name="username" id="username" class="form-control @error('username') is-invalid @enderror"
                       value="{{ old('username') }}" minlength="3" maxlength="50" autocapitalize="none" autocomplete="username" required>
                @error('username') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="row g-3 mb-4">
                <div class="col-sm-6">
                    <label for="mot_de_passe" class="form-label">Mot de passe<span class="required-marker">*</span></label>
                    <input type="password" name="mot_de_passe" id="mot_de_passe" class="form-control @error('mot_de_passe') is-invalid @enderror"
                           minlength="8" autocomplete="new-password" required>
                    @error('mot_de_passe') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-text">8 caractères au minimum.</div>
                </div>
                <div class="col-sm-6">
                    <label for="mot_de_passe_confirmation" class="form-label">Confirmer le mot de passe<span class="required-marker">*</span></label>
                    <input type="password" name="mot_de_passe_confirmation" id="mot_de_passe_confirmation" class="form-control"
                           minlength="8" autocomplete="new-password" required>
                </div>
            </div>

            <h2 class="h6 text-secondary">Vérification</h2>
            <div class="mb-4">
                <label for="reponse" class="form-label">{{ $question }}<span class="required-marker">*</span></label>
                <input type="text" name="reponse" id="reponse" class="form-control @error('reponse') is-invalid @enderror" style="max-width: 8rem;"
                       inputmode="numeric" autocomplete="off" required>
                @error('reponse') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            {{-- Champ piège (voir App\Plateforme\AntiRobot) : hors écran et
                 hors tabulation pour une personne, rempli par un robot. --}}
            <div class="champ-piege" aria-hidden="true">
                <label for="{{ \App\Plateforme\AntiRobot::CHAMP_PIEGE }}">Ne pas remplir ce champ</label>
                <input type="text" name="{{ \App\Plateforme\AntiRobot::CHAMP_PIEGE }}" id="{{ \App\Plateforme\AntiRobot::CHAMP_PIEGE }}" tabindex="-1" autocomplete="off">
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-100">Créer mon compte</button>
            <p class="text-secondary small text-center mt-2 mb-0">La préparation de votre espace peut prendre jusqu'à une minute.</p>
        </form>
    </div>
@endsection
