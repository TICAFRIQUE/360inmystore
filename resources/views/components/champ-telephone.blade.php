{{-- Champ téléphone unique de l'application (voir App\Support\Telephone et
     CLAUDE.md — Téléphone) : sélecteur de pays (indicatif) + numéro sans
     indicatif. Envoie « {nom}_pays » (code ISO) et « {nom} » ; le contrôleur
     appelle Telephone::normaliserDansRequete() puis valide avec
     TelephoneValide — jamais un <input> texte libre pour un téléphone. --}}
@props(['nom' => 'telephone', 'valeur' => null, 'id' => null, 'requis' => false])
@php
    $id ??= $nom;
    $saisie = \App\Support\Telephone::decomposer($valeur);
    // Après un refus de validation, le pays choisi par l'utilisateur prime
    // sur celui déduit de la valeur (qui n'est alors pas un numéro reconnu).
    $paysChoisi = old($nom.'_pays', $saisie['pays']);
    $options = \App\Support\Telephone::options();
@endphp
<div class="input-group champ-telephone @error($nom) has-validation @enderror"
     x-data="{ pays: @js($paysChoisi), exemples: @js(collect($options)->pluck('exemple', 'code')) }">
    <select name="{{ $nom }}_pays" class="form-select champ-telephone__pays" aria-label="Pays (indicatif)" x-model="pays">
        @foreach ($options as $option)
            <option value="{{ $option['code'] }}" @selected($option['code'] === $paysChoisi)>{{ $option['nom'] }} ({{ $option['indicatif'] }})</option>
        @endforeach
    </select>
    <input type="tel" name="{{ $nom }}" id="{{ $id }}" inputmode="tel" autocomplete="tel-national" maxlength="30"
           class="form-control @error($nom) is-invalid @enderror" value="{{ $saisie['numero'] }}"
           :placeholder="exemples[pays]" @required($requis)>
    @error($nom) <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>
