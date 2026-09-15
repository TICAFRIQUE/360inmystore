<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Bon de commande {{ $commande->numero }}</title>
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
        table.lignes th { background: #f0ece6; text-align: left; font-size: 10px; }
        table.totaux { width: 45%; margin-left: 55%; margin-top: 12px; }
        table.totaux td { padding: 5px 8px; }
        table.totaux .net td { font-weight: bold; font-size: 15px; border-top: 2px solid #241e19; }
        table.totaux .credit td { color: #b02a37; font-weight: bold; }
        .badge-annulee { display: inline-block; padding: 4px 10px; border: 1px solid #b02a37; color: #b02a37; font-weight: bold; margin-bottom: 12px; }
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
            .mention { color: #000; }
        </style>
    @endif
</head>
<body>
    @unless ($pourPdf ?? false)
        <div class="actions">
            {{-- Imprime le PDF réel (dompdf) plutôt que cette page HTML :
                 rendu garanti identique à "Télécharger en PDF". --}}
            <x-bouton-imprimer :pdf-route="route('commande-achats.pdf', $commande)" />
            <a href="{{ route('commande-achats.pdf', $commande) }}">Télécharger en PDF</a>
            <a href="{{ route('commande-achats.excel', $commande) }}">Télécharger en Excel</a>
            <a href="{{ route('commande-achats.show', $commande) }}">Voir le détail</a>
        </div>
    @endunless

    @if ($commande->trashed())
        <div class="badge-annulee">COMMANDE ANNULÉE</div>
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
                <div class="facture-titre">BON DE COMMANDE</div>
                <div class="facture-meta">
                    N° {{ $commande->numero }}<br>
                    Date : {{ $commande->date_commande->format('d/m/Y') }}<br>
                    Statut : {{ $commande->statut === 'validee' ? 'Validée' : 'Brouillon' }}
                </div>
            </td>
        </tr>
    </table>

    <div class="bloc-client">
        <div class="label">Fournisseur</div>
        <strong>{{ $commande->fournisseur->nom }}</strong><br>
        @if ($commande->fournisseur->telephone) Tél : {{ $commande->fournisseur->telephone }}<br> @endif
        @if ($commande->fournisseur->adresse) {{ $commande->fournisseur->adresse }} @endif
    </div>

    @php $aDesReceptions = $commande->receptions->isNotEmpty(); @endphp

    <table class="lignes">
        <colgroup>
            <col style="width: 26%;">
            <col style="width: 7%;">
            <col style="width: 13%;">
            <col style="width: 7%;">
            <col style="width: 12%;">
            <col style="width: 10%;">
            <col style="width: 12%;">
            <col style="width: 13%;">
        </colgroup>
        <thead>
            <tr>
                <th>Désignation</th>
                <th>Unité</th>
                <th>Destination</th>
                <th class="text-end">Qté</th>
                <th class="text-end">Prix HT</th>
                <th>Taxe</th>
                <th class="text-end">Total HT</th>
                <th class="text-end">Total TTC</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($commande->lignes as $ligne)
                <tr>
                    <td>{{ $ligne->produit->libelle_affichage }}</td>
                    <td>{{ $ligne->uniteVente->unite->nom_avec_abbreviation ?? $ligne->produit->unite_base_libelle }}</td>
                    <td>{{ $ligne->magasinDestination->nom ?? '—' }}</td>
                    <td class="text-end">{{ quantite($ligne->quantite) }}</td>
                    <td class="text-end">{{ montantAchat($ligne->prix_achat) }}</td>
                    <td>{{ $ligne->taxe->nom ?? '—' }}</td>
                    <td class="text-end">{{ montantAchat($ligne->montantHt()) }}</td>
                    <td class="text-end">{{ montantAchat($ligne->montantTtc()) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totaux">
        <tr>
            <td>Total HT</td>
            <td class="text-end">{{ montantAchat($commande->totalHt()) }}</td>
        </tr>
        <tr>
            <td>Total taxes</td>
            <td class="text-end">{{ montantAchat($commande->totalTaxes()) }}</td>
        </tr>
        <tr class="net">
            <td>Total TTC</td>
            <td class="text-end">{{ montant($commande->totalTtcReel()) }}</td>
        </tr>
        @if ($aDesReceptions && $commande->ecartMontant() != 0)
            <tr>
                <td>Total TTC indicatif (commande)</td>
                <td class="text-end">{{ montantAchat($commande->totalTtc()) }}</td>
            </tr>
        @endif
        @foreach ($commande->paiements as $paiement)
            <tr>
                <td>{{ $paiement->moyenPaiement->nom }}</td>
                <td class="text-end">{{ montant($paiement->montant) }}</td>
            </tr>
        @endforeach
        @if ($commande->statut === 'validee' && $commande->montantRegle() > 0)
            <tr>
                <td>Montant réglé</td>
                <td class="text-end">{{ montant($commande->montantRegle()) }}</td>
            </tr>
            @if ($commande->resteDu() > 0)
                <tr class="credit">
                    <td>Reste dû au fournisseur</td>
                    <td class="text-end">{{ montant($commande->resteDu()) }}</td>
                </tr>
            @endif
        @endif
    </table>

    <div class="mention">
        @if ($parametre->slogan) {{ $parametre->slogan }} @endif
    </div>
</body>
</html>
