<div class="mb-3">
    <label for="nom" class="form-label">Nom<span class="required-marker">*</span></label>
    <input type="text" name="nom" id="nom" class="form-control @error('nom') is-invalid @enderror"
           value="{{ old('nom', $fournisseur->nom ?? '') }}" required autofocus>
    @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="mb-3">
    <label for="code" class="form-label">Code</label>
    <input type="text" name="code" id="code" class="form-control @error('code') is-invalid @enderror"
           value="{{ old('code', $fournisseur->code ?? '') }}" placeholder="Génération automatique">
    @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="mb-3">
    <label for="telephone" class="form-label">Téléphone</label>
    <x-champ-telephone :valeur="old('telephone', $fournisseur->telephone ?? '')" />
</div>

<div class="mb-3">
    <label for="email" class="form-label">E-mail</label>
    <input type="email" name="email" id="email" class="form-control @error('email') is-invalid @enderror"
           value="{{ old('email', $fournisseur->email ?? '') }}">
    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="mb-3">
    <label for="adresse" class="form-label">Adresse</label>
    <input type="text" name="adresse" id="adresse" class="form-control @error('adresse') is-invalid @enderror"
           value="{{ old('adresse', $fournisseur->adresse ?? '') }}">
    @error('adresse') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<x-actif-toggle :checked="$fournisseur->actif ?? true" />

<button type="submit" class="btn btn-primary">Enregistrer</button>
<a href="{{ route('fournisseurs.index') }}" class="btn btn-link">Annuler</a>
