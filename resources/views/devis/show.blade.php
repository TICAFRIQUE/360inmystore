@extends('layouts.app')

@section('title', "Devis {$devis->numero}")

@section('content')
    @php
        $statut = $devis->statutEffectif();
        $joursRestants = (int) now()->startOfDay()->diffInDays($devis->date_validite->copy()->startOfDay(), false);
        $peutTransformer = $devis->peutEtreTransforme();
        $stockManquant = $lignesEnRupture->isNotEmpty();
    @endphp

    {{-- En-tête : le titre et son statut à gauche ; à droite les actions,
         de la moins engageante à la principale — documents (un seul menu),
         modifier, puis transformer en vente. Refuser / annuler, irréversible,
         est rangé dans le menu « Plus » pour ne pas être cliqué par erreur. --}}
    <div class="devis-entete d-print-none">
        <div class="devis-entete__titre">
            <x-bouton-retour :route="route('devis.index')" />
            <div>
                <h2 class="h4 mb-1">Devis {{ $devis->numero }}</h2>
                <span class="badge {{ $statut->classeBadge() }}">{{ $statut->libelle() }}</span>
            </div>
        </div>

        <div class="devis-entete__actions">
            <div class="dropdown">
                <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-file-earmark-text me-1"></i>Document
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><x-bouton-imprimer :pdf-route="route('devis.pdf', $devis)" dans-menu /></li>
                    <li><a href="{{ route('devis.pdf', $devis) }}" class="dropdown-item"><i class="bi bi-file-earmark-pdf me-2"></i>Télécharger en PDF</a></li>
                    <li><a href="{{ route('devis.excel', $devis) }}" class="dropdown-item"><i class="bi bi-file-earmark-excel me-2"></i>Télécharger en Excel</a></li>
                </ul>
            </div>

            @can('devis.gerer')
                @if ($devis->peutEtreModifie())
                    <a href="{{ route('devis.edit', $devis) }}" class="btn btn-outline-secondary">
                        <i class="bi bi-pencil me-1"></i>Modifier
                    </a>
                @endif
                @if ($devis->peutEtreAnnule())
                    <div class="dropdown">
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="dropdown" aria-expanded="false" title="Plus d'actions">
                            <i class="bi bi-three-dots"></i><span class="visually-hidden">Plus d'actions</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#confirmActionModal"
                                        data-form-id="form-annuler-devis"
                                        data-message="Refuser / annuler ce devis ? Il ne pourra plus être modifié ni transformé en vente."
                                        data-button-label="Refuser / annuler" data-button-class="btn-danger">
                                    <i class="bi bi-x-circle me-2"></i>Refuser / annuler le devis
                                </button>
                            </li>
                        </ul>
                    </div>
                @endif
            @endcan

            @can('devis.transformer')
                @if ($peutTransformer)
                    {{-- Jamais désactivé, même si du stock manque : la
                         transformation ouvre l'écran de vente avec le panier
                         du devis, où les quantités se corrigent avant
                         d'encaisser (l'alerte ci-dessous dit lesquelles). --}}
                    @if ($sessionOuverte)
                        <a href="{{ route('devis.transformer.form', [$sessionOuverte, $devis]) }}" class="btn btn-primary devis-entete__principale">
                            <i class="bi bi-arrow-repeat me-1"></i>Transformer en vente
                        </a>
                    @else
                        <a href="{{ route('sessions.index') }}" class="btn btn-outline-primary devis-entete__principale">
                            <i class="bi bi-cash-coin me-1"></i>Ouvrir une caisse pour transformer
                        </a>
                    @endif
                @endif
            @endcan
        </div>
    </div>

    @if ($devis->peutEtreAnnule())
        <form id="form-annuler-devis" method="POST" action="{{ route('devis.annuler', $devis) }}" class="d-none">@csrf</form>
    @endif

    {{-- Alertes : la couleur dit le sens — vert, c'est fait ; ambre, il faut
         agir ; rouge, c'est terminé sans suite ; bleu, une information. --}}
    @if ($statut === \App\Enums\DevisStatut::Expire)
        <div class="alert alert-warning d-flex gap-2 align-items-start d-print-none">
            <i class="bi bi-clock-history fs-5 lh-1"></i>
            <div>
                <strong>Devis expiré</strong> depuis le {{ $devis->date_validite->format('d/m/Y') }} : il ne peut plus être transformé en vente.
                @can('devis.gerer')
                    <a href="{{ route('devis.create') }}" class="alert-link ms-1">Créer un nouveau devis</a>
                @endcan
            </div>
        </div>
    @elseif ($statut === \App\Enums\DevisStatut::Refuse)
        <div class="alert alert-danger d-flex gap-2 align-items-start d-print-none">
            <i class="bi bi-x-circle fs-5 lh-1"></i>
            <div><strong>Devis refusé / annulé</strong> : il ne peut plus être modifié ni transformé en vente.</div>
        </div>
    @elseif ($statut === \App\Enums\DevisStatut::Transforme && $devis->vente)
        <div class="alert alert-success d-flex gap-2 align-items-start d-print-none">
            <i class="bi bi-check-circle fs-5 lh-1"></i>
            <div>
                <strong>Transformé en vente</strong> le {{ $devis->transforme_at->format('d/m/Y à H:i') }} —
                <a href="{{ route('ventes.ticket', $devis->vente) }}" class="alert-link">voir le ticket {{ $devis->vente->numero }}</a>
            </div>
        </div>
    @elseif ($peutTransformer && $joursRestants <= 3)
        <div class="alert alert-info d-flex gap-2 align-items-start d-print-none">
            <i class="bi bi-hourglass-split fs-5 lh-1"></i>
            <div>
                <strong>{{ $joursRestants <= 0 ? 'Dernier jour de validité' : 'Plus que '.$joursRestants.' jour'.($joursRestants > 1 ? 's' : '').' de validité' }}</strong> :
                après le {{ $devis->date_validite->format('d/m/Y') }}, ce devis ne pourra plus être transformé en vente.
            </div>
        </div>
    @endif

    @if ($stockManquant)
        <div class="alert alert-warning d-flex gap-2 align-items-start d-print-none">
            <i class="bi bi-exclamation-triangle fs-5 lh-1"></i>
            <div>
                <strong>Stock insuffisant</strong> pour {{ $lignesEnRupture->count() > 1 ? 'ces produits' : 'ce produit' }}, tous lieux confondus :
                le devis ne peut pas être vendu tel quel. En le transformant, ajustez ou retirez
                {{ $lignesEnRupture->count() > 1 ? 'ces lignes' : 'cette ligne' }} sur l'écran de vente avant d'encaisser.
                <ul class="mb-0 mt-1 ps-3">
                    @foreach ($lignesEnRupture as $etat)
                        <li>
                            {{ $etat['ligne']->produit->libelle_affichage }} :
                            {{ $etat['demande'] }} demandé{{ $etat['demande'] > 1 ? 's' : '' }}, {{ $etat['disponible'] }} disponible{{ $etat['disponible'] > 1 ? 's' : '' }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    {{-- Client, création, validité, total : une seule carte basse plutôt que trois. --}}
    <div class="card mb-3 d-print-none">
        <div class="card-body devis-infos">
            <div>
                <div class="devis-infos__libelle">Client</div>
                <a href="{{ route('clients.show', $devis->client) }}" class="fw-medium">{{ $devis->client->nom }}</a>
                @if ($devis->client->telephone)
                    <div class="small text-secondary">{{ telephone($devis->client->telephone) }}</div>
                @endif
            </div>
            <div>
                <div class="devis-infos__libelle">Créé le</div>
                <div class="fw-medium">{{ $devis->created_at->format('d/m/Y') }}</div>
                <div class="small text-secondary">par {{ $devis->auteur->name }}</div>
            </div>
            <div>
                <div class="devis-infos__libelle">Valide jusqu'au</div>
                <div class="fw-medium">{{ $devis->date_validite->format('d/m/Y') }}</div>
                @if ($peutTransformer)
                    <div class="small text-secondary">{{ $joursRestants <= 0 ? "Aujourd'hui" : 'Encore '.$joursRestants.' jour'.($joursRestants > 1 ? 's' : '') }}</div>
                @endif
            </div>
            <div>
                <div class="devis-infos__libelle">Total indicatif</div>
                <div class="fw-medium">{{ montant($montants['total_net']) }}</div>
                <div class="small text-secondary">{{ $devis->lignes->count() }} ligne{{ $devis->lignes->count() > 1 ? 's' : '' }}</div>
            </div>
        </div>
    </div>

    <div class="card d-print-none">
        <div class="card-body">
            <p class="text-secondary small mb-0">Montants indicatifs — ceci est un devis, pas une facture.</p>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Désignation</th>
                        <th class="d-print-none">Stock</th>
                        <th>Qté</th>
                        <th class="text-end">Prix unitaire</th>
                        <th class="text-end">Remise</th>
                        @if ($montants['total_taxes'] > 0)
                            <th>Taxe</th>
                        @endif
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($devis->lignes as $ligne)
                        @php
                            $prixUnitaire = $ligne->uniteVente->prix ?? $ligne->produit->prix_piece;
                            $sousTotalLigne = $prixUnitaire * $ligne->quantite;
                            $remiseLigne = \App\Support\Remise::resoudre($ligne->remise_type, $ligne->remise_valeur, $sousTotalLigne);
                            $enRupture = $lignesEnRupture->firstWhere(fn ($etat) => $etat['ligne']->id === $ligne->id);
                        @endphp
                        <tr>
                            <td>
                                {{ $ligne->produit->libelle_affichage }}
                                <span class="text-secondary small">({{ $ligne->uniteVente?->libelle ?? $ligne->produit->unite_base_libelle }})</span>
                                @if ($enRupture)
                                    <span class="badge badge-doux-warning ms-1" title="Stock insuffisant : {{ $enRupture['disponible'] }} disponible(s) sur {{ $enRupture['demande'] }} demandé(s)">
                                        <i class="bi bi-exclamation-triangle"></i> Stock insuffisant
                                    </span>
                                @endif
                            </td>
                            <td class="d-print-none">
                                <div class="d-flex flex-column">
                                    @foreach ($stocksParProduit[$ligne->produit_id] ?? [] as $source)
                                        <span class="small">
                                            <span class="text-secondary">{{ $source['nom'] }}{{ $source['type'] === 'depot' ? ' (dépôt)' : '' }} : </span><span class="{{ (float) $source['quantite'] === 0.0 ? 'text-danger' : 'text-secondary' }}">{{ quantite($source['quantite']) }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td>{{ quantite($ligne->quantite) }}</td>
                            <td class="text-end">{{ montant($prixUnitaire) }}</td>
                            <td class="text-end text-danger">{{ $remiseLigne > 0 ? '− '.montant($remiseLigne) : '—' }}</td>
                            @if ($montants['total_taxes'] > 0)
                                <td>{{ $ligne->taxe->nom ?? '—' }}</td>
                            @endif
                            <td class="text-end fw-medium">{{ montant($sousTotalLigne - $remiseLigne) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                @php $colspanTotaux = $montants['total_taxes'] > 0 ? 6 : 5; @endphp
                <tfoot>
                    @if ($montants['total_taxes'] > 0)
                        <tr>
                            <th colspan="{{ $colspanTotaux }}" class="text-end">Sous-total (HT)</th>
                            <th class="text-end">{{ montant($montants['sous_total']) }}</th>
                        </tr>
                        <tr>
                            <th colspan="{{ $colspanTotaux }}" class="text-end">Total taxes</th>
                            <th class="text-end">{{ montant($montants['total_taxes']) }}</th>
                        </tr>
                    @endif
                    <tr class="fw-bold fs-5">
                        <th colspan="{{ $colspanTotaux }}" class="text-end">Total indicatif</th>
                        <th class="text-end">{{ montant($montants['total_net']) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- Contenu au format devis, masqué à l'écran (même gabarit que le PDF).
         « Imprimer » (menu Document) imprime le PDF réel, voir
         x-bouton-imprimer. --}}
    <div id="factureImprimable" class="d-none">
        <table class="entete">
            <tr>
                <td style="width: 55%;">
                    @if ($logoDataUri)
                        <img src="{{ $logoDataUri }}" class="logo" alt="Logo">
                        <br>
                    @endif
                    <span class="entreprise-nom">{{ $parametre->nom }}</span><br>
                    @if ($parametre->adresse) {{ $parametre->adresse }}<br> @endif
                    @if ($parametre->numero) Tél : {{ telephone($parametre->numero) }} @endif
                </td>
                <td style="width: 45%;">
                    <div class="facture-titre">DEVIS</div>
                    <div class="facture-meta">
                        N° {{ $devis->numero }}<br>
                        Date : {{ $devis->created_at->format('d/m/Y') }}<br>
                        Valide jusqu'au : {{ $devis->date_validite->format('d/m/Y') }}
                    </div>
                </td>
            </tr>
        </table>

        <div class="bloc-client">
            <div class="label">Client</div>
            <strong>{{ $devis->client->nom }}</strong><br>
            @if ($devis->client->telephone) Tél : {{ telephone($devis->client->telephone) }}<br> @endif
            @if ($devis->client->adresse) {{ $devis->client->adresse }} @endif
        </div>

        <table class="lignes">
            <thead>
                <tr>
                    <th>Désignation</th>
                    <th>Unité</th>
                    <th class="text-end">Qté</th>
                    <th class="text-end">Prix unitaire</th>
                    <th class="text-end">Remise</th>
                    <th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($devis->lignes as $ligne)
                    @php
                        $prixUnitaire = $ligne->uniteVente->prix ?? $ligne->produit->prix_piece;
                        $sousTotalLigne = $prixUnitaire * $ligne->quantite;
                        $remiseLigne = \App\Support\Remise::resoudre($ligne->remise_type, $ligne->remise_valeur, $sousTotalLigne);
                    @endphp
                    <tr>
                        <td>{{ $ligne->produit->libelle_affichage }}</td>
                        <td>{{ $ligne->uniteVente->libelle ?? $ligne->produit->unite_base_libelle }}</td>
                        <td class="text-end">{{ quantite($ligne->quantite) }}</td>
                        <td class="text-end">{{ montant($prixUnitaire) }}</td>
                        <td class="text-end">{{ $remiseLigne > 0 ? '− '.montant($remiseLigne) : '—' }}</td>
                        <td class="text-end">{{ montant($sousTotalLigne - $remiseLigne) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totaux">
            <tr class="net">
                <td>Total net</td>
                <td class="text-end">{{ montant($montants['total_net']) }}</td>
            </tr>
        </table>

        <div class="mention">
            Ce document est un devis, pas une facture — montants indicatifs, valables jusqu'au {{ $devis->date_validite->format('d/m/Y') }}.
        </div>
    </div>
@endsection
