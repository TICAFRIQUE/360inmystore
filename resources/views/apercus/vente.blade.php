<x-apercu-ecran titre="Caisse" description="Écran de caisse : produits à gauche, dont un en rupture de stock grisé, panier à droite avec remise, net à payer, moyens de paiement et bouton Encaisser.">
    {{-- Recherche --}}
    <rect class="f-blanc s-ligne" x="16" y="52" width="300" height="30" rx="8"/>
    <circle class="s-doux" cx="32" cy="66" r="5" stroke-width="1.5"/>
    <path class="s-doux" d="M36 70 L40 74" stroke-width="1.5" stroke-linecap="round"/>
    <text class="t-doux" x="48" y="71" font-size="10.5">Rechercher un produit ou un code…</text>

    {{-- Produits --}}
    @foreach ([
        [16, 94, 'Riz 5 kg', '4 500 F', 'En stock : 48', 'primaire', false],
        [120, 94, 'Huile 1 L', '1 500 F', 'En stock : 6', 'alerte', false],
        [224, 94, 'Savon 250 g', '500 F', 'En stock : 120', 'info', false],
        [16, 234, 'Cahier 200 p', '1 200 F', 'En stock : 35', 'succes', false],
        [120, 234, 'Ampoule LED', '2 000 F', 'Rupture de stock', 'gris', true],
        [224, 234, 'Câble 2 m', '2 500 F', 'En stock : 60', 'primaire', false],
    ] as [$x, $y, $nom, $prix, $stock, $ton, $rupture])
        <g @if ($rupture) opacity="0.55" @endif>
            <rect class="f-blanc s-ligne" x="{{ $x }}" y="{{ $y }}" width="92" height="128" rx="10"/>
            <rect class="{{ $ton === 'gris' ? 'f-gris' : 'f-'.$ton.'-doux' }}" x="{{ $x + 8 }}" y="{{ $y + 8 }}" width="76" height="58" rx="7"/>
            <rect class="{{ $ton === 'gris' ? 'f-point' : 'f-'.$ton }}" x="{{ $x + 34 }}" y="{{ $y + 25 }}" width="24" height="24" rx="6" opacity="0.85"/>
            <text class="t-fort" x="{{ $x + 8 }}" y="{{ $y + 84 }}" font-size="10.5">{{ $nom }}</text>
            <text class="t-primaire" x="{{ $x + 8 }}" y="{{ $y + 100 }}" font-size="10.5">{{ $prix }}</text>
            <text class="{{ $rupture ? 't-danger' : 't-doux' }}" x="{{ $x + 8 }}" y="{{ $y + 116 }}" font-size="9">{{ $stock }}</text>
        </g>
    @endforeach

    {{-- Panier --}}
    <rect class="f-gris" x="332" y="52" width="172" height="312" rx="10"/>
    <text class="t-fort" x="346" y="75" font-size="12.5">Panier</text>
    <text class="t-doux" x="490" y="75" font-size="9.5" text-anchor="end">3 articles</text>
    @foreach ([['Riz 5 kg', '× 1', '4 500', 104], ['Huile 1 L', '× 2', '3 000', 128], ['Savon 250 g', '× 4', '2 000', 152]] as [$nom, $quantite, $montant, $y])
        <text class="t" x="346" y="{{ $y }}" font-size="10.5">{{ $nom }}</text>
        <text class="t-doux" x="424" y="{{ $y }}" font-size="9.5">{{ $quantite }}</text>
        <text class="t" x="490" y="{{ $y }}" font-size="10.5" text-anchor="end">{{ $montant }}</text>
    @endforeach
    <path class="s-ligne" d="M346 166.5 H490"/>
    <text class="t-doux" x="346" y="186" font-size="10">Sous-total</text>
    <text class="t" x="490" y="186" font-size="10" text-anchor="end">9 500</text>
    <text class="t-doux" x="346" y="204" font-size="10">Remise 5 %</text>
    <text class="t-succes" x="490" y="204" font-size="10" text-anchor="end">− 475</text>
    <path class="s-ligne" d="M346 214.5 H490"/>
    <text class="t-doux" x="346" y="234" font-size="10">Net à payer</text>
    <text class="t-fort" x="490" y="236" font-size="16" text-anchor="end">9 025 F</text>

    <rect class="f-primaire-doux" x="346" y="250" width="64" height="24" rx="12"/>
    <text class="t-primaire" x="378" y="266" font-size="9.5" text-anchor="middle">Espèces</text>
    <rect class="f-blanc s-ligne" x="416" y="250" width="74" height="24" rx="12"/>
    <text class="t-doux" x="453" y="266" font-size="9.5" text-anchor="middle">Mobile money</text>

    <rect class="f-blanc s-ligne" x="346" y="288" width="144" height="28" rx="8"/>
    <text class="t" x="418" y="306" font-size="10.5" text-anchor="middle">Mettre en attente</text>
    <rect class="f-primaire" x="346" y="324" width="144" height="30" rx="8"/>
    <text class="t-blanc" x="418" y="343" font-size="11.5" text-anchor="middle">Encaisser</text>
</x-apercu-ecran>
