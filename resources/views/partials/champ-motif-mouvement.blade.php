{{-- Champ « Motif » d'un mouvement d'entrée/sortie, partagé par la session de
     caisse (sessions/show) et la trésorerie (comptabilite/show). $motifs :
     motifs actifs que l'utilisateur peut choisir — ceux marqués « dépense »
     n'y figurent que s'il a la permission depense.creer.

     Une SORTIE sous un motif « dépense » est enregistrée comme une dépense
     (voir DepenseService) : le champ propose alors le bénéficiaire. Le bouton
     « + » crée un motif sans quitter le formulaire. --}}
<div class="col-12 col-md-6"
     x-data="{
         ajoutMotifOuvert: false,
         nouveauEstDepense: false,
         estDepense: false,
         suivre() { this.estDepense = !! $refs.motifSelect.selectedOptions[0]?.dataset.depense; },
     }">
    <label for="motif" class="form-label small mb-1">Motif</label>
    <div class="d-flex gap-1">
        <select name="motif" id="motif" x-ref="motifSelect" class="form-select form-select-sm" required @change="suivre()">
            <option value="">— Choisir —</option>
            @foreach ($motifs as $m)
                <option value="{{ $m->nom }}" @if ($m->est_depense) data-depense="1" @endif>{{ $m->nom }}{{ $m->est_depense ? ' (dépense)' : '' }}</option>
            @endforeach
        </select>
        <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0" title="Nouveau motif"
                @click="ajoutMotifOuvert = !ajoutMotifOuvert">
            <i class="bi bi-plus-lg"></i>
        </button>
    </div>

    <div class="mt-1" x-show="ajoutMotifOuvert" x-cloak>
        <div class="input-group input-group-sm">
            <input type="text" x-ref="motifNouveau" class="form-control" placeholder="Nouveau motif…" maxlength="255"
                   @keydown.enter.prevent="window.ajouterMotifRapide($refs.motifSelect, $refs.motifNouveau, () => { ajoutMotifOuvert = false; nouveauEstDepense = false; }, nouveauEstDepense)">
            <button type="button" class="btn btn-outline-primary"
                    @click="window.ajouterMotifRapide($refs.motifSelect, $refs.motifNouveau, () => { ajoutMotifOuvert = false; nouveauEstDepense = false; }, nouveauEstDepense)">
                Ajouter
            </button>
        </div>
        @can('depense.creer')
            <div class="form-check mt-1">
                <input type="checkbox" class="form-check-input" id="motif-nouveau-depense" x-model="nouveauEstDepense">
                <label class="form-check-label small" for="motif-nouveau-depense">C'est une dépense (loyer, salaire, facture…)</label>
            </div>
        @endcan
    </div>

    <div class="mt-2" x-show="estDepense" x-cloak>
        <label for="beneficiaire" class="form-label small mb-1">Bénéficiaire</label>
        <input type="text" name="beneficiaire" id="beneficiaire" class="form-control form-control-sm" maxlength="255"
               placeholder="À qui l'argent a été remis (facultatif)" :disabled="! estDepense">
        <div class="form-text"><i class="bi bi-wallet2 me-1"></i>Une sortie sous ce motif est comptée dans les dépenses.</div>
    </div>
</div>
