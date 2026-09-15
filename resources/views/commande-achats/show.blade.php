@extends('layouts.app')

@section('title', "Bon de commande {$commande->numero}")

@section('content')
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="d-flex align-items-center gap-2">
                <x-bouton-retour :route="route('commande-achats.index')" />
                <h2 class="h4 mb-0">Bon de commande <code>{{ $commande->numero }}</code></h2>
            </div>
            <div class="text-secondary small ms-5 ps-1">
                Fournisseur : <a href="{{ route('fournisseurs.show', $commande->fournisseur) }}">{{ $commande->fournisseur->nom }}</a>
            </div>
            <div class="text-secondary small ms-5 ps-1">
                @if ($commande->trashed())
                    <span class="text-danger">Annulée</span>
                @elseif ($commande->statut === 'validee')
                    <span class="text-success">Validée le {{ $commande->valide_at->format('d/m/Y à H:i') }} par {{ $commande->validateur->name }}</span>
                @else
                    Date : {{ $commande->date_commande->format('d/m/Y') }} · Brouillon
                @endif
            </div>
        </div>
        <div class="d-flex gap-2">
            <x-export-buttons :pdf-route="route('commande-achats.pdf', $commande)" :excel-route="route('commande-achats.excel', $commande)" />
            @if (! $commande->trashed() && $commande->statut === 'brouillon' && $peutAnnuler)
                <x-delete-button :action="route('commande-achats.destroy', $commande)" :label="'la commande « '.$commande->numero.' »'" />
            @endif
        </div>
    </div>

    @if ($commande->trashed())
        <div class="alert alert-danger mb-3">
            <i class="bi bi-x-circle-fill me-1"></i>
            <strong>Commande annulée</strong> par {{ $commande->annulateur?->name ?? 'un utilisateur supprimé' }}
            le {{ $commande->deleted_at->format('d/m/Y H:i') }}.
            <div class="mt-1 fst-italic">{{ $commande->motif_annulation }}</div>
        </div>
    @endif

    @php
        $afficheValider = ! $commande->trashed() && $commande->statut === 'brouillon' && $peutValider;
        $afficheRegler = ! $commande->trashed() && $commande->statut === 'validee' && $peutRegler && $commande->resteDu() > 0;
        $afficheAnnuler = ! $commande->trashed() && $commande->statut === 'validee' && $peutAnnuler;
        $afficheRetourner = ! $commande->trashed() && $commande->statut === 'validee' && $peutRetourner;
        $afficheReceptionner = ! $commande->trashed() && $commande->statut === 'validee' && $peutReceptionner;
        $lignesAReceptionner = $commande->lignes->filter(
            fn ($ligne) => (float) $ligne->quantite_pieces - ($dejaRecuParLigne[$ligne->id] ?? 0) > 0
        );
        $quantiteRetournee = (float) $commande->retours->flatMap->lignes->sum('quantite_pieces');
    @endphp

    @if ($commande->statut === 'validee' && ! $commande->trashed())
        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="card h-100 shadow-sm border-0 border-start border-4 border-info">
                    <div class="card-body">
                        <div class="text-secondary small">Réception</div>
                        <div class="fs-4 fw-bold">{{ quantite($commande->quantiteRecuePieces()) }} / {{ quantite($commande->quantiteCommandeePieces()) }} pièce(s)</div>
                        <div class="small text-secondary">
                            Reste à recevoir : <span class="{{ $commande->quantiteResteARecevoirPieces() > 0 ? 'text-warning-emphasis fw-medium' : '' }}">{{ quantite($commande->quantiteResteARecevoirPieces()) }}</span>
                            · {{ $commande->tauxCompletion() }} %
                            @if ($quantiteRetournee > 0)
                                · dont {{ quantite($quantiteRetournee) }} retournée(s)
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100 shadow-sm border-0 border-start border-4 border-primary">
                    <div class="card-body">
                        <div class="text-secondary small">Montant</div>
                        <div class="fs-4 fw-bold">{{ number_format($commande->totalTtcReel(), 0, ',', ' ') }} F</div>
                        <div class="small text-secondary">
                            Indicatif : {{ montantAchat($commande->totalTtc()) }}
                            @if ($commande->ecartMontant() != 0)
                                · Écart : <span class="{{ $commande->ecartMontant() > 0 ? 'text-danger' : 'text-success' }}">{{ $commande->ecartMontant() > 0 ? '+' : '' }}{{ montantAchat($commande->ecartMontant()) }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100 shadow-sm border-0 border-start border-4 border-secondary">
                    <div class="card-body">
                        <div class="text-secondary small">Réceptions</div>
                        <div class="fs-4 fw-bold">{{ $commande->receptions->count() }}</div>
                        <div class="small text-secondary">
                            Reste dû : {{ number_format($commande->resteDu(), 0, ',', ' ') }} F
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100 shadow-sm border-0 border-start border-4 border-warning">
                    <div class="card-body">
                        <div class="text-secondary small">Retours</div>
                        <div class="fs-4 fw-bold">{{ quantite($quantiteRetournee) }} pièce(s)</div>
                        <div class="small text-secondary">
                            {{ $commande->retours->count() }} retour(s) · {{ number_format($commande->retours->sum('montant_total'), 0, ',', ' ') }} F
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($afficheValider || $afficheReceptionner || $afficheRegler || $afficheRetourner || $afficheAnnuler)
        <div class="d-flex flex-wrap justify-content-end gap-2 mb-3">
            @if ($afficheValider)
                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#validerAchatModal">
                    <i class="bi bi-check-circle me-1"></i>Valider
                </button>
            @endif

            @if ($afficheReceptionner)
                <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#receptionnerAchatModal"
                        @disabled($lignesAReceptionner->isEmpty())
                        title="{{ $lignesAReceptionner->isEmpty() ? 'Tout a déjà été reçu' : '' }}">
                    <i class="bi bi-box-seam me-1"></i>Réceptionner
                </button>
            @endif

            @if ($afficheRegler)
                <button type="button" class="btn btn-reglement btn-sm" data-bs-toggle="modal" data-bs-target="#reglerFournisseurModal">
                    <i class="bi bi-cash-coin me-1"></i>Régler
                </button>
            @endif

            @if ($afficheRetourner)
                <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#retourAchatModal"
                        @disabled($lignesRetournables->isEmpty())
                        title="{{ $lignesRetournables->isEmpty() ? 'Rien à retourner (pas encore reçu, ou tout a déjà été retourné)' : '' }}">
                    <i class="bi bi-arrow-return-left me-1"></i>Retourner
                </button>
            @endif

            @if ($afficheAnnuler)
                <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#annulerAchatModal"
                        @disabled($commande->receptions->isNotEmpty())
                        title="{{ $commande->receptions->isNotEmpty() ? 'Déjà réceptionnée : corrigez via un retour fournisseur' : '' }}">
                    <i class="bi bi-x-circle me-1"></i>Annuler
                </button>
            @endif
        </div>
    @endif

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Produit</th>
                        <th>Quantité achetée</th>
                        <th>Qté en pièces (stock)</th>
                        <th>Destination</th>
                        <th>Prix d'achat HT</th>
                        <th>Taxe</th>
                        <th>Total HT</th>
                        <th>Total TTC</th>
                        @if ($commande->statut === 'validee')
                            <th>Reçu</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($commande->lignes as $ligne)
                        <tr>
                            <td><code>{{ $ligne->produit->sku }}</code></td>
                            <td>{{ $ligne->produit->libelle_affichage }}</td>
                            <td>
                                {{ quantite($ligne->quantite) }} × {{ $ligne->uniteVente->unite->nom_avec_abbreviation ?? $ligne->produit->unite_base_libelle }}
                            </td>
                            <td>{{ quantite($ligne->quantite_pieces) }} {{ $ligne->produit->unite_base_libelle }}</td>
                            <td>
                                @if ($ligne->magasinDestination)
                                    {{ $ligne->magasinDestination->nom }}
                                    @if ($ligne->magasinDestination->estDepot())
                                        <span class="badge text-bg-info">Dépôt</span>
                                    @endif
                                @else
                                    <span class="text-secondary fst-italic">Non planifiée</span>
                                @endif
                            </td>
                            <td>
                                {{ montantAchat($ligne->prix_achat) }}
                                @if ($ligne->uniteVente)
                                    <div class="text-secondary small">soit {{ montantAchat($ligne->prixAchatParPiece()) }} / {{ $ligne->produit->unite_base_libelle }}</div>
                                @endif
                            </td>
                            <td>{{ $ligne->taxe->nom ?? '—' }}</td>
                            <td>{{ montantAchat($ligne->montantHt()) }}</td>
                            <td>{{ montantAchat($ligne->montantTtc()) }}</td>
                            @if ($commande->statut === 'validee')
                                @php $ligneDejaRecu = (float) ($dejaRecuParLigne[$ligne->id] ?? 0); @endphp
                                <td>
                                    <span class="{{ $ligneDejaRecu >= (float) $ligne->quantite_pieces ? 'text-success' : ($ligneDejaRecu > 0 ? 'text-warning-emphasis' : 'text-secondary') }}">
                                        {{ quantite($ligneDejaRecu) }}/{{ quantite($ligne->quantite_pieces) }}
                                    </span>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="6" class="text-end">Total</th>
                        <th>{{ montantAchat($commande->totalTaxes()) }}</th>
                        <th>{{ montantAchat($commande->totalHt()) }}</th>
                        <th>{{ montantAchat($commande->totalTtc()) }}</th>
                        @if ($commande->statut === 'validee')
                            <th></th>
                        @endif
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- Petit tableau de règlement, juste sous le tableau des produits (qui --}}
    {{-- affiche déjà Total HT/Taxes/TTC dans son pied) — plus la grosse --}}
    {{-- carte listant chaque paiement en pleine largeur. --}}
    @if ($commande->statut === 'validee')
        <div class="d-flex justify-content-end mb-3">
            <table class="table table-sm mb-0" style="max-width: 380px;">
                <tbody>
                    @forelse ($commande->paiements as $paiement)
                        <tr>
                            <td>{{ $paiement->moyenPaiement->nom }}</td>
                            <td class="text-end">{{ montant($paiement->montant) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="text-secondary small">Aucun paiement pour l'instant.</td>
                        </tr>
                    @endforelse
                    @foreach ($commande->reglementsFournisseur as $reglement)
                        <tr>
                            <td class="text-success">Règlement du {{ $reglement->created_at->format('d/m/Y H:i') }}</td>
                            <td class="text-end text-success">{{ montant($reglement->montant) }}</td>
                        </tr>
                    @endforeach
                    <tr class="fw-medium border-top">
                        <td>Total réglé</td>
                        <td class="text-end">{{ montant($commande->montantRegle()) }}</td>
                    </tr>
                    <tr class="fw-semibold {{ $commande->resteDu() > 0 ? 'text-danger' : '' }}">
                        <td>Reste dû au fournisseur</td>
                        <td class="text-end">{{ montant($commande->resteDu()) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif

    @if ($commande->receptions->isNotEmpty())
        <div class="card mt-3">
            <div class="card-body">
                <h3 class="h6">Bons d'achat (réceptions)</h3>
                @foreach ($commande->receptions as $reception)
                    <div class="small border-bottom py-1 text-info-emphasis">
                        <div class="d-flex justify-content-between">
                            <span>
                                <i class="bi bi-box-seam me-1"></i>Bon d'achat <code>{{ $reception->numero }}</code>
                                du {{ $reception->created_at->format('d/m/Y H:i') }}
                                par {{ $reception->auteur?->name ?? 'utilisateur supprimé' }}
                                @if ($reception->numero_bon_livraison_fournisseur)
                                    · BL n° <strong>{{ $reception->numero_bon_livraison_fournisseur }}</strong>
                                @endif
                                @if ($reception->numero_facture_fournisseur)
                                    · Facture n° <strong>{{ $reception->numero_facture_fournisseur }}</strong>
                                @endif
                            </span>
                            <span class="fw-medium">{{ montant($reception->totalTtc()) }}</span>
                        </div>
                        @if ($reception->motif)
                            <div class="text-secondary ps-4 fst-italic">{{ $reception->motif }}</div>
                        @endif
                        @foreach ($reception->lignes as $ligneReception)
                            <div class="d-flex justify-content-between text-secondary ps-4">
                                <span>{{ $ligneReception->produit->libelle_affichage }} × {{ quantite($ligneReception->quantite_pieces) }} — {{ $ligneReception->magasin->nom }}</span>
                                <span>{{ montant($ligneReception->prix_achat_reel) }} / pièce</span>
                            </div>
                        @endforeach
                        @forelse ($reception->paiements as $paiementReception)
                            <div class="d-flex justify-content-between text-success ps-4">
                                <span>Payé — {{ $paiementReception->moyenPaiement->nom }}</span>
                                <span>{{ montant($paiementReception->montant) }}</span>
                            </div>
                        @empty
                            <div class="text-secondary ps-4 fst-italic">Rien payé à la réception — dette fournisseur intégrale pour ce lot.</div>
                        @endforelse
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($commande->retours->isNotEmpty())
        <div class="card mt-3 bg-warning-subtle border-0">
            <div class="card-body">
                <h3 class="h6 text-warning-emphasis"><i class="bi bi-arrow-return-left me-1"></i>Retours</h3>
                @foreach ($commande->retours as $retour)
                    <div class="small border-bottom border-warning-subtle py-1 text-warning-emphasis">
                        <div class="d-flex justify-content-between">
                            <span>
                                <code>{{ $retour->numero }}</code>
                                du {{ $retour->created_at->format('d/m/Y H:i') }}
                                par {{ $retour->auteur?->name ?? 'utilisateur supprimé' }}
                            </span>
                            <span class="fw-medium">Avoir {{ montant($retour->montant_total) }}</span>
                        </div>
                        @if ($retour->motif)
                            <div class="text-secondary ps-4 fst-italic">{{ $retour->motif }}</div>
                        @endif
                        @foreach ($retour->lignes as $ligneRetour)
                            <div class="d-flex justify-content-between text-secondary ps-4">
                                <span>{{ $ligneRetour->produit->libelle_affichage }} × {{ quantite($ligneRetour->quantite_pieces) }}</span>
                                <span>{{ montant($ligneRetour->montant) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($afficheValider)
        <div class="modal fade" id="validerAchatModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form id="formValiderAchat" method="POST" action="{{ route('commande-achats.valider', $commande) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-check-circle me-1"></i>Valider le bon de commande</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-secondary mb-0">
                                Confirme la commande (envoyée au fournisseur, prête à être réceptionnée) — total
                                indicatif : <strong>{{ montantAchat($commande->totalTtc()) }}</strong>. Le stock et le
                                paiement se font plus tard, à chaque réception.
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-link" data-bs-dismiss="modal">Fermer</button>
                            <button type="submit" class="btn btn-success">Valider le bon de commande</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($afficheRegler)
        <div class="modal fade" id="reglerFournisseurModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content" x-data="{ paiements: [{ moyen_paiement_id: '', montant: {{ $commande->resteDu() }} }],
                        especeIds: @json($moyensPaiement->where('est_espece', true)->pluck('id')->values()),
                        detteAffichee: {{ $commande->resteDu() }},
                        get totalPaiements() { return this.paiements.reduce((total, p) => total + (Number(p.montant) || 0), 0); },
                        get contientEspeces() { return this.paiements.some(p => this.especeIds.includes(Number(p.moyen_paiement_id))); },
                        ajouterPaiement() { this.paiements.push({ moyen_paiement_id: '', montant: null }); },
                        retirerPaiement(index) { this.paiements.splice(index, 1); },
                        clamperMontant(index, valeurBrute) {
                            if (valeurBrute === '') return null;
                            const valeur = Number(valeurBrute) || 0;
                            const autres = this.paiements.reduce((total, p, i) => i === index ? total : total + (Number(p.montant) || 0), 0);
                            const maxPourCetteLigne = Math.max(0, this.detteAffichee - autres);
                            return Math.min(valeur, maxPourCetteLigne);
                        } }">
                    <form id="formReglerFournisseur" method="POST" action="{{ route('reglements-fournisseur.store', $commande->fournisseur) }}">
                        @csrf
                        <input type="hidden" name="commande_achat_id" value="{{ $commande->id }}">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-cash-coin me-1"></i>Règlement fournisseur</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-secondary mb-2">
                                Reste dû sur ce bon de commande : <strong>{{ montant($commande->resteDu()) }}</strong><br>
                                Solde total du fournisseur (toutes commandes confondues) : {{ montant($commande->fournisseur->solde()) }}
                            </p>
                            <template x-for="(paiement, index) in paiements" :key="index">
                                <div class="row g-1 align-items-center mb-2">
                                    <div class="col-6 col-md-5">
                                        <select :name="'paiements['+index+'][moyen_paiement_id]'" x-model="paiement.moyen_paiement_id" class="form-select form-select-sm" required>
                                            <option value="">Moyen…</option>
                                            @foreach ($moyensPaiement as $moyen)
                                                <option value="{{ $moyen->id }}">{{ $moyen->nom }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-4">
                                        <input type="number" :name="'paiements['+index+'][montant]'" :value="paiement.montant"
                                               @input="paiement.montant = clamperMontant(index, $event.target.value); $event.target.value = paiement.montant ?? ''"
                                               min="1" :max="detteAffichee" class="form-control form-control-sm" placeholder="Montant" required>
                                    </div>
                                    <div class="col-2">
                                        <button type="button" class="btn btn-sm btn-icon btn-outline-danger" @click="retirerPaiement(index)" x-show="paiements.length > 1">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                </div>
                            </template>
                            <button type="button" class="btn btn-sm btn-outline-reglement" @click="ajouterPaiement()" :disabled="totalPaiements >= detteAffichee">
                                <i class="bi bi-plus-lg"></i> Ajouter un paiement
                            </button>
                            <div class="mt-2 small text-secondary" x-show="contientEspeces" x-cloak>
                                <i class="bi bi-safe me-1"></i>Paiement en espèces : sort de la Caisse Générale.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-link" data-bs-dismiss="modal">Fermer</button>
                            <button type="submit" class="btn btn-reglement" :disabled="totalPaiements <= 0 || totalPaiements > detteAffichee">Enregistrer le règlement</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($afficheReceptionner && $lignesAReceptionner->isNotEmpty())
        <div class="modal fade" id="receptionnerAchatModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content"
                     x-data="{
                        // Groupé par ligne de commande : chaque ligne peut être
                        // reçue à plusieurs destinations en une seule réception
                        // (voir ajouterDestination) — un même produit livré et
                        // réparti sur plusieurs sites d'un coup.
                        // Pré-rempli au reste à recevoir (cas le plus fréquent :
                        // tout réceptionner d'un coup) — l'utilisateur réduit la
                        // valeur lui-même pour une réception partielle, même
                        // logique que le bon de livraison (ventes/ticket.blade.php).
                        groupes: {{ $lignesAReceptionner->mapWithKeys(fn ($l) => [$l->id => [[
                            'magasin_id' => $l->magasin_destination_id ?? '',
                            'quantite_pieces' => (float) $l->quantite_pieces - ($dejaRecuParLigne[$l->id] ?? 0),
                            // prix_achat_reel reste entier (réception réelle,
                            // voir CLAUDE.md) même si le prix indicatif du
                            // bon de commande, lui, peut porter des centimes.
                            'prix_achat_reel' => (int) round($l->prixAchatParPiece()),
                        ]]])->toJson() }},
                        sommeGroupe(ligneId) {
                            return (this.groupes[ligneId] || []).reduce((s, r) => s + (Number(r.quantite_pieces) || 0), 0);
                        },
                        resteGroupe(ligneId, resteInitial) {
                            return Math.max(0, resteInitial - this.sommeGroupe(ligneId));
                        },
                        ajouterDestination(ligneId, prixDefaut) {
                            this.groupes[ligneId].push({ magasin_id: '', quantite_pieces: 0, prix_achat_reel: prixDefaut, _cle: 'r' + this.groupes[ligneId].length + '-' + Date.now() + '-' + Math.random() });
                        },
                        retirerDestination(ligneId, index) {
                            this.groupes[ligneId].splice(index, 1);
                        },
                        // Même logique de « pool restant » que reglerFournisseurModal
                        // (clamperMontant) : le plafond d'une ligne de destination
                        // dépend de ce que les AUTRES lignes du même groupe ont
                        // déjà pris.
                        clamperQuantite(ligneId, index, resteInitial, valeurBrute) {
                            const valeur = Number(valeurBrute) || 0;
                            const autres = this.groupes[ligneId].reduce((s, r, i) => i === index ? s : s + (Number(r.quantite_pieces) || 0), 0);
                            return Math.min(valeur, Math.max(0, resteInitial - autres));
                        },
                        get total() {
                            return Object.values(this.groupes).reduce((total, rows) => total + rows.reduce((s, r) => s + (Number(r.quantite_pieces) || 0), 0), 0);
                        },
                        paiements: [],
                        get totalPaiements() { return this.paiements.reduce((total, p) => total + (Number(p.montant) || 0), 0); },
                        ajouterPaiement() { this.paiements.push({ moyen_paiement_id: '', montant: '' }); },
                        retirerPaiement(index) { this.paiements.splice(index, 1); },
                     }">
                    <form method="POST" action="{{ route('commande-achats.receptions.store', $commande) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-box-seam me-1"></i>Enregistrer une réception</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-secondary">
                                Quantité physiquement arrivée par article (unité de base — pièce), au prix
                                réellement facturé par le fournisseur pour ce lot (peut différer de l'indicatif) et
                                à la destination réelle (pré-remplie avec le plan de la commande, modifiable).
                            </p>

                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label for="reception-achat-numero-bl" class="form-label small">N° de bon de livraison (optionnel)</label>
                                    <input type="text" name="numero_bon_livraison_fournisseur" id="reception-achat-numero-bl" class="form-control form-control-sm"
                                           placeholder="Numéro écrit sur le bon de livraison">
                                </div>
                                <div class="col-md-6">
                                    <label for="reception-achat-numero-facture" class="form-label small">N° de facture fournisseur (optionnel)</label>
                                    <input type="text" name="numero_facture_fournisseur" id="reception-achat-numero-facture" class="form-control form-control-sm"
                                           placeholder="Numéro écrit sur la facture, si déjà remise">
                                </div>
                            </div>

                            {{-- Fond gris clair pour le panneau : chaque ligne reste une
                                 vraie carte blanche qui se détache nettement dessus, au lieu
                                 de se fondre dans le blanc du corps de la modale (même
                                 traitement que le panneau de lignes de commande-achats/create.blade.php). --}}
                            <div class="bg-light rounded p-2 p-md-3 mb-2">
                            @foreach ($lignesAReceptionner as $ligne)
                                @php
                                    $ligneDejaRecu = (float) ($dejaRecuParLigne[$ligne->id] ?? 0);
                                    $reste = (float) $ligne->quantite_pieces - $ligneDejaRecu;
                                @endphp
                                <div class="card bg-white shadow-sm mb-2">
                                <div class="card-body p-2 p-md-3">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                                        <div>
                                            <strong>{{ $ligne->produit->libelle_affichage }}</strong>
                                            <span class="text-secondary small ms-2">Commandé {{ quantite($ligne->quantite_pieces) }} · Reste à recevoir {{ quantite($reste) }}</span>
                                        </div>
                                        <span class="small fw-medium" :class="resteGroupe({{ $ligne->id }}, {{ $reste }}) <= 0 ? 'text-success' : 'text-secondary'">
                                            Alloué : <span x-text="sommeGroupe({{ $ligne->id }})"></span> / {{ quantite($reste) }}
                                        </span>
                                    </div>

                                    <template x-for="(row, ri) in groupes[{{ $ligne->id }}]" :key="row._cle ?? 'seed-{{ $ligne->id }}'">
                                        <div class="row g-1 align-items-end mb-2">
                                            <div class="col-6 col-md-4">
                                                <label class="form-label small mb-0">Destination</label>
                                                <select :name="'lignes['+{{ $ligne->id }}+'-'+ri+'][magasin_id]'" x-model="row.magasin_id"
                                                        class="form-select form-select-sm" :required="Number(row.quantite_pieces) > 0">
                                                    <option value="">— Destination —</option>
                                                    @foreach ($magasins as $magasinOption)
                                                        <option value="{{ $magasinOption->id }}">{{ $magasinOption->nom }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-3 col-md-3">
                                                <label class="form-label small mb-0">Prix réel HT/pièce</label>
                                                <input type="number" :name="'lignes['+{{ $ligne->id }}+'-'+ri+'][prix_achat_reel]'"
                                                       x-model.number="row.prix_achat_reel" min="0" step="1"
                                                       class="form-control form-control-sm text-end">
                                            </div>
                                            <div class="col-3 col-md-3">
                                                <label class="form-label small mb-0">Qté reçue</label>
                                                <input type="number" :name="'lignes['+{{ $ligne->id }}+'-'+ri+'][quantite_pieces]'"
                                                       :value="row.quantite_pieces"
                                                       @input="row.quantite_pieces = clamperQuantite({{ $ligne->id }}, ri, {{ $reste }}, $event.target.value); $event.target.value = row.quantite_pieces"
                                                       min="0" step="0.001" class="form-control form-control-sm text-end">
                                                <input type="hidden" :name="'lignes['+{{ $ligne->id }}+'-'+ri+'][ligne_commande_achat_id]'" value="{{ $ligne->id }}">
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <button type="button" class="btn btn-sm btn-icon btn-outline-danger" @click="retirerDestination({{ $ligne->id }}, ri)"
                                                        x-show="groupes[{{ $ligne->id }}].length > 1" title="Retirer cette destination">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </template>

                                    <button type="button" class="btn btn-sm btn-outline-info mt-1" @click="ajouterDestination({{ $ligne->id }}, {{ $ligne->prixAchatParPiece() }})"
                                            :disabled="resteGroupe({{ $ligne->id }}, {{ $reste }}) <= 0">
                                        <i class="bi bi-plus-lg"></i> Ajouter une destination
                                    </button>
                                </div>
                                </div>
                            @endforeach
                            </div>

                            <label class="form-label small">Paiement (optionnel)</label>
                            <template x-for="(paiement, index) in paiements" :key="index">
                                <div class="row g-1 align-items-center mb-2">
                                    <div class="col-6 col-md-5">
                                        <select :name="'paiements['+index+'][moyen_paiement_id]'" x-model="paiement.moyen_paiement_id" class="form-select form-select-sm">
                                            <option value="">Moyen…</option>
                                            @foreach ($moyensPaiement as $moyen)
                                                <option value="{{ $moyen->id }}">{{ $moyen->nom }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-4">
                                        <input type="number" :name="'paiements['+index+'][montant]'" x-model.number="paiement.montant" min="1" class="form-control form-control-sm" placeholder="Montant">
                                    </div>
                                    <div class="col-2">
                                        <button type="button" class="btn btn-sm btn-icon btn-outline-danger" @click="retirerPaiement(index)">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                </div>
                            </template>
                            <button type="button" class="btn btn-sm btn-outline-info" @click="ajouterPaiement()">
                                <i class="bi bi-plus-lg"></i> Ajouter un paiement
                            </button>
                            <p class="small text-secondary mt-2 mb-0">
                                Laissez vide pour ne rien encaisser maintenant (dette fournisseur intégrale pour ce
                                lot).
                            </p>

                            <div class="mb-2 mt-3">
                                <label for="reception-achat-motif" class="form-label small">Motif (optionnel)</label>
                                <textarea name="motif" id="reception-achat-motif" class="form-control form-control-sm" rows="2"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-link" data-bs-dismiss="modal">Fermer</button>
                            <button type="submit" class="btn btn-info" :disabled="total <= 0">Enregistrer la réception</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($afficheAnnuler)
        <div class="modal fade" id="annulerAchatModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form id="formAnnulerAchat" method="POST" action="{{ route('commande-achats.annuler', $commande) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title text-danger"><i class="bi bi-x-circle me-1"></i>Annuler le bon de commande</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-secondary">
                                Si le stock a déjà été mouvementé pour cette commande, il sera décrémenté en
                                conséquence (échoue si une partie a déjà été vendue ou transférée ailleurs). Cette
                                action est irréversible.
                            </p>
                            <label for="motif-annulation-achat" class="form-label small">Motif de l'annulation (obligatoire)</label>
                            <textarea name="motif" id="motif-annulation-achat" class="form-control form-control-sm" rows="2" required></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-link" data-bs-dismiss="modal">Fermer</button>
                            <button type="submit" class="btn btn-danger">Confirmer l'annulation</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($afficheRetourner && $lignesRetournables->isNotEmpty())
        <div class="modal fade" id="retourAchatModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content"
                     x-data="{
                        lignes: {{ $lignesRetournables->mapWithKeys(fn ($item, $index) => [$index => 0])->toJson() }},
                        get total() { return Object.values(this.lignes).reduce((s, q) => s + (Number(q) || 0), 0); },
                     }">
                    <form method="POST" action="{{ route('commande-achats.retours.store', $commande) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-arrow-return-left me-1"></i>Retourner au fournisseur</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-secondary">
                                Quantité à rendre au fournisseur par article (unité de base — pièce). Le stock est
                                repris à l'endroit où il a été reçu, et un avoir est crédité au compte du fournisseur.
                            </p>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>Produit</th>
                                            <th>Destination</th>
                                            <th class="text-end">Reçu</th>
                                            <th class="text-end">Déjà retourné</th>
                                            <th class="text-end" style="width: 140px;">À retourner</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($lignesRetournables as $index => $item)
                                            <tr>
                                                <td>
                                                    {{ $item->ligne->produit->libelle_affichage }}
                                                    <input type="hidden" name="lignes[{{ $index }}][ligne_commande_achat_id]" value="{{ $item->ligne->id }}">
                                                    <input type="hidden" name="lignes[{{ $index }}][magasin_id]" value="{{ $item->magasin->id }}">
                                                </td>
                                                <td>{{ $item->magasin->nom }}</td>
                                                <td class="text-end">{{ quantite($item->quantiteRecue) }}</td>
                                                <td class="text-end">{{ quantite($item->dejaRetourne) }}</td>
                                                <td>
                                                    <input type="number" name="lignes[{{ $index }}][quantite_pieces]"
                                                           x-model.number="lignes[{{ $index }}]"
                                                           min="0" step="0.001" max="{{ $item->reste }}"
                                                           class="form-control form-control-sm text-end">
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="mb-2">
                                <label for="retour-achat-motif" class="form-label small">Motif (optionnel)</label>
                                <textarea name="motif" id="retour-achat-motif" class="form-control form-control-sm" rows="2"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-link" data-bs-dismiss="modal">Fermer</button>
                            <button type="submit" class="btn btn-info" :disabled="total <= 0">Enregistrer le retour</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endsection
