@extends('layouts.app')

@section('title', "{$vente->type->libelle()} {$vente->numero}")

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <div>
            <div class="d-flex align-items-center gap-2">
                {{-- Un utilisateur n'ayant que vente.livrer (pas caisse.ouvrir)
                     peut atteindre cette page depuis /bons-livraison sans
                     jamais pouvoir ouvrir sessions.show — le retour pointe
                     alors vers la liste des livraisons plutôt qu'un 403. --}}
                <x-bouton-retour :route="request()->user()->can('caisse.ouvrir') ? route('sessions.show', $vente->sessionCaisse) : route('bons-livraison.index')" />
                <h2 class="h4 mb-0">Détail {{ $vente->estBonLivraison() ? 'du bon de livraison' : 'de la facture' }}</h2>
            </div>
            <div class="text-secondary small ms-5 ps-1">
                <code>{{ $vente->numero }}</code>
                @if ($vente->estBonLivraison())
                    <span class="badge {{ $vente->type->classeBadge() }} ms-1">{{ $vente->type->libelle() }}</span>
                @endif
            </div>
        </div>
        @can('vente.creer')
            {{-- $sessionOuverte : la session ouverte de L'UTILISATEUR COURANT
                 (déjà chargée pour le bouton "Régler", règle 14), jamais celle
                 de CETTE facture précise — souvent déjà clôturée (vieille
                 facture), ce qui faisait 403 ("Cette session n'est plus
                 ouverte"). Avec une session ouverte : va droit à la création.
                 Sinon : sessions.index pour en choisir/ouvrir une. --}}
            <a href="{{ $sessionOuverte ? route('ventes.create', $sessionOuverte) : route('sessions.index') }}" class="btn btn-primary">
                <i class="bi bi-cart-plus me-1"></i>Nouvelle facture
            </a>
        @endcan
    </div>

    @if ($vente->trashed())
        <div class="alert alert-danger mx-auto mb-3 d-print-none" style="max-width: 420px;">
            <i class="bi bi-x-circle-fill me-1"></i>
            <strong>Vente annulée</strong> par {{ $vente->annulateur?->name ?? 'un utilisateur supprimé' }}
            le {{ $vente->deleted_at->format('d/m/Y H:i') }}.
            <div class="mt-1 fst-italic">{{ $vente->motif_annulation }}</div>
        </div>
    @endif

    @php
        $afficheReglerClient = ! $vente->trashed() && $peutRegler && $vente->soldeDuReel() > 0;
        $afficheSignaler = ! $vente->trashed() && auth()->user()->can('vente.signaler');
        $afficheAnnulerVente = ! $vente->trashed() && auth()->user()->can('vente.annuler');
        $afficheRetourner = ! $vente->trashed() && $peutRetourner;
        $lignesRetournables = $vente->lignes->filter(
            fn ($ligne) => $ligne->quantite_pieces - ($dejaRetourneParLigne[$ligne->id] ?? 0) > 0
        );
        // Un retour n'est possible que le jour même de la vente, et
        // uniquement avec une session de caisse ouverte (voir CLAUDE.md,
        // Retours) — les deux conditions gèlent le bouton indépendamment,
        // avec un message distinct pour chacune.
        $retourMemeJour = $vente->created_at->isSameDay(now());
        $afficheLivrer = ! $vente->trashed() && $peutLivrer;
        $lignesLivrables = $vente->lignes->filter(
            fn ($ligne) => $ligne->quantite_pieces - ($dejaLivreParLigne[$ligne->id] ?? 0) > 0
        );
        $totalVenduPieces = $vente->lignes->sum('quantite_pieces');
        $totalLivrePieces = $dejaLivreParLigne->sum();
        $resteALivrerPieces = $totalVenduPieces - $totalLivrePieces;
        $quantiteRetournee = (float) $vente->retours->flatMap->lignes->sum('quantite_pieces');
    @endphp

    {{-- KPI : qui/où/quand, montant (gros chiffre + détail réglé/avoir/retours/
    reste — chaque déduction du "Reste" listée explicitement, pas seulement
    fondue dans le chiffre final), livraison, retours — purement informatif,
    les actions sont regroupées séparément juste en dessous. --}}
    <div class="row g-3 mb-3 d-print-none">
        <div class="col-md-3">
            <div class="card h-100 bg-primary-subtle border-0">
                <div class="card-body">
                    <div class="fw-medium">
                        @if ($vente->client)
                            <a href="{{ route('clients.show', $vente->client) }}">{{ $vente->client->nom }}</a>
                        @else
                            Vente comptant
                        @endif
                    </div>
                    <div class="small text-secondary mt-1">{{ $vente->magasin->nom }} — {{ $vente->sessionCaisse->caisse->nom }}</div>
                    <div class="small text-secondary">{{ $vente->created_at->format('d/m/Y à H:i') }} par {{ $vente->caissier->name }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100 bg-reglement-subtle border-0">
                <div class="card-body">
                    <div class="text-secondary small">Montant à régler</div>
                    <div class="fs-4 fw-bold">{{ montant($vente->total_net) }}</div>
                    @if ($vente->trashed())
                        <div class="small text-secondary">Vente annulée</div>
                    @else
                        <div class="small text-secondary">
                            Déjà réglé : {{ montant($vente->montantRegle()) }}
                            @if ($vente->avoir_applique > 0)
                                · Avoir appliqué : {{ montant($vente->avoir_applique) }}
                            @endif
                            @if ($vente->retours->isNotEmpty())
                                · Retours : {{ montant($vente->retours->sum('montant_total')) }}
                            @endif
                            · Reste : <span class="{{ $vente->soldeDuReel() > 0 ? 'text-danger fw-medium' : '' }}">{{ montant($vente->soldeDuReel()) }}</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100 bg-info-subtle border-0">
                <div class="card-body">
                    <div class="text-secondary small">Livraison</div>
                    <div class="fs-4 fw-bold">{{ $totalVenduPieces }} pièce(s)</div>
                    <div class="small text-secondary">
                        Déjà livré : {{ $totalLivrePieces }}
                        · Reste à livrer : <span class="{{ $resteALivrerPieces > 0 ? 'text-warning-emphasis fw-medium' : '' }}">{{ $resteALivrerPieces }}</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100 bg-warning-subtle border-0">
                <div class="card-body">
                    <div class="text-secondary small">Retours</div>
                    <div class="fs-4 fw-bold">{{ quantite($quantiteRetournee) }} pièce(s)</div>
                    <div class="small text-secondary">
                        {{ $vente->retours->count() }} retour(s) · {{ montant($vente->retours->sum('montant_total')) }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($afficheSignaler && $signalements->isNotEmpty())
        <div class="d-print-none mb-3">
            @foreach ($signalements as $signalement)
                <div class="alert alert-warning small mb-1">
                    <i class="bi bi-flag-fill me-1"></i>
                    Signalée par {{ $signalement->causer?->name ?? 'un utilisateur supprimé' }}
                    le {{ $signalement->created_at->format('d/m/Y H:i') }} :
                    {{ $signalement->properties['motif'] ?? '' }}
                </div>
            @endforeach
        </div>
    @endif

    {{-- Actions métier (chacune ouvre sa propre modale, voir plus bas) à --}}
    {{-- gauche, impression/export à droite — une seule ligne pour ne pas --}}
    {{-- disperser les boutons de la facture sur plusieurs endroits. Une --}}
    {{-- action désactivée garde une info-bulle expliquant pourquoi plutôt --}}
    {{-- que d'être masquée. --}}
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3 d-print-none">
        <div class="d-flex flex-wrap gap-2">
            @if ($afficheReglerClient)
                @if ($sessionOuverte)
                    <button type="button" class="btn btn-reglement btn-sm" data-bs-toggle="modal" data-bs-target="#reglerClientModal">
                        <i class="bi bi-cash-coin me-1"></i>Régler
                    </button>
                @else
                    <a href="{{ route('sessions.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-cash-coin me-1"></i>Ouvrir une caisse pour régler
                    </a>
                @endif
            @endif

            @if ($afficheLivrer)
                <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#livraisonVenteModal"
                        @disabled($lignesLivrables->isEmpty())
                        title="{{ $lignesLivrables->isEmpty() ? 'Tous les articles ont déjà été livrés' : '' }}">
                    <i class="bi bi-truck me-1"></i>Bon de livraison
                </button>
            @endif

            @if ($afficheRetourner)
                <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#retourVenteModal"
                        @disabled($lignesRetournables->isEmpty() || ! $retourMemeJour || ! $sessionOuverte)
                        title="{{ ! $retourMemeJour ? 'Retour possible uniquement le jour de la vente' : (! $sessionOuverte ? 'Ouvrez une session de caisse pour faire un retour' : ($lignesRetournables->isEmpty() ? 'Tous les articles ont déjà été retournés' : '')) }}">
                    <i class="bi bi-arrow-return-left me-1"></i>Retourner
                </button>
            @endif

            @if ($afficheSignaler)
                <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#signalerModal">
                    <i class="bi bi-flag me-1"></i>Signaler
                </button>
            @endif

            @if ($afficheAnnulerVente)
                <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#annulerVenteModal">
                    <i class="bi bi-x-circle me-1"></i>Annuler
                </button>
            @endif
        </div>

        <div class="btn-group">
            <button type="button" class="btn btn-sm btn-outline-secondary"
                onclick="(window.inmystore && window.inmystore.print) ? window.inmystore.print() : window.print()">
                <i class="bi bi-printer me-1"></i>Ticket caisse
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="imprimerFacture()">
                <i class="bi bi-receipt me-1"></i>Facture
            </button>
            <a href="{{ route('ventes.pdf', $vente) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-file-earmark-pdf me-1"></i>PDF
            </a>
            <a href="{{ route('ventes.excel', $vente) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-file-earmark-excel me-1"></i>Excel
            </a>
        </div>
    </div>

    @php $venteTotalTaxes = $vente->totalTaxes(); @endphp
    <div class="card mb-3 d-print-none bg-white">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Désignation</th>
                        <th>Qté</th>
                        <th class="text-end">Prix unitaire</th>
                        <th class="text-end">Remise</th>
                        @if ($venteTotalTaxes > 0)
                            <th>Taxe</th>
                        @endif
                        <th class="text-end">Total</th>
                        <th>Livraison</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($vente->lignes as $ligne)
                        <tr>
                            <td>
                                {{ $ligne->produit->libelle_affichage }}
                                <span class="text-secondary small">({{ $ligne->uniteVente?->libelle ?? $ligne->produit->unite_base_libelle }})</span>
                            </td>
                            <td>{{ quantite($ligne->quantite) }}</td>
                            <td class="text-end">{{ montant($ligne->prixUnitaireEffectif()) }}</td>
                            <td class="text-end text-danger">{{ (! $ligne->prix_personnalise && $ligne->remise_ligne_montant > 0) ? '− '.montant($ligne->remise_ligne_montant) : '—' }}</td>
                            @if ($venteTotalTaxes > 0)
                                <td>{{ $ligne->taxe->nom ?? '—' }}</td>
                            @endif
                            <td class="text-end fw-medium">{{ montant($ligne->total_ligne) }}</td>
                            @php $ligneDejaLivre = $dejaLivreParLigne[$ligne->id] ?? 0; @endphp
                            <td>
                                <span class="{{ (float) $ligneDejaLivre >= (float) $ligne->quantite_pieces ? 'text-success' : ($ligneDejaLivre > 0 ? 'text-warning-emphasis' : 'text-secondary') }}">
                                    {{ quantite($ligneDejaLivre) }}/{{ quantite($ligne->quantite_pieces) }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Petit tableau de totaux/règlement, même structure et même ordre que --}}
    {{-- la facture imprimée (voir ventes/facture.blade.php, table.totaux) --}}
    {{-- — juste en dessous du tableau des produits, pas une grosse carte. --}}
    <div class="d-flex justify-content-end mb-3 d-print-none">
        <table class="table table-sm mb-0" style="max-width: 380px;">
            <tbody>
                <tr>
                    <td>Sous-total (HT)</td>
                    <td class="text-end">{{ montant($vente->sous_total) }}</td>
                </tr>
                @if ($venteTotalTaxes > 0)
                    <tr>
                        <td>Total taxes</td>
                        <td class="text-end">{{ montant($venteTotalTaxes) }}</td>
                    </tr>
                @endif
                @if ($vente->remise_totale_montant > 0)
                    <tr>
                        <td>Remise</td>
                        <td class="text-end text-danger">− {{ montant($vente->remise_totale_montant) }}</td>
                    </tr>
                @endif
                <tr class="fw-bold border-top">
                    <td>Net à payer</td>
                    <td class="text-end">{{ montant($vente->total_net) }}</td>
                </tr>
                @foreach ($vente->paiements as $paiement)
                    <tr>
                        <td>{{ $paiement->moyenPaiement->nom }}</td>
                        <td class="text-end">{{ montant($paiement->montant) }}</td>
                    </tr>
                @endforeach
                @if ($vente->monnaie_rendue > 0)
                    <tr>
                        <td>Monnaie rendue</td>
                        <td class="text-end">{{ montant($vente->monnaie_rendue) }}</td>
                    </tr>
                @endif
                @if ($vente->soldeDu() > 0)
                    <tr>
                        <td>Montant payé</td>
                        <td class="text-end">{{ montant($vente->montantRegle()) }}</td>
                    </tr>
                    @if ($vente->avoir_applique > 0)
                        <tr>
                            <td>Avoir appliqué</td>
                            <td class="text-end">{{ montant($vente->avoir_applique) }}</td>
                        </tr>
                    @endif
                    <tr class="fw-semibold {{ $vente->soldeDuReel() > 0 ? 'text-danger' : '' }}">
                        <td>Reste à payer</td>
                        <td class="text-end">{{ montant($vente->soldeDuReel()) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    @if ($vente->retours->isNotEmpty())
        <div class="card mb-3 d-print-none bg-warning-subtle border-0">
            <div class="card-body">
                <h3 class="h6 text-warning-emphasis"><i class="bi bi-arrow-return-left me-1"></i>Retours</h3>
                @foreach ($vente->retours as $retour)
                    <div class="small border-bottom border-warning-subtle py-1 text-warning-emphasis">
                        <div class="d-flex justify-content-between">
                            <span>
                                <code>{{ $retour->numero }}</code>
                                du {{ $retour->created_at->format('d/m/Y H:i') }}
                                par {{ $retour->auteur?->name ?? 'utilisateur supprimé' }}
                            </span>
                            <span class="fw-medium">Avoir {{ montant($retour->montant_total) }}</span>
                        </div>
                        @if ($retour->client_id !== $vente->client_id)
                            <div class="ps-4">
                                <i class="bi bi-person me-1"></i>Crédité à
                                <a href="{{ route('clients.show', $retour->client) }}" class="text-warning-emphasis fw-medium">{{ $retour->client->nom }}</a>
                            </div>
                        @endif
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

    @if ($vente->bonsLivraison->isNotEmpty())
        <div class="card mb-3 d-print-none bg-white">
            <div class="card-body pb-0">
                <h3 class="h6">Livraisons</h3>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Numéro</th>
                            <th>Date</th>
                            <th>Par</th>
                            <th>Articles</th>
                            <th>Statut</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($vente->bonsLivraison as $bonLivraison)
                            <tr class="{{ $bonLivraison->trashed() ? 'text-secondary' : '' }}">
                                <td><code>{{ $bonLivraison->numero }}</code></td>
                                <td>{{ $bonLivraison->created_at->format('d/m/Y H:i') }}</td>
                                <td>{{ $bonLivraison->auteur?->name ?? 'utilisateur supprimé' }}</td>
                                <td>
                                    @foreach ($bonLivraison->lignes as $ligneBonLivraison)
                                        <div class="small {{ $loop->last ? '' : 'mb-1' }}">
                                            {{ $ligneBonLivraison->produit->libelle_affichage }} × {{ quantite($ligneBonLivraison->quantite_pieces) }}
                                            <span class="text-secondary">({{ $ligneBonLivraison->magasin->nom }})</span>
                                        </div>
                                    @endforeach
                                </td>
                                <td>
                                    @if ($bonLivraison->trashed())
                                        <span class="badge text-bg-secondary" title="{{ $bonLivraison->motif_annulation }}">Annulé</span>
                                    @else
                                        <span class="badge text-bg-success-subtle text-success-emphasis">Actif</span>
                                    @endif
                                    @if ($bonLivraison->motif && ! $bonLivraison->trashed())
                                        <div class="small text-secondary fst-italic mt-1">{{ $bonLivraison->motif }}</div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-icon btn-outline-secondary"
                                            onclick="imprimerBonLivraison('{{ route('bons-livraison.pdf', $bonLivraison) }}?imprimer=1')"
                                            title="Imprimer le bon de livraison">
                                        <i class="bi bi-printer"></i>
                                        <span class="visually-hidden">Imprimer</span>
                                    </button>
                                    <a href="{{ route('bons-livraison.pdf', $bonLivraison) }}" class="btn btn-sm btn-icon btn-outline-secondary" title="Télécharger le PDF">
                                        <i class="bi bi-file-earmark-pdf"></i>
                                        <span class="visually-hidden">PDF</span>
                                    </a>
                                    @if (! $bonLivraison->trashed() && $peutLivrer)
                                        <button type="button" class="btn btn-sm btn-icon btn-outline-danger" data-bs-toggle="modal" data-bs-target="#annulerLivraisonModal{{ $bonLivraison->id }}" title="Annuler ce bon de livraison">
                                            <i class="bi bi-x-lg"></i>
                                            <span class="visually-hidden">Annuler</span>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @foreach ($vente->bonsLivraison as $bonLivraison)
            @if (! $bonLivraison->trashed() && $peutLivrer)
                <div class="modal fade d-print-none" id="annulerLivraisonModal{{ $bonLivraison->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="{{ route('bons-livraison.annuler', $bonLivraison) }}">
                                @csrf
                                <div class="modal-header">
                                    <h5 class="modal-title">Annuler le bon de livraison {{ $bonLivraison->numero }}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                                </div>
                                <div class="modal-body">
                                    <label for="motif-annulation-livraison-{{ $bonLivraison->id }}" class="form-label small">Motif (obligatoire)</label>
                                    <textarea name="motif" id="motif-annulation-livraison-{{ $bonLivraison->id }}" class="form-control form-control-sm" rows="2" required></textarea>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-link" data-bs-dismiss="modal">Fermer</button>
                                    <button type="submit" class="btn btn-danger">Annuler ce bon de livraison</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
        @endforeach
    @endif

    <div class="card recu-pos mx-auto d-none d-print-block" style="max-width: 420px;">
        <div class="card-body">
            @if ($vente->trashed())
                <div class="text-center mb-2">
                    <span class="badge text-bg-danger fs-6">ANNULÉE</span>
                </div>
            @endif
            <div class="text-center mb-3">
                <div class="fw-bold">{{ $vente->magasin->nom }}</div>
                <div class="small text-secondary">{{ $vente->sessionCaisse->caisse->nom }}</div>
                <div class="small text-secondary">{{ $vente->created_at->format('d/m/Y H:i') }}</div>
                <div class="fw-medium mt-1"><code>{{ $vente->numero }}</code></div>
                @if ($vente->client)
                    <div class="small text-secondary mt-1">
                        Client : <a href="{{ route('clients.show', $vente->client) }}">{{ $vente->client->nom }}</a>
                    </div>
                @endif
            </div>

            <table class="table table-sm">
                <tbody>
                    @foreach ($vente->lignes as $ligne)
                        <tr>
                            <td>
                                {{ $ligne->produit->libelle_affichage }}
                                <span class="text-secondary small">({{ $ligne->uniteVente?->libelle ?? $ligne->produit->unite_base_libelle }})</span>
                                <br>
                                <span class="text-secondary small">{{ quantite($ligne->quantite) }} × {{ montant($ligne->prixUnitaireEffectif()) }}</span>
                                @if (! $ligne->prix_personnalise && $ligne->remise_ligne_montant > 0)
                                    <br><span class="text-danger small">Remise : − {{ montant($ligne->remise_ligne_montant) }}</span>
                                @endif
                                @if ($ligne->taxe)
                                    <br><span class="text-secondary small">{{ $ligne->taxe->nom }} ({{ $ligne->taxe->taux }}%)</span>
                                @endif
                            </td>
                            <td class="text-end align-top">{{ montant($ligne->total_ligne) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <table class="table table-sm mb-0">
                <tr>
                    <td>Sous-total (HT)</td>
                    <td class="text-end">{{ montant($vente->sous_total) }}</td>
                </tr>
                @if ($venteTotalTaxes > 0)
                    <tr>
                        <td>Total taxes</td>
                        <td class="text-end">{{ montant($venteTotalTaxes) }}</td>
                    </tr>
                @endif
                @if ($vente->remise_totale_montant > 0)
                    <tr>
                        <td>Remise</td>
                        <td class="text-end text-danger">− {{ montant($vente->remise_totale_montant) }}</td>
                    </tr>
                @endif
                <tr class="fw-bold fs-5">
                    <td>Net à payer</td>
                    <td class="text-end">{{ montant($vente->total_net) }}</td>
                </tr>
            </table>

            <hr>

            <table class="table table-sm mb-0">
                @foreach ($vente->paiements as $paiement)
                    <tr>
                        <td>{{ $paiement->moyenPaiement->nom }}</td>
                        <td class="text-end">{{ montant($paiement->montant) }}</td>
                    </tr>
                @endforeach
                @if ($vente->monnaie_rendue > 0)
                    <tr>
                        <td>Reçu</td>
                        <td class="text-end">{{ montant($vente->montant_recu) }}</td>
                    </tr>
                    <tr class="fw-medium">
                        <td>Monnaie rendue</td>
                        <td class="text-end">{{ montant($vente->monnaie_rendue) }}</td>
                    </tr>
                @endif
                @if ($vente->soldeDu() > 0)
                    <tr class="fw-medium">
                        <td>Montant payé</td>
                        <td class="text-end">{{ montant($vente->montantRegle()) }}</td>
                    </tr>
                    @if ($vente->avoir_applique > 0)
                        <tr>
                            <td>Avoir appliqué</td>
                            <td class="text-end">{{ montant($vente->avoir_applique) }}</td>
                        </tr>
                    @endif
                    <tr class="fw-bold {{ $vente->soldeDuReel() > 0 ? 'text-danger' : '' }}">
                        <td>Reste à payer</td>
                        <td class="text-end">{{ montant($vente->soldeDuReel()) }}</td>
                    </tr>
                @endif
            </table>

            <div class="text-center text-secondary small mt-3">
                Merci de votre visite !
                @if ($parametre->numero || $parametre->adresse || $parametre->slogan)
                    <hr>
                @endif
                @if ($parametre->slogan)
                    <div>{{ $parametre->slogan }}</div>
                @endif
                @if ($parametre->numero)
                    <div>{{ $parametre->numero }}</div>
                @endif
                @if ($parametre->adresse)
                    <div>{{ $parametre->adresse }}</div>
                @endif
            </div>
        </div>
    </div>

    {{-- Contenu au format facture, masqué à l'écran : le bouton "Facture"
         imprime ce bloc en place (voir imprimerFacture() ci-dessous), sans
         redirection ni nouvel onglet — même geste que "Ticket caisse". --}}
    <div id="factureImprimable" class="d-none">
        @if ($vente->trashed())
            <div class="badge-annulee">VENTE ANNULÉE</div>
        @endif

        <table class="entete">
            <tr>
                <td style="width: 55%;">
                    @if ($logoDataUri)
                        <img src="{{ $logoDataUri }}" class="logo" alt="Logo">
                        <br>
                    @endif
                    <span class="entreprise-nom">{{ $parametre->nom }}</span><br>
                    @if ($parametre->adresse) {{ $parametre->adresse }}<br> @endif
                    @if ($parametre->numero) Tél : {{ $parametre->numero }} @endif
                </td>
                <td style="width: 45%;">
                    <div class="facture-titre">{{ \Illuminate\Support\Str::upper($vente->type->libelle()) }}</div>
                    <div class="facture-meta">
                        N° {{ $vente->numero }}<br>
                        Date : {{ $vente->created_at->format('d/m/Y à H:i') }}<br>
                        Magasin : {{ $vente->magasin->nom }}<br>
                        Caisse : {{ $vente->sessionCaisse->caisse->nom }}<br>
                        Caissier : {{ $vente->caissier->name }}
                    </div>
                </td>
            </tr>
        </table>

        <div class="bloc-client">
            <div class="label">Client</div>
            <strong>{{ $vente->client->nom ?? 'Client comptant' }}</strong><br>
            @if ($vente->client?->telephone) Tél : {{ $vente->client->telephone }}<br> @endif
            @if ($vente->client?->adresse) {{ $vente->client->adresse }} @endif
        </div>

        <table class="lignes">
            {{-- Mêmes largeurs que ventes/facture.blade.php (sans taxe, ce
                 bloc n'en affiche jamais) : Désignation la plus large,
                 Unité/Qté/Remise resserrées sur leur contenu réel. --}}
            <colgroup>
                <col style="width: 38%;">
                <col style="width: 10%;">
                <col style="width: 8%;">
                <col style="width: 16%;">
                <col style="width: 10%;">
                <col style="width: 18%;">
            </colgroup>
            <thead>
                <tr>
                    <th>Désignation</th>
                    <th>Unité</th>
                    <th class="text-end">Qté</th>
                    <th class="text-end">P.u</th>
                    <th class="text-end">Remise</th>
                    <th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($vente->lignes as $ligne)
                    <tr>
                        <td>{{ $ligne->produit->libelle_affichage }}</td>
                        <td>{{ $ligne->uniteVente->libelle ?? $ligne->produit->unite_base_libelle }}</td>
                        <td class="text-end">{{ quantite($ligne->quantite) }}</td>
                        <td class="text-end">{{ montant($ligne->prix_unitaire_applique) }}</td>
                        <td class="text-end">{{ $ligne->remise_ligne_montant > 0 ? '− '.montant($ligne->remise_ligne_montant) : '—' }}</td>
                        <td class="text-end">{{ montant($ligne->total_ligne) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totaux">
            <tr>
                <td>Sous-total</td>
                <td class="text-end">{{ montant($vente->sous_total) }}</td>
            </tr>
            @if ($vente->remise_totale_montant > 0)
                <tr>
                    <td>Remise</td>
                    <td class="text-end">− {{ montant($vente->remise_totale_montant) }}</td>
                </tr>
            @endif
            <tr class="net">
                <td>Net à payer</td>
                <td class="text-end">{{ montant($vente->total_net) }}</td>
            </tr>
            @foreach ($vente->paiements as $paiement)
                <tr>
                    <td>{{ $paiement->moyenPaiement->nom }}</td>
                    <td class="text-end">{{ montant($paiement->montant) }}</td>
                </tr>
            @endforeach
            @if ($vente->monnaie_rendue > 0)
                <tr>
                    <td>Monnaie rendue</td>
                    <td class="text-end">{{ montant($vente->monnaie_rendue) }}</td>
                </tr>
            @endif
            @if ($vente->soldeDu() > 0)
                <tr>
                    <td>Montant payé</td>
                    <td class="text-end">{{ montant($vente->montantRegle()) }}</td>
                </tr>
                @if ($vente->avoir_applique > 0)
                    <tr>
                        <td>Avoir appliqué</td>
                        <td class="text-end">{{ montant($vente->avoir_applique) }}</td>
                    </tr>
                @endif
                <tr class="{{ $vente->soldeDuReel() > 0 ? 'credit' : '' }}">
                    <td>Reste à payer</td>
                    <td class="text-end">{{ montant($vente->soldeDuReel()) }}</td>
                </tr>
            @endif
        </table>

        @if ($vente->estBonLivraison())
            <div class="mention" style="font-weight: bold;">Facture à suivre</div>
        @endif

        <div class="mention">
            Merci de votre confiance.
            @if ($parametre->slogan) {{ $parametre->slogan }} @endif
        </div>
    </div>

    @if ($afficheReglerClient && $sessionOuverte)
        <div class="modal fade d-print-none" id="reglerClientModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content"
                     x-data="{ paiements: [{ moyen_paiement_id: '', montant: {{ $vente->soldeDuReel() }} }],
                        get totalPaiements() { return this.paiements.reduce((total, p) => total + (Number(p.montant) || 0), 0); },
                        ajouterPaiement() { this.paiements.push({ moyen_paiement_id: '', montant: '' }); },
                        retirerPaiement(index) { this.paiements.splice(index, 1); } }">
                    <form id="formReglerClient" method="POST" action="{{ route('reglements.store', $sessionOuverte) }}">
                        @csrf
                        <input type="hidden" name="client_id" value="{{ $vente->client_id }}">
                        <input type="hidden" name="vente_id" value="{{ $vente->id }}">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-cash-coin me-1"></i>Règlement client</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-secondary mb-2">
                                Reste dû sur cette facture : <strong>{{ montant($vente->soldeDuReel()) }}</strong><br>
                                Solde total du client (toutes factures confondues) : {{ montant($vente->client->solde()) }}
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
                                        <input type="number" :name="'paiements['+index+'][montant]'" x-model.number="paiement.montant" min="1" class="form-control form-control-sm" placeholder="Montant" required>
                                    </div>
                                    <div class="col-2">
                                        <button type="button" class="btn btn-sm btn-icon btn-outline-danger" @click="retirerPaiement(index)" x-show="paiements.length > 1">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                </div>
                            </template>
                            <button type="button" class="btn btn-sm btn-outline-reglement" @click="ajouterPaiement()">
                                <i class="bi bi-plus-lg"></i> Ajouter un paiement
                            </button>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-link" data-bs-dismiss="modal">Fermer</button>
                            <button type="submit" class="btn btn-reglement" :disabled="totalPaiements <= 0">Enregistrer le règlement</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($afficheSignaler)
        <div class="modal fade d-print-none" id="signalerModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ route('ventes.signaler', $vente) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-flag me-1"></i>Signaler un problème</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-secondary">Ex. vente enregistrée deux fois par erreur.</p>
                            <label for="motif-signalement" class="form-label small">Motif</label>
                            <textarea name="motif" id="motif-signalement" class="form-control form-control-sm" rows="2" required></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-link" data-bs-dismiss="modal">Fermer</button>
                            <button type="submit" class="btn btn-warning">Envoyer le signalement</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($afficheAnnulerVente)
        <div class="modal fade d-print-none" id="annulerVenteModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form id="formAnnulerVente" method="POST" action="{{ route('ventes.annuler', $vente) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title text-danger"><i class="bi bi-x-circle me-1"></i>Annuler la vente</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-secondary">Le stock sera remis à jour automatiquement. Cette action est irréversible.</p>
                            <label for="motif-annulation" class="form-label small">Motif de l'annulation (obligatoire)</label>
                            <textarea name="motif" id="motif-annulation" class="form-control form-control-sm" rows="2" required></textarea>
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
        @php
            // Client réellement crédité, connu à l'avance uniquement si la
            // vente en a un — sinon choisi/créé dans le formulaire ci-dessous
            // (jamais un repli silencieux sur "Client comptant" : plusieurs
            // personnes différentes font des ventes comptant, voir CLAUDE.md
            // Retours). Le solde estimé sert juste à préremplir le montant
            // du remboursement immédiat ; le plafond réel est toujours revérifié
            // côté serveur.
            $clientACrediter = $vente->client;
            $soldeActuelEstime = $clientACrediter?->solde() ?? 0;
        @endphp
        <div class="modal fade d-print-none" id="retourVenteModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content"
                     x-data="{
                        lignes: {{ $lignesRetournables->mapWithKeys(fn ($l) => [$l->id => 0])->toJson() }},
                        max: {{ $lignesRetournables->mapWithKeys(fn ($l) => [$l->id => $l->quantite_pieces - ($dejaRetourneParLigne[$l->id] ?? 0)])->toJson() }},
                        valeurPiece: {{ $lignesRetournables->mapWithKeys(fn ($l) => [$l->id => $l->quantite_pieces > 0 ? $l->total_ligne / $l->quantite_pieces : 0])->toJson() }},
                        soldeActuel: {{ (int) $soldeActuelEstime }},
                        // Rembourser maintenant par défaut (retour = même jour +
                        // session ouverte désormais, le client est là) — mais reste
                        // un choix, pas une obligation (tiroir court, client à
                        // crédit qui préfère garder ça en avoir). Reste en mode avoir
                        // si l'utilisateur n'a même pas la permission de rembourser
                        // (les champs de paiement ne s'afficheraient pas).
                        mode: '{{ $peutRembourserAvoir ? 'remboursement' : 'avoir' }}',
                        // Espèces présélectionné (cas quasi systématique d'un
                        // remboursement immédiat au comptoir) — évite une étape
                        // de saisie en plus, modifiable si besoin.
                        paiements: [{ moyen_paiement_id: {{ $moyensPaiement->firstWhere('est_espece', true)?->id ?? 'null' }}, montant: null }],
                        get total() { return Object.values(this.lignes).reduce((s, q) => s + (Number(q) || 0), 0); },
                        get montantEstime() { return Object.entries(this.lignes).reduce((s, [id, q]) => s + (Number(q) || 0) * this.valeurPiece[id], 0); },
                        get avoirDisponibleApres() { return Math.max(0, Math.round(this.montantEstime - this.soldeActuel)); },
                        get totalPaiements() { return this.paiements.reduce((s, p) => s + (Number(p.montant) || 0), 0); },
                        ajouterPaiement() { this.paiements.push({ moyen_paiement_id: '', montant: null }); },
                        retirerPaiement(index) { if (this.paiements.length > 1) this.paiements.splice(index, 1); },
                        activerRemboursement() {
                            this.paiements = [{ moyen_paiement_id: this.paiements[0].moyen_paiement_id, montant: this.avoirDisponibleApres || null }];
                        },
                     }">
                    <form method="POST" action="{{ route('ventes.retours.store', $vente) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-arrow-return-left me-1"></i>Retourner des articles</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-secondary">
                                Quantité à retourner par article (unité de base — pièce). Le stock est remis à
                                disposition.
                                @if ($clientACrediter)
                                    Un avoir est crédité au compte du client ({{ $clientACrediter->nom }}).
                                @endif
                            </p>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>Produit</th>
                                            <th class="text-end">Vendu</th>
                                            <th class="text-end">Déjà retourné</th>
                                            <th class="text-end" style="width: 140px;">À retourner</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($lignesRetournables as $ligne)
                                            @php $dejaRetourne = $dejaRetourneParLigne[$ligne->id] ?? 0; @endphp
                                            <tr>
                                                <td>
                                                    {{ $ligne->produit->libelle_affichage }}
                                                    <input type="hidden" name="lignes[{{ $ligne->id }}][ligne_vente_id]" value="{{ $ligne->id }}">
                                                </td>
                                                <td class="text-end">{{ quantite($ligne->quantite_pieces) }}</td>
                                                <td class="text-end">{{ quantite($dejaRetourne) }}</td>
                                                <td>
                                                    <input type="number" name="lignes[{{ $ligne->id }}][quantite_pieces]"
                                                           x-model.number="lignes[{{ $ligne->id }}]"
                                                           @input="lignes[{{ $ligne->id }}] = Math.max(0, Math.min(Number($event.target.value) || 0, max[{{ $ligne->id }}]));
                                                                   if (mode === 'remboursement') paiements[0].montant = avoirDisponibleApres || null"
                                                           min="0" step="0.001" max="{{ (float) $ligne->quantite_pieces - $dejaRetourne }}"
                                                           class="form-control form-control-sm text-end">
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="mb-2">
                                <label for="retour-motif" class="form-label small">Motif (optionnel)</label>
                                <textarea name="motif" id="retour-motif" class="form-control form-control-sm" rows="2"></textarea>
                            </div>

                            <div class="border-top pt-2 mt-2">
                                @if ($peutRembourserAvoir)
                                    <label class="form-label small d-block">Que faire de l'avoir ?</label>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" id="retour-mode-remboursement" value="remboursement" checked
                                               x-model="mode" @change="activerRemboursement()">
                                        <label class="form-check-label" for="retour-mode-remboursement">Rembourser maintenant</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" id="retour-mode-avoir" value="avoir" x-model="mode">
                                        <label class="form-check-label" for="retour-mode-avoir">Créer un avoir pour ce client</label>
                                    </div>
                                    <input type="hidden" name="rembourser_immediat" :value="mode === 'remboursement' ? 1 : 0">
                                @endif

                                @if (! $vente->client)
                                    <div class="mt-2" x-show="mode === 'avoir'" x-cloak>
                                        <label for="retour-client-picker" class="form-label small">
                                            Client à créditer<span class="required-marker">*</span>
                                        </label>
                                        <div class="d-flex gap-1">
                                            <select name="client_id_retour" id="retour-client-picker" class="form-select form-select-sm" :required="mode === 'avoir'">
                                                <option value="">— Choisir —</option>
                                                @foreach ($clientsPourRetour as $c)
                                                    <option value="{{ $c->id }}" @selected($c->comptant_defaut)>
                                                        {{ $c->nom }}{{ $c->telephone ? ' — '.$c->telephone : '' }}{{ $c->comptant_defaut ? ' (identité inconnue)' : '' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @can('client.gerer')
                                                <button type="button" class="btn btn-sm btn-outline-primary btn-icon flex-shrink-0" title="Nouveau client"
                                                        onclick="window.ouvrirClientRapide('retour-client-picker')">
                                                    <i class="bi bi-person-plus"></i>
                                                    <span class="visually-hidden">Nouveau client</span>
                                                </button>
                                            @endcan
                                        </div>
                                        <div class="form-text small">
                                            Vente comptant (aucun client rattaché à la facture) : indiquez qui reprend
                                            l'article, pour ne pas mélanger son avoir avec celui d'autres clients de
                                            passage. Choisissez "identité inconnue" seulement si le client ne se
                                            présente vraiment pas.
                                        </div>
                                    </div>
                                @endif

                                @if ($peutRembourserAvoir)
                                    <div x-show="mode === 'remboursement'" x-cloak class="mt-2">
                                        <p class="small text-secondary mb-2">
                                            Avoir disponible après ce retour (estimation) :
                                            <strong x-text="avoirDisponibleApres + ' F'"></strong>
                                        </p>
                                        <template x-for="(paiement, index) in paiements" :key="index">
                                            <div class="row g-1 align-items-center mb-2">
                                                <div class="col-6">
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
                                                    <button type="button" class="btn btn-sm btn-icon btn-outline-danger" @click="retirerPaiement(index)" x-show="paiements.length > 1">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </template>
                                        <button type="button" class="btn btn-sm btn-outline-primary" @click="ajouterPaiement()">
                                            <i class="bi bi-plus-lg"></i> Ajouter un moyen de paiement
                                        </button>
                                        <div class="mt-2 small" :class="totalPaiements > avoirDisponibleApres ? 'text-danger' : 'text-secondary'">
                                            Total remboursé : <span x-text="totalPaiements"></span> F
                                            <span x-show="totalPaiements > avoirDisponibleApres">— dépasse l'avoir disponible estimé.</span>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-link" data-bs-dismiss="modal">Fermer</button>
                            <button type="submit" class="btn btn-info"
                                    :disabled="total <= 0 || (mode === 'remboursement' && (totalPaiements <= 0 || totalPaiements > avoirDisponibleApres))">
                                Enregistrer le retour
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        @if (! $vente->client)
            @include('partials.client-rapide-modal')
        @endif
    @endif

    @if ($afficheLivrer && $lignesLivrables->isNotEmpty())
        <div class="modal fade d-print-none" id="livraisonVenteModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content"
                     x-data="{
                        // Pré-rempli au reste à livrer (cas le plus fréquent :
                        // tout remettre d'un coup) — l'utilisateur réduit la
                        // valeur lui-même pour une livraison partielle.
                        lignes: {{ $lignesLivrables->mapWithKeys(fn ($l) => [$l->id => (float) $l->quantite_pieces - ($dejaLivreParLigne[$l->id] ?? 0)])->toJson() }},
                        max: {{ $lignesLivrables->mapWithKeys(fn ($l) => [$l->id => $l->quantite_pieces - ($dejaLivreParLigne[$l->id] ?? 0)])->toJson() }},
                        get total() { return Object.values(this.lignes).reduce((s, q) => s + (Number(q) || 0), 0); },
                     }">
                    <form method="POST" action="{{ route('ventes.bons-livraison.store', $vente) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-truck me-1"></i>Livrer des articles</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-secondary">
                                Quantité remise physiquement au client aujourd'hui (unité de base — pièce). Ne modifie
                                ni le stock (déjà décrémenté à la vente) ni la caisse.
                            </p>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>Produit</th>
                                            <th class="text-end">Vendu</th>
                                            <th class="text-end">Déjà livré</th>
                                            <th class="text-end">Reste à livrer</th>
                                            <th class="text-end" style="width: 140px;">À livrer</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($lignesLivrables as $ligne)
                                            @php $dejaLivre = $dejaLivreParLigne[$ligne->id] ?? 0; @endphp
                                            <tr>
                                                <td>
                                                    {{ $ligne->produit->libelle_affichage }}
                                                    <input type="hidden" name="lignes[{{ $ligne->id }}][ligne_vente_id]" value="{{ $ligne->id }}">
                                                </td>
                                                <td class="text-end">{{ quantite($ligne->quantite_pieces) }}</td>
                                                <td class="text-end">{{ quantite($dejaLivre) }}</td>
                                                <td class="text-end">{{ quantite((float) $ligne->quantite_pieces - $dejaLivre) }}</td>
                                                <td>
                                                    <input type="number" name="lignes[{{ $ligne->id }}][quantite_pieces]"
                                                           x-model.number="lignes[{{ $ligne->id }}]"
                                                           min="0" step="0.001" max="{{ (float) $ligne->quantite_pieces - $dejaLivre }}"
                                                           class="form-control form-control-sm text-end">
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="mb-2">
                                <label for="livraison-motif" class="form-label small">Motif (optionnel)</label>
                                <textarea name="motif" id="livraison-motif" class="form-control form-control-sm" rows="2"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-link" data-bs-dismiss="modal">Fermer</button>
                            <button type="submit" class="btn btn-info" :disabled="total <= 0">Enregistrer la livraison</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        // Imprime le PDF réel (dompdf, route ventes.pdf), plutôt que de
        // basculer l'affichage sur #factureImprimable et d'imprimer cette
        // page HTML : rendu garanti identique à "Télécharger en PDF" (dompdf
        // a un support CSS différent d'un navigateur/Electron — voir
        // x-bouton-imprimer pour le même mécanisme).
        function imprimerFacture() {
            const url = '{{ route('ventes.pdf', $vente) }}?imprimer=1';
            if (window.inmystore && window.inmystore.printPdfUrl) {
                window.inmystore.printPdfUrl(url);
                return;
            }
            let iframe = document.getElementById('__iframeFacturePdf');
            if (! iframe) {
                iframe = document.createElement('iframe');
                iframe.id = '__iframeFacturePdf';
                iframe.style.display = 'none';
                document.body.appendChild(iframe);
            }
            iframe.onload = function () {
                setTimeout(() => {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                }, 200);
            };
            iframe.src = url;
        }

        // Même mécanique que imprimerFacture() ci-dessus, réutilisée pour
        // chaque bon de livraison de la ligne "Imprimer" du tableau
        // Livraisons — un seul iframe caché partagé entre les appels.
        function imprimerBonLivraison(url) {
            if (window.inmystore && window.inmystore.printPdfUrl) {
                window.inmystore.printPdfUrl(url);
                return;
            }
            let iframe = document.getElementById('__iframeBonLivraisonPdf');
            if (! iframe) {
                iframe = document.createElement('iframe');
                iframe.id = '__iframeBonLivraisonPdf';
                iframe.style.display = 'none';
                document.body.appendChild(iframe);
            }
            iframe.onload = function () {
                setTimeout(() => {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                }, 200);
            };
            iframe.src = url;
        }
    </script>
@endpush
