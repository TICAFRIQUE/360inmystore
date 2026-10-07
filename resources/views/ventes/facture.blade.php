<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Facture {{ $vente->numero }}</title>
    <style>
        /* Feuille de style volontairement autonome (pas de Bootstrap) : ce
           document sert aussi de source au PDF (dompdf), dont le support CSS
           est limité — tables et boîtes simples uniquement, pas de flex/grid. */
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 13px;
            color: #241e19;
            margin: 0;
            padding: 28px;
        }
        table { border-collapse: collapse; }
        .text-end { text-align: right; }
        .actions { margin-bottom: 20px; }
        .actions a, .actions button {
            display: inline-block;
            margin-right: 8px;
            padding: 6px 14px;
            font-size: 13px;
            text-decoration: none;
            border: 1px solid #e8590c;
            border-radius: 4px;
            color: #e8590c;
            background: #fff;
            cursor: pointer;
        }
        .entete { width: 100%; margin-bottom: 24px; }
        .entete td { vertical-align: top; }
        .logo { max-height: 70px; max-width: 220px; }
        .entreprise-nom { font-size: 16px; font-weight: bold; }
        .facture-titre { font-size: 22px; font-weight: bold; text-align: right; color: #e8590c; letter-spacing: 1px; }
        .facture-meta { text-align: right; font-size: 12px; color: #555; line-height: 1.6; }
        .bloc-client { margin: 16px 0 24px; padding: 12px 14px; background: #f6f3ef; border: 1px solid #ddd3c8; }
        .bloc-client .label { font-size: 11px; text-transform: uppercase; color: #888; margin-bottom: 4px; }
        table.lignes { width: 100%; margin-top: 8px; }
        table.lignes th, table.lignes td { border: 1px solid #ccc; padding: 6px 8px; font-size: 12px; }
        table.lignes th { background: #f0ece6; text-align: left; }
        table.totaux { width: 45%; margin-left: 55%; margin-top: 12px; }
        table.totaux td { padding: 5px 8px; }
        table.totaux .net td { font-weight: bold; font-size: 15px; border-top: 2px solid #241e19; }
        table.totaux .credit td { color: #b02a37; font-weight: bold; }
        .badge-annulee { display: inline-block; padding: 4px 10px; border: 1px solid #b02a37; color: #b02a37; font-weight: bold; margin-bottom: 12px; }
        .badge-livraison { display: inline-block; padding: 4px 10px; border: 1px solid #2f7d32; color: #2f7d32; font-weight: bold; margin-bottom: 12px; }
        .badge-livraison.partielle { border-color: #92650a; color: #92650a; }
        .mention { margin-top: 30px; font-size: 11px; color: #888; text-align: center; }
        /* @page (pas body padding) : seul mécanisme qui réserve une marge de
           sécurité fiable sur CHAQUE page côté dompdf comme à l'impression
           navigateur — un padding sur body ne protège que le tout début/la
           toute fin du document, pas les bords de chaque page. Sans cette
           marge, le contenu touchait le bord physique et se faisait rogner
           par la zone non imprimable de l'imprimante. */
        @page {
            margin: 15mm 12mm;
        }
        @media print {
            .actions { display: none; }
            body { padding: 0; margin: 0; color: #000; }
            /* Noir et blanc par défaut à l'impression/PDF : les couleurs
               d'accent (orange, rouge, beige) restent réservées à l'écran. */
            .facture-titre { color: #000; }
            .facture-meta { color: #000; }
            .bloc-client { background: #fff; border-color: #000; }
            .bloc-client .label { color: #000; }
            table.lignes th { background: #fff; }
            table.totaux .credit td { color: #000; }
            .badge-annulee { color: #000; border-color: #000; }
            .badge-livraison, .badge-livraison.partielle { color: #000; border-color: #000; }
            .mention { color: #000; }
        }
    </style>
    @if ($pourPdf ?? false)
        <style>
            /* Le PDF téléchargé est généré par dompdf en media "screen" par
               défaut (@media print ci-dessus ne s'y applique jamais) : mêmes
               règles noir et blanc dupliquées ici pour le PDF, l'impression
               navigateur réelle restant, elle, couverte par @media print. */
            .facture-titre { color: #000; }
            .facture-meta { color: #000; }
            .bloc-client { background: #fff; border-color: #000; }
            .bloc-client .label { color: #000; }
            table.lignes th { background: #fff; }
            table.totaux .credit td { color: #000; }
            .badge-annulee { color: #000; border-color: #000; }
            .badge-livraison, .badge-livraison.partielle { color: #000; border-color: #000; }
            .mention { color: #000; }
        </style>
    @endif
</head>
<body>
    @unless ($pourPdf ?? false)
        <div class="actions">
            {{-- Imprime le PDF réel (dompdf) plutôt que cette page HTML :
                 rendu garanti identique à "Télécharger en PDF" (dompdf a un
                 support CSS différent d'un navigateur/Electron). --}}
            <x-bouton-imprimer :pdf-route="route('ventes.pdf', $vente)" />
            <a href="{{ route('ventes.pdf', $vente) }}">Télécharger en PDF</a>
            <a href="{{ route('ventes.excel', $vente) }}">Télécharger en Excel</a>
            <a href="{{ route('ventes.ticket', $vente) }}">Voir le ticket de caisse</a>
        </div>
        <script>
            // Le bouton "Facture" du détail vente ouvre directement cette page :
            // déclenche l'impression immédiatement, sans clic supplémentaire.
            window.addEventListener('load', () => setTimeout(() => {
                window.__imprimerPdf('{{ route('ventes.pdf', $vente) }}?imprimer=1');
            }, 200));
        </script>
    @endunless

    @if ($vente->trashed())
        <div class="badge-annulee">VENTE ANNULÉE</div>
    @endif

    @php
        // N'affiche le statut de livraison que si la fonctionnalité a été
        // utilisée sur cette vente (voir Vente::livraisonEngagee()) — pas de
        // mention "non livré" sur une vente comptoir classique jamais suivie
        // via un bon de livraison (remise immédiate au comptoir).
        $livraisonEngagee = $vente->livraisonEngagee();
        $totalVenduPieces = $vente->lignes->sum('quantite_pieces');
        $totalLivrePieces = $vente->quantiteLivreePieces();
        $entierementLivree = $vente->entierementLivree();
    @endphp
    @if ($livraisonEngagee)
        <div class="badge-livraison {{ $entierementLivree ? '' : 'partielle' }}">
            @if ($entierementLivree)
                ENTIÈREMENT LIVRÉE
            @else
                LIVRAISON PARTIELLE — {{ $totalLivrePieces }}/{{ $totalVenduPieces }} pièce(s) livrée(s)
            @endif
        </div>
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
                @if ($parametre->numero) Tél : {{ telephone($parametre->numero) }} @endif
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
        @if ($vente->client?->telephone) Tél : {{ telephone($vente->client->telephone) }}<br> @endif
        @if ($vente->client?->adresse) {{ $vente->client->adresse }} @endif
    </div>

    @php $venteTotalTaxes = $vente->totalTaxes(); @endphp
    <table class="lignes">
        {{-- Largeurs explicites : Désignation doit rester la zone la plus large
             (évite les retours à la ligne sur un nom de produit long) tandis
             qu'Unité/Qté/Remise, au contenu toujours très court, restent
             étroites — laissées à l'auto-layout, dompdf leur donnait sinon
             une part de largeur bien supérieure à leur contenu réel. --}}
        <colgroup>
            @if ($venteTotalTaxes > 0)
                <col style="width: 34%;">
                <col style="width: 9%;">
                <col style="width: 7%;">
                <col style="width: 14%;">
                <col style="width: 9%;">
                <col style="width: 11%;">
                <col style="width: 16%;">
            @else
                <col style="width: 38%;">
                <col style="width: 10%;">
                <col style="width: 8%;">
                <col style="width: 16%;">
                <col style="width: 10%;">
                <col style="width: 18%;">
            @endif
        </colgroup>
        <thead>
            <tr>
                <th>Désignation</th>
                <th>Unité</th>
                <th class="text-end">Qté</th>
                <th class="text-end">P.u</th>
                <th class="text-end">Remise</th>
                @if ($venteTotalTaxes > 0)
                    <th>Taxe</th>
                @endif
                <th class="text-end">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($vente->lignes as $ligne)
                <tr>
                    <td>{{ $ligne->produit->libelle_affichage }}</td>
                    <td>{{ $ligne->uniteVente->libelle ?? $ligne->produit->unite_base_libelle }}</td>
                    <td class="text-end">{{ quantite($ligne->quantite) }}</td>
                    <td class="text-end">{{ montant($ligne->prixUnitaireEffectif()) }}</td>
                    <td class="text-end">{{ (! $ligne->prix_personnalise && $ligne->remise_ligne_montant > 0) ? '− '.montant($ligne->remise_ligne_montant) : '—' }}</td>
                    @if ($venteTotalTaxes > 0)
                        <td>{{ $ligne->taxe->nom ?? '—' }}</td>
                    @endif
                    <td class="text-end">{{ montant($ligne->total_ligne) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totaux">
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
</body>
</html>
