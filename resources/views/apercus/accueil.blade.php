<x-apercu-ecran titre="Tableau de bord" largeur="640" hauteur="430"
    description="Aperçu du tableau de bord : ventes du jour, panier moyen, total en caisse, courbe des ventes de la semaine, répartition des moyens de paiement et produits sous le seuil d'alerte.">
    {{-- Menu réduit --}}
    <path class="f-gris" d="M1.5 40 H57 V428.5 H15 A13.5 13.5 0 0 1 1.5 415 Z"/>
    <path class="s-ligne" d="M57.5 40 V429"/>
    <rect class="f-primaire" x="17" y="56" width="24" height="24" rx="7"/>
    @foreach ([94, 132, 170, 208, 246, 284] as $y)
        <rect class="f-point" x="17" y="{{ $y }}" width="24" height="24" rx="7"/>
    @endforeach

    <text class="t-fort" x="76" y="69" font-size="14">Tableau de bord</text>
    <rect class="f-succes-doux" x="512" y="53" width="108" height="24" rx="12"/>
    <circle class="f-succes" cx="527" cy="65" r="4"/>
    <text class="t-succes" x="537" y="69" font-size="10.5">Caisse ouverte</text>

    {{-- Chiffres clés --}}
    @foreach ([
        [76, 'Ventes du jour', '485 000 F', '+12 % vs hier'],
        [263, 'Panier moyen', '9 700 F', '50 ventes'],
        [450, 'Total en caisse', '312 500 F', 'Espèces du tiroir'],
    ] as [$x, $libelle, $valeur, $detail])
        <rect class="f-blanc s-ligne" x="{{ $x }}" y="90" width="170" height="70" rx="10"/>
        <text class="t-doux" x="{{ $x + 14 }}" y="110" font-size="10">{{ $libelle }}</text>
        <text class="t-fort" x="{{ $x + 14 }}" y="132" font-size="17">{{ $valeur }}</text>
        <text class="{{ $loop->first ? 't-succes' : 't-doux' }}" x="{{ $x + 14 }}" y="149" font-size="9.5">{{ $detail }}</text>
    @endforeach

    {{-- Courbe des ventes --}}
    <rect class="f-blanc s-ligne" x="76" y="174" width="357" height="240" rx="10"/>
    <text class="t-fort" x="90" y="196" font-size="11">Ventes des 7 derniers jours</text>
    @foreach ([232, 272, 312, 352] as $y)
        <path class="s-ligne" d="M92 {{ $y }} H418" stroke-dasharray="3 4"/>
    @endforeach
    <path class="f-primaire-doux" d="M92 340 L146 316 L201 328 L255 286 L309 300 L364 240 L418 262 V384 H92 Z"/>
    <path class="s-primaire" d="M92 340 L146 316 L201 328 L255 286 L309 300 L364 240 L418 262" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
    @foreach ([[92, 340], [146, 316], [201, 328], [255, 286], [309, 300], [364, 240], [418, 262]] as [$x, $y])
        <circle class="f-blanc s-primaire" cx="{{ $x }}" cy="{{ $y }}" r="3.5" stroke-width="2"/>
    @endforeach
    <path class="s-ligne" d="M92 384.5 H418"/>
    @foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $i => $jour)
        <text class="t-doux" x="{{ [92, 146, 201, 255, 309, 364, 418][$i] }}" y="402" font-size="9.5" text-anchor="middle">{{ $jour }}</text>
    @endforeach

    {{-- Moyens de paiement --}}
    <rect class="f-blanc s-ligne" x="450" y="174" width="170" height="114" rx="10"/>
    <text class="t-fort" x="464" y="196" font-size="11">Moyens de paiement</text>
    <g transform="rotate(-90 492 244)" fill="none" stroke-width="11">
        <circle class="s-primaire" cx="492" cy="244" r="23" stroke-dasharray="89.6 144.5"/>
        <circle class="s-info" cx="492" cy="244" r="23" stroke-dasharray="40.5 144.5" stroke-dashoffset="-89.6"/>
        <circle class="s-alerte" cx="492" cy="244" r="23" stroke-dasharray="14.4 144.5" stroke-dashoffset="-130.1"/>
    </g>
    @foreach ([['f-primaire', 'Espèces', 228], ['f-info', 'Mobile', 247], ['f-alerte', 'Carte', 266]] as [$classe, $libelle, $y])
        <circle class="{{ $classe }}" cx="538" cy="{{ $y - 3.5 }}" r="4"/>
        <text class="t" x="548" y="{{ $y }}" font-size="10">{{ $libelle }}</text>
    @endforeach

    {{-- Stock sous seuil --}}
    <rect class="f-blanc s-ligne" x="450" y="300" width="170" height="114" rx="10"/>
    <text class="t-fort" x="464" y="322" font-size="11">Stock sous seuil</text>
    @foreach ([['Huile 1 L', '6', 'alerte', 346], ['Ampoule LED', '4', 'alerte', 372], ['Savon 250 g', '0', 'danger', 398]] as [$produit, $quantite, $ton, $y])
        <text class="t" x="464" y="{{ $y }}" font-size="10.5">{{ $produit }}</text>
        <rect class="f-{{ $ton }}-doux" x="578" y="{{ $y - 13 }}" width="28" height="18" rx="9"/>
        <text class="t-{{ $ton }}" x="592" y="{{ $y }}" font-size="10" text-anchor="middle">{{ $quantite }}</text>
    @endforeach
</x-apercu-ecran>
