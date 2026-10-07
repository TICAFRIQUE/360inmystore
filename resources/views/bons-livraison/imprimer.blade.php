<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Bon de livraison {{ $bonLivraison->numero }}</title>
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
        .badge-annulee { display: inline-block; padding: 4px 10px; border: 1px solid #b02a37; color: #b02a37; font-weight: bold; margin-bottom: 12px; }
        .signatures { width: 100%; margin-top: 50px; }
        .signatures td { width: 50%; vertical-align: top; padding-top: 30px; border-top: 1px solid #241e19; font-size: 12px; }
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
            .badge-annulee { color: #000; border-color: #000; }
            .mention { color: #000; }
        </style>
    @endif
</head>
<body>
    @unless ($pourPdf ?? false)
        <div class="actions">
            <x-bouton-imprimer :pdf-route="route('bons-livraison.pdf', $bonLivraison)" />
            <a href="{{ route('bons-livraison.pdf', $bonLivraison) }}">Télécharger en PDF</a>
            <a href="{{ route('ventes.ticket', $bonLivraison->vente) }}">Voir la vente {{ $bonLivraison->vente->numero }}</a>
        </div>
    @endunless

    @if ($bonLivraison->trashed())
        <div class="badge-annulee">BON DE LIVRAISON ANNULÉ — {{ $bonLivraison->motif_annulation }}</div>
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
                <div class="facture-titre">BON DE LIVRAISON</div>
                <div class="facture-meta">
                    N° {{ $bonLivraison->numero }}<br>
                    Date : {{ $bonLivraison->created_at->format('d/m/Y à H:i') }}<br>
                    Vente d'origine : {{ $bonLivraison->vente->numero }}<br>
                    Magasin : {{ $bonLivraison->vente->magasin->nom }}<br>
                    Remis par : {{ $bonLivraison->auteur->name ?? 'utilisateur supprimé' }}
                </div>
            </td>
        </tr>
    </table>

    <div class="bloc-client">
        <div class="label">Client</div>
        <strong>{{ $bonLivraison->vente->client->nom ?? 'Client comptant' }}</strong><br>
        @if ($bonLivraison->vente->client?->telephone) Tél : {{ telephone($bonLivraison->vente->client->telephone) }}<br> @endif
        @if ($bonLivraison->vente->client?->adresse) {{ $bonLivraison->vente->client->adresse }} @endif
    </div>

    <table class="lignes">
        <thead>
            <tr>
                <th>Désignation</th>
                <th class="text-end">Quantité livrée</th>
                <th>Magasin / dépôt source</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($bonLivraison->lignes as $ligne)
                <tr>
                    <td>{{ $ligne->produit->libelle_affichage }}</td>
                    <td class="text-end">{{ quantite($ligne->quantite_pieces) }}</td>
                    <td>{{ $ligne->magasin->nom }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="signatures">
        <tr>
            <td>Remis par</td>
            <td>Reçu par (nom, date, signature)</td>
        </tr>
    </table>

    <div class="mention">
        @if ($parametre->slogan) {{ $parametre->slogan }} @endif
    </div>
</body>
</html>
