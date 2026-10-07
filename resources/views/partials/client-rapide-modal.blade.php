<div class="modal fade" id="clientRapideModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nouveau client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="clientRapideNom" class="form-label">Nom<span class="required-marker">*</span></label>
                    <input type="text" id="clientRapideNom" class="form-control" required>
                    <div class="invalid-feedback" id="clientRapideNomErreur"></div>
                </div>
                <div class="mb-3">
                    <label for="clientRapideTelephone" class="form-label">Téléphone</label>
                    {{-- Même champ que x-champ-telephone, sans attributs name : ce
                         formulaire est envoyé en AJAX (voir soumettreClientRapide()). --}}
                    <div class="input-group champ-telephone">
                        <select id="clientRapideTelephonePays" class="form-select champ-telephone__pays" aria-label="Pays (indicatif)">
                            @foreach (\App\Support\Telephone::options() as $option)
                                <option value="{{ $option['code'] }}" @selected($option['code'] === \App\Support\Telephone::paysParDefaut())>{{ $option['nom'] }} ({{ $option['indicatif'] }})</option>
                            @endforeach
                        </select>
                        <input type="tel" id="clientRapideTelephone" class="form-control" inputmode="tel" maxlength="30">
                        <div class="invalid-feedback" id="clientRapideTelephoneErreur"></div>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="clientRapideTypeClient" class="form-label">Type de client</label>
                    <select id="clientRapideTypeClient" class="form-select">
                        <option value="">— Aucun —</option>
                        @foreach ($typesClient ?? [] as $type)
                            <option value="{{ $type->id }}">{{ $type->nom }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" id="clientRapideBouton" onclick="window.soumettreClientRapide()">
                    Créer et sélectionner
                </button>
            </div>
        </div>
    </div>
</div>
