<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>État du stock</title>
    <style>
        /* Feuille de style volontairement autonome (pas de Bootstrap) : dompdf,
           dont le support CSS est limité — tables et boîtes simples uniquement. */
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            color: #241e19;
            margin: 0;
            padding: 28px;
        }
        table { border-collapse: collapse; width: 100%; }
        .text-end { text-align: right; }
        .titre { font-size: 20px; font-weight: bold; color: #e8590c; letter-spacing: 1px; margin-bottom: 4px; }
        .meta { font-size: 11px; color: #555; margin-bottom: 16px; }
        table.lignes th, table.lignes td { border: 1px solid #ccc; padding: 5px 7px; font-size: 11px; }
        table.lignes th { background: #f0ece6; text-align: left; }
        .sous-seuil { color: #b02a37; font-weight: bold; }
    </style>
</head>
<body>
    <div class="titre">État du stock</div>
    <div class="meta">
        Édité le {{ now()->format('d/m/Y à H:i') }}
        @if ($filtres['magasin']) — Destination : {{ $filtres['magasin'] }} @endif
        @if ($filtres['produit']) — Produit : {{ $filtres['produit'] }} @endif
        @if ($filtres['recherche'] ?? null) — Recherche : {{ $filtres['recherche'] }} @endif
        @if ($filtres['sousSeuil']) — Sous le seuil d'alerte uniquement @endif
    </div>

    <table class="lignes">
        <thead>
            <tr>
                <th>Produit</th>
                <th>SKU</th>
                <th>Stock</th>
                <th class="text-end">Seuil d'alerte</th>
                <th class="text-end">Coût moyen pondéré</th>
                <th class="text-end">Prix de vente</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($produits as $produit)
                <tr>
                    <td>{{ $produit->libelle_affichage }}</td>
                    <td>{{ $produit->sku }}</td>
                    @php($total = $produit->totalStock($magasinsAffiches))
                    <td class="{{ $total['sous_seuil'] ? 'sous-seuil' : '' }}">
                        {{ quantite($total['quantite']) }} {{ $produit->unite_base_libelle_complet }}
                    </td>
                    <td class="text-end">{{ $produit->seuil_alerte }}</td>
                    <td class="text-end">{{ montant($produit->coutMoyenPondere()) }}</td>
                    <td class="text-end">{{ montant($produit->prix_piece) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-end">Aucun produit.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
