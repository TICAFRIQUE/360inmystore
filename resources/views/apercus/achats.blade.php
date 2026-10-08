<x-apercu-ecran titre="Bon de commande" description="Bon de commande fournisseur validé, reçu à 70 % en deux livraisons : quantités commandées et reçues par produit, destination magasin ou dépôt, total, montant payé et reste dû.">
    <text class="t-doux" x="20" y="64" font-size="9.5">Bon de commande</text>
    <text class="t-fort" x="20" y="85" font-size="15.5">BC-000124</text>
    <rect class="f-succes-doux" x="116" y="71" width="50" height="19" rx="9.5"/>
    <text class="t-succes" x="141" y="84" font-size="9.5" text-anchor="middle">Validé</text>
    <text class="t-doux" x="500" y="64" font-size="9.5" text-anchor="end">Fournisseur</text>
    <text class="t-fort" x="500" y="84" font-size="12" text-anchor="end">Grossiste du Centre</text>

    {{-- Avancement des réceptions --}}
    <rect class="f-gris" x="20" y="100" width="480" height="46" rx="10"/>
    <text class="t-doux" x="34" y="119" font-size="9.5">Réception</text>
    <text class="t-fort" x="34" y="135" font-size="11">2 livraisons reçues · 70 %</text>
    <rect class="f-ligne" x="250" y="118" width="236" height="10" rx="5"/>
    <rect class="f-primaire" x="250" y="118" width="165" height="10" rx="5"/>

    {{-- Lignes --}}
    <text class="t-doux" x="20" y="170" font-size="9.5">Produit</text>
    <text class="t-doux" x="250" y="170" font-size="9.5" text-anchor="end">Commandé</text>
    <text class="t-doux" x="310" y="170" font-size="9.5" text-anchor="end">Reçu</text>
    <text class="t-doux" x="430" y="170" font-size="9.5">Destination</text>
    <path class="s-ligne" d="M20 178.5 H500"/>
    @foreach ([
        ['Riz 5 kg', 100, 100, 'Magasin', 198],
        ['Huile 1 L', 60, 36, 'Dépôt', 228],
        ['Savon 250 g', 200, 120, 'Magasin', 258],
        ['Cahier 200 p', 80, 0, 'Dépôt', 288],
    ] as [$produit, $commande, $recu, $destination, $y])
        <text class="t" x="20" y="{{ $y }}" font-size="10.5">{{ $produit }}</text>
        <text class="t" x="250" y="{{ $y }}" font-size="10.5" text-anchor="end">{{ $commande }}</text>
        <text class="{{ $recu === 0 ? 't-doux' : 't' }}" x="310" y="{{ $y }}" font-size="10.5" text-anchor="end">{{ $recu }}</text>
        <rect class="f-ligne" x="326" y="{{ $y - 8 }}" width="84" height="7" rx="3.5"/>
        @if ($recu > 0)
            <rect class="{{ $recu === $commande ? 'f-succes' : 'f-primaire' }}" x="326" y="{{ $y - 8 }}" width="{{ round(84 * $recu / $commande) }}" height="7" rx="3.5"/>
        @endif
        <text class="t-doux" x="430" y="{{ $y }}" font-size="10">{{ $destination }}</text>
        <path class="s-ligne" d="M20 {{ $y + 10.5 }} H500"/>
    @endforeach

    {{-- Totaux --}}
    <text class="t-doux" x="20" y="326" font-size="9.5">Total reçu TTC</text>
    <text class="t-fort" x="20" y="346" font-size="14">1 284 500 F</text>
    <text class="t-doux" x="150" y="326" font-size="9.5">Payé</text>
    <text class="t-succes" x="150" y="346" font-size="14">800 000 F</text>
    <text class="t-doux" x="268" y="326" font-size="9.5">Reste dû</text>
    <text class="t-alerte" x="268" y="346" font-size="14">484 500 F</text>
    <rect class="f-primaire" x="396" y="320" width="104" height="32" rx="8"/>
    <text class="t-blanc" x="448" y="340" font-size="11" text-anchor="middle">Réceptionner</text>
</x-apercu-ecran>
