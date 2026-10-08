<div class="mb-3">
    <label for="nom" class="form-label">Nom<span class="required-marker">*</span></label>
    <input type="text" name="nom" id="nom" class="form-control @error('nom') is-invalid @enderror"
           value="{{ old('nom', $motif->nom ?? '') }}" required autofocus>
    @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>

{{-- Une sortie d'argent faite sous ce motif compte comme une dépense (voir
     App\Models\MotifMouvement). --}}
<div class="form-check mb-3">
    <input type="hidden" name="est_depense" value="0">
    <input type="checkbox" name="est_depense" value="1" id="est_depense" class="form-check-input" @checked(old('est_depense', $motif->est_depense ?? false))>
    <label class="form-check-label" for="est_depense">C'est une dépense</label>
    <div class="form-text">
        À cocher pour le loyer, les salaires, l'électricité, le transport… : chaque sortie d'argent sous ce motif est alors comptée dans les dépenses et retirée du résultat.
        À laisser décoché pour ce qui n'est pas une dépense : retrait du propriétaire, dépôt en banque, appoint de monnaie.
        Ce réglage ne vaut que pour les prochaines sorties, jamais pour celles déjà enregistrées.
    </div>
</div>

<x-actif-toggle :checked="$motif->actif ?? true" />

<button type="submit" class="btn btn-primary">Enregistrer</button>
<a href="{{ route('motifs-mouvement.index') }}" class="btn btn-link">Annuler</a>
