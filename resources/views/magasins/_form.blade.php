<div class="mb-3">
    <label for="nom" class="form-label">Nom<span class="required-marker">*</span></label>
    <input type="text" name="nom" id="nom" class="form-control @error('nom') is-invalid @enderror"
           value="{{ old('nom', $magasin->nom ?? '') }}" required>
    @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="mb-3">
    <label for="type" class="form-label">Type<span class="required-marker">*</span></label>
    <select name="type" id="type" class="form-select @error('type') is-invalid @enderror" required>
        <option value="magasin" @selected(old('type', $magasin->type ?? 'magasin') === 'magasin')>Magasin (point de vente)</option>
        <option value="depot" @selected(old('type', $magasin->type ?? 'magasin') === 'depot')>Dépôt (stockage uniquement)</option>
    </select>
    <div class="form-text">Un dépôt sert uniquement au stockage : il n'a ni caisse ni session de vente.</div>
    @error('type') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="mb-3">
    <label for="adresse" class="form-label">Adresse</label>
    <input type="text" name="adresse" id="adresse" class="form-control @error('adresse') is-invalid @enderror"
           value="{{ old('adresse', $magasin->adresse ?? '') }}">
    @error('adresse') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="mb-3">
    <label for="telephone" class="form-label">Téléphone</label>
    <x-champ-telephone :valeur="old('telephone', $magasin->telephone ?? '')" />
</div>

<x-actif-toggle :checked="$magasin->actif ?? true" />

<button type="submit" class="btn btn-primary">Enregistrer</button>
<a href="{{ route('magasins.index') }}" class="btn btn-link">Annuler</a>
