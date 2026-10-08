<x-apercu-ecran titre="État de stock" description="État de stock : nombre de références, produits sous le seuil, valeur du stock, quantités par magasin et par dépôt avec leur état, et un transfert du dépôt vers le magasin.">
    @foreach ([
        [20, 'Références', '248', 't-fort'],
        [188, 'Sous le seuil', '6', 't-alerte'],
        [356, 'Valeur du stock', '4 820 000 F', 't-fort'],
    ] as [$x, $libelle, $valeur, $classe])
        <rect class="f-blanc s-ligne" x="{{ $x }}" y="52" width="144" height="58" rx="10"/>
        <text class="t-doux" x="{{ $x + 12 }}" y="72" font-size="9.5">{{ $libelle }}</text>
        <text class="{{ $classe }}" x="{{ $x + 12 }}" y="96" font-size="16" font-weight="600">{{ $valeur }}</text>
    @endforeach

    <text class="t-doux" x="20" y="134" font-size="9.5">Produit</text>
    <text class="t-doux" x="270" y="134" font-size="9.5" text-anchor="end">Magasin</text>
    <text class="t-doux" x="350" y="134" font-size="9.5" text-anchor="end">Dépôt</text>
    <text class="t-doux" x="500" y="134" font-size="9.5" text-anchor="end">État</text>
    <path class="s-ligne" d="M20 142.5 H500"/>
    @foreach ([
        ['Riz 5 kg', '48', '120', 'En stock', 'succes', 162],
        ['Huile 1 L', '6', '0', 'Sous le seuil', 'alerte', 192],
        ['Savon 250 g', '0', '40', 'Rupture', 'danger', 222],
        ['Câble 2 m', '35', '60', 'En stock', 'succes', 252],
    ] as [$produit, $magasin, $depot, $etat, $ton, $y])
        <text class="t" x="20" y="{{ $y }}" font-size="10.5">{{ $produit }}</text>
        <text class="t" x="270" y="{{ $y }}" font-size="10.5" text-anchor="end">{{ $magasin }}</text>
        <text class="t" x="350" y="{{ $y }}" font-size="10.5" text-anchor="end">{{ $depot }}</text>
        <rect class="f-{{ $ton }}-doux" x="420" y="{{ $y - 13 }}" width="80" height="19" rx="9.5"/>
        <text class="t-{{ $ton }}" x="460" y="{{ $y }}" font-size="9.5" text-anchor="middle">{{ $etat }}</text>
        <path class="s-ligne" d="M20 {{ $y + 10.5 }} H500"/>
    @endforeach

    {{-- Transfert --}}
    <rect class="f-primaire-doux" x="20" y="278" width="480" height="86" rx="10"/>
    <text class="t-fort" x="34" y="298" font-size="11">Transfert de stock</text>
    <rect class="f-blanc" x="34" y="310" width="150" height="40" rx="8"/>
    <text class="t-doux" x="46" y="326" font-size="9">Depuis</text>
    <text class="t-fort" x="46" y="341" font-size="10.5">Dépôt central</text>
    <path class="s-primaire" d="M196 330 H316" stroke-width="2" stroke-dasharray="4 4"/>
    <path class="f-primaire" d="M316 324 L326 330 L316 336 Z"/>
    <rect class="f-primaire" x="226" y="319" width="62" height="22" rx="11"/>
    <text class="t-blanc" x="257" y="334" font-size="9.5" text-anchor="middle">40 pièces</text>
    <rect class="f-blanc" x="336" y="310" width="150" height="40" rx="8"/>
    <text class="t-doux" x="348" y="326" font-size="9">Vers</text>
    <text class="t-fort" x="348" y="341" font-size="10.5">Magasin principal</text>
</x-apercu-ecran>
