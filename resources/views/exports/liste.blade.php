<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titre }}</title>
    <style>
        /* Feuille de style volontairement autonome (pas de Bootstrap) : dompdf,
           dont le support CSS est limité — tables et boîtes simples uniquement. */
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            color: #1d2939;
            margin: 0;
            padding: 28px;
        }
        table { border-collapse: collapse; width: 100%; }
        .text-end { text-align: right; }
        .titre { font-size: 20px; font-weight: bold; color: #101828; letter-spacing: 1px; margin-bottom: 4px; }
        .meta { font-size: 11px; color: #555; margin-bottom: 16px; }
        table.lignes th, table.lignes td { border: 1px solid #ccc; padding: 3px 5px; font-size: 9px; }
        table.lignes th { background: #f2f4f7; text-align: left; }
        table.bilan { width: 260px; margin-top: 18px; margin-left: auto; }
        table.bilan th { background: #f2f4f7; text-align: left; font-size: 10px; padding: 4px 6px; border: 1px solid #ccc; }
        table.bilan td { border: 1px solid #ccc; padding: 3px 6px; font-size: 9px; }
        table.bilan td:first-child { color: #555; }
        table.bilan td:last-child { text-align: right; font-weight: bold; }
    </style>
</head>
<body>
    <div class="titre">{{ $titre }}</div>
    <div class="meta">
        Édité le {{ now()->format('d/m/Y à H:i') }}
        @if ($sousTitre) — {{ $sousTitre }} @endif
    </div>

    <table class="lignes">
        <thead>
            <tr>
                @foreach ($entetes as $entete)
                    <th>{{ $entete }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($lignes as $ligne)
                <tr>
                    @foreach ($ligne as $valeur)
                        <td>{{ $valeur }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($entetes) }}" class="text-end">Aucune donnée.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if (! empty($bilan))
        <table class="bilan">
            <tr><th colspan="2">Bilan</th></tr>
            @foreach ($bilan as $label => $valeur)
                <tr>
                    <td>{{ $label }}</td>
                    <td>{{ $valeur }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
