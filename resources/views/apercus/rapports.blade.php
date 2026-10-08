<x-apercu-ecran titre="Rapports" description="Rapport des ventes du mois : chiffre d'affaires, marge et panier moyen, histogramme des ventes par jour, menu Exporter ouvert sur PDF, Excel, CSV et Imprimer, et répartition des moyens de paiement.">
    <text class="t-fort" x="20" y="70" font-size="13">Rapport des ventes</text>
    <rect class="f-gris" x="146" y="55" width="60" height="22" rx="11"/>
    <text class="t-doux" x="176" y="70" font-size="9.5" text-anchor="middle">Ce mois</text>
    <rect class="f-primaire" x="404" y="52" width="96" height="28" rx="8"/>
    <text class="t-blanc" x="444" y="70" font-size="10.5" text-anchor="middle">Exporter</text>
    <path class="s-blanc" d="M476 64 L481 69 L486 64" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>

    @foreach ([
        [20, "Chiffre d'affaires", '6 480 000 F', 't-fort'],
        [130, 'Marge', '1 512 000 F', 't-succes'],
        [240, 'Panier moyen', '12 400 F', 't-fort'],
    ] as [$x, $libelle, $valeur, $classe])
        <rect class="f-blanc s-ligne" x="{{ $x }}" y="94" width="100" height="54" rx="10"/>
        <text class="t-doux" x="{{ $x + 10 }}" y="113" font-size="9">{{ $libelle }}</text>
        <text class="{{ $classe }}" x="{{ $x + 10 }}" y="135" font-size="12.5" font-weight="600">{{ $valeur }}</text>
    @endforeach

    {{-- Ventes par jour --}}
    <rect class="f-blanc s-ligne" x="20" y="162" width="320" height="202" rx="10"/>
    <text class="t-fort" x="34" y="184" font-size="10.5">Ventes par jour</text>
    @foreach ([222, 262, 302] as $y)
        <path class="s-ligne" d="M34 {{ $y }}.5 H326" stroke-dasharray="3 4"/>
    @endforeach
    <path class="s-ligne" d="M34 334.5 H326"/>
    @foreach ([[62, 'Lun'], [84, 'Mar'], [70, 'Mer'], [102, 'Jeu'], [92, 'Ven'], [128, 'Sam'], [48, 'Dim']] as $i => [$hauteur, $jour])
        <rect class="{{ $hauteur === 128 ? 'f-primaire' : 'f-primaire-moyen' }}" x="{{ 44 + $i * 41 }}" y="{{ 334 - $hauteur }}" width="24" height="{{ $hauteur }}" rx="5"/>
        <text class="t-doux" x="{{ 56 + $i * 41 }}" y="352" font-size="9" text-anchor="middle">{{ $jour }}</text>
    @endforeach

    {{-- Menu Exporter --}}
    <rect class="f-blanc s-ligne ombre" x="356" y="86" width="144" height="124" rx="10"/>
    @foreach ([['PDF', 'danger', 110], ['Excel', 'succes', 138], ['CSV', 'info', 166], ['Imprimer', 'doux', 194]] as [$format, $ton, $y])
        <rect class="f-{{ $ton }}{{ $ton === 'doux' ? '' : '-doux' }}" x="368" y="{{ $y - 13 }}" width="18" height="18" rx="5" @if ($ton === 'doux') opacity="0.25" @endif/>
        <text class="t" x="396" y="{{ $y }}" font-size="10.5">{{ $format }}</text>
    @endforeach

    {{-- Moyens de paiement --}}
    <rect class="f-blanc s-ligne" x="356" y="222" width="144" height="142" rx="10"/>
    <text class="t-fort" x="368" y="244" font-size="10.5">Moyens de paiement</text>
    <rect class="f-primaire" x="368" y="258" width="74" height="9" rx="4.5"/>
    <rect class="f-info" x="444" y="258" width="34" height="9" rx="4.5"/>
    <rect class="f-alerte" x="480" y="258" width="8" height="9" rx="4"/>
    @foreach ([['f-primaire', 'Espèces', '62 %', 292], ['f-info', 'Mobile money', '30 %', 316], ['f-alerte', 'Carte', '8 %', 340]] as [$classe, $libelle, $part, $y])
        <circle class="{{ $classe }}" cx="373" cy="{{ $y - 3.5 }}" r="4"/>
        <text class="t" x="384" y="{{ $y }}" font-size="10">{{ $libelle }}</text>
        <text class="t-doux" x="488" y="{{ $y }}" font-size="10" text-anchor="end">{{ $part }}</text>
    @endforeach
</x-apercu-ecran>
