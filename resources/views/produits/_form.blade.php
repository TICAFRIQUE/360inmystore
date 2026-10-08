<div class="row">
    <div class="col-md-6 mb-3">
        <label for="sku" class="form-label">SKU</label>
        <input type="text" name="sku" id="sku" class="form-control @error('sku') is-invalid @enderror"
               value="{{ old('sku', $produit->sku ?? '') }}" placeholder="Laissez vide pour génération automatique">
        @error('sku') <div class="invalid-feedback">{{ $message }}</div> @enderror
        <div class="form-text">Laissez vide pour générer un SKU automatiquement (ex. PRD-000123).</div>
    </div>

    <div class="col-md-6 mb-3">
        <label for="code_barre" class="form-label">Code-barres</label>
        <input type="text" name="code_barre" id="code_barre" class="form-control @error('code_barre') is-invalid @enderror"
               value="{{ old('code_barre', $produit->code_barre ?? '') }}">
        @error('code_barre') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
</div>

<div class="row" x-data="{
        nom: {{ Js::from(old('nom', $produit->nom ?? '')) }},
        libelleDistinctif: {{ Js::from(old('libelle_distinctif', $produit->libelle_distinctif ?? '')) }},
        nomsExistants: {{ Js::from($nomsExistants->map(fn ($n) => mb_strtolower(trim($n)))) }},
        get nomEnDouble() {
            return this.nom.trim() !== '' && this.nomsExistants.includes(this.nom.trim().toLowerCase());
        },
    }">
    <div class="col-md-6 mb-3">
        <label for="nom" class="form-label">Nom<span class="required-marker">*</span></label>
        <input type="text" name="nom" id="nom" class="form-control @error('nom') is-invalid @enderror"
               x-model="nom" required>
        @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="col-md-6 mb-3">
        <label for="libelle_distinctif" class="form-label">
            Libellé distinctif<span class="required-marker" x-show="nomEnDouble" x-cloak>*</span>
        </label>
        <input type="text" name="libelle_distinctif" id="libelle_distinctif" class="form-control @error('libelle_distinctif') is-invalid @enderror"
               x-model="libelleDistinctif" :required="nomEnDouble" placeholder="Qualité, motif, provenance…">
        @error('libelle_distinctif') <div class="invalid-feedback">{{ $message }}</div> @enderror
        <div class="form-text">Permet de distinguer deux produits qui portent le même nom.</div>
        <div class="form-text text-warning" x-show="nomEnDouble && libelleDistinctif.trim() === ''" x-cloak>
            <i class="bi bi-exclamation-triangle-fill me-1"></i>Un autre produit s'appelle déjà « <span x-text="nom"></span> ». Le libellé distinctif est obligatoire pour les différencier à la caisse.
        </div>
    </div>
</div>

