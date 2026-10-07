<div class="mb-3">
    <label for="nom" class="form-label">Nom<span class="required-marker">*</span></label>
    <input type="text" name="nom" id="nom" class="form-control @error('nom') is-invalid @enderror"
           value="{{ old('nom', $client->nom ?? '') }}" required autofocus>
    @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="mb-3">
    <label for="code" class="form-label">Code</label>
    <input type="text" name="code" id="code" class="form-control @error('code') is-invalid @enderror"
           value="{{ old('code', $client->code ?? '') }}" placeholder="Génération automatique">
    @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="mb-3">
    <label for="type_client_id" class="form-label">Type de client</label>
    <select name="type_client_id" id="type_client_id" class="form-select @error('type_client_id') is-invalid @enderror">
        <option value="">— Aucun —</option>
        @foreach ($typesClient as $type)
            <option value="{{ $type->id }}" @selected(old('type_client_id', $client->type_client_id ?? '') == $type->id)>{{ $type->nom }}</option>
        @endforeach
    </select>
    @error('type_client_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="mb-3">
    <label for="telephone" class="form-label">Téléphone</label>
    <x-champ-telephone :valeur="old('telephone', $client->telephone ?? '')" />
</div>

<div class="mb-3">
    <label for="adresse" class="form-label">Adresse</label>
    <input type="text" name="adresse" id="adresse" class="form-control @error('adresse') is-invalid @enderror"
           value="{{ old('adresse', $client->adresse ?? '') }}">
    @error('adresse') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

<div class="mb-3">
    <label for="limite_credit" class="form-label">Limite de crédit</label>
    <div class="input-group">
        <input type="number" name="limite_credit" id="limite_credit" min="0"
               class="form-control @error('limite_credit') is-invalid @enderror"
               value="{{ old('limite_credit', $client->limite_credit ?? '') }}" placeholder="Illimitée">
        <span class="input-group-text">F</span>
        @error('limite_credit') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="form-text">Laisser vide pour une limite illimitée. Au-delà, une vente à crédit est bloquée sauf permission dédiée.</div>
</div>

<x-actif-toggle :checked="$client->actif ?? true" />

<button type="submit" class="btn btn-primary">Enregistrer</button>
<a href="{{ route('clients.index') }}" class="btn btn-link">Annuler</a>