<div class="row" x-data="{
        categories: {{ \Illuminate\Support\Js::from($categories->map(fn ($c) => ['id' => $c->id, 'nom' => $c->nom, 'parent_id' => $c->parent_id])->values()) }},
        parentId: {{ \Illuminate\Support\Js::from(old('categorie_parent_id', $categorieParentInitiale ?? '')) }},
        categorieId: {{ \Illuminate\Support\Js::from(old('categorie_id', $produit->categorie_id ?? '')) }},
        get parentes() { return this.categories.filter((c) => !c.parent_id); },
        get enfants() { return this.categories.filter((c) => String(c.parent_id) === String(this.parentId)); },
        get aDesEnfants() { return this.enfants.length > 0; },
    }">
    <input type="hidden" name="categorie_id" :value="categorieId">

    <div class="col-md-3 mb-3">
        <label for="categorie_parent_id" class="form-label">Catégorie<span class="required-marker">*</span></label>
        <select name="categorie_parent_id" id="categorie_parent_id" class="form-select @error('categorie_id') is-invalid @enderror"
                x-model="parentId" @change="categorieId = aDesEnfants ? '' : parentId" required>
            <option value="" :selected="!parentId">— Choisir —</option>
            <template x-for="parente in parentes" :key="parente.id">
                <option :value="parente.id" :selected="String(parente.id) === String(parentId)" x-text="parente.nom"></option>
            </template>
        </select>
        @error('categorie_id') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
    </div>

    <div class="col-md-3 mb-3" x-show="aDesEnfants" x-cloak>
        <label for="categorie_sous_id" class="form-label">Sous-catégorie<span class="required-marker">*</span></label>
        <select id="categorie_sous_id" class="form-select" x-model="categorieId" :required="aDesEnfants">
            <option value="" :selected="!categorieId">— Choisir —</option>
            <template x-for="enfant in enfants" :key="enfant.id">
                <option :value="enfant.id" :selected="String(enfant.id) === String(categorieId)" x-text="enfant.nom"></option>
            </template>
        </select>
    </div>

    <div class="col-md-3 mb-3">
        <label for="unite_base_id" class="form-label">Unité de base<span class="required-marker">*</span></label>
        <select name="unite_base_id" id="unite_base_id" class="form-select @error('unite_base_id') is-invalid @enderror" required>
            <option value="">— Choisir —</option>
            @foreach ($unites as $uniteOption)
                <option value="{{ $uniteOption->id }}" @selected(old('unite_base_id', $produit->unite_base_id ?? '') == $uniteOption->id)>
                    {{ $uniteOption->nom }}
                </option>
            @endforeach
        </select>
        @error('unite_base_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
        <div class="form-text">La plus petite unité vendable de ce produit (Pièce, Litre, Mètre…).</div>
    </div>

    <div class="col-md-3 mb-3">
        <label for="prix_piece" class="form-label">Prix de l'unité de base (F CFA)<span class="required-marker">*</span></label>
        <input type="number" name="prix_piece" id="prix_piece" class="form-control @error('prix_piece') is-invalid @enderror"
               value="{{ old('prix_piece', $produit->prix_piece ?? '') }}" min="0" required>
        @error('prix_piece') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="col-md-3 mb-3">
        <label for="seuil_alerte" class="form-label">Seuil d'alerte (unités de base)<span class="required-marker">*</span></label>
        <input type="number" name="seuil_alerte" id="seuil_alerte" class="form-control @error('seuil_alerte') is-invalid @enderror"
               value="{{ old('seuil_alerte', $produit->seuil_alerte ?? 0) }}" min="0" required>
        @error('seuil_alerte') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
</div>

@unless (isset($produit))
    <div class="mb-3 p-3 bg-light rounded" x-data="{ lots: {{ \Illuminate\Support\Js::from(old('unites_vente', [])) }} }">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <span class="form-label mb-0">Unités de vente</span>
                <div class="form-text mt-0">Optionnel — l'unité de base reste toujours vendable. Ajoutez des variantes (ex. Bidon 5L, Carton de 24) avec leur unité, leur facteur et leur prix.</div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary text-nowrap" @click="lots.push({ unite_id: '', facteur: '', prix: '' })">
                <i class="bi bi-plus-lg"></i> Ajouter une unité de vente
            </button>
        </div>

        <template x-if="lots.length > 0">
            <div class="mt-3">
                <template x-for="(lot, index) in lots" :key="index">
                    <div class="row g-2 align-items-end mb-2">
                        <div class="col-4">
                            <label class="form-label small">Unité<span class="required-marker">*</span></label>
                            <select :name="'unites_vente['+index+'][unite_id]'" x-model="lot.unite_id" class="form-select form-select-sm" required>
                                <option value="">— Choisir —</option>
                                @foreach ($unites as $uniteOption)
                                    <option value="{{ $uniteOption->id }}">{{ $uniteOption->nom }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label small">Facteur<span class="required-marker">*</span></label>
                            <input type="number" :name="'unites_vente['+index+'][facteur]'" x-model="lot.facteur" class="form-control form-control-sm" min="2" placeholder="Ex. 5" required>
                        </div>
                        <div class="col-3">
                            <label class="form-label small">Prix ({{ App\Models\Devise::abreviationActuelle() }})<span class="required-marker">*</span></label>
                            <input type="number" :name="'unites_vente['+index+'][prix]'" x-model="lot.prix" class="form-control form-control-sm" min="0" required>
                        </div>
                        <div class="col-2">
                            <button type="button" class="btn btn-sm btn-icon btn-outline-danger" title="Retirer" @click="lots.splice(index, 1)">
                                <i class="bi bi-x-lg"></i>
                                <span class="visually-hidden">Retirer</span>
                            </button>
                        </div>
                    </div>
                </template>
                <div class="form-text">Facteur = nombre d'unités de base contenues (ex. 5 pour un bidon de 5L si l'unité de base est le litre).</div>
            </div>
        </template>

        @error('unites_vente.*.unite_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
        @error('unites_vente.*.facteur') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
        @error('unites_vente.*.prix') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
    </div>
@endunless

<div class="mb-3">
    <label for="image" class="form-label">Image</label>
    <input type="file" name="image" id="image" class="form-control @error('image') is-invalid @enderror" accept="image/*">
    @error('image') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">Une photo prise au téléphone convient : elle est réduite automatiquement (1 Mo au plus une fois enregistrée).</div>
</div>

<x-actif-toggle :checked="$produit->actif ?? true" />

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            window.initSelect2('#unite_base_id', { placeholder: '— Choisir —' });
            // Catégorie parente : liste statique, select2 classique (sans
            // mise en forme particulière). La sous-catégorie qui apparaît à
            // côté reste un <select> natif : ses options changent selon la
            // catégorie choisie, ce qu'Alpine gère nativement sans avoir à
            // resynchroniser un plugin jQuery à chaque changement.
            window.initSelect2('#categorie_parent_id', { placeholder: '— Choisir —' });
        });
    </script>
@endpush
