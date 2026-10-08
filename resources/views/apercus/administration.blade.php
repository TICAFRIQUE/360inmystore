<x-apercu-ecran titre="Administration" description="Administration : tableau des permissions accordées aux rôles Gérant et Caissier, liste des magasins et dépôts, et personnalisation du nom, du logo et de la couleur du commerce.">
    {{-- Rôles et permissions --}}
    <rect class="f-blanc s-ligne" x="20" y="52" width="290" height="312" rx="10"/>
    <text class="t-fort" x="34" y="75" font-size="11.5">Rôles et permissions</text>
    <text class="t-doux" x="222" y="102" font-size="9.5" text-anchor="middle">Gérant</text>
    <text class="t-doux" x="278" y="102" font-size="9.5" text-anchor="middle">Caissier</text>
    <path class="s-ligne" d="M34 110.5 H296"/>
    @foreach ([
        ['Vendre', true, true],
        ['Vendre à crédit', true, true],
        ['Ajuster le stock', true, false],
        ['Clôturer la caisse', true, true],
        ['Voir les rapports', true, false],
        ['Gérer les utilisateurs', true, false],
    ] as $i => [$permission, $gerant, $caissier])
        @php $y = 133 + $i * 34; @endphp
        <text class="t" x="34" y="{{ $y }}" font-size="10.5">{{ $permission }}</text>
        @foreach ([[222, $gerant], [278, $caissier]] as [$x, $accorde])
            @if ($accorde)
                <circle class="f-primaire" cx="{{ $x }}" cy="{{ $y - 4 }}" r="9"/>
                <path class="s-blanc" d="M{{ $x - 4 }} {{ $y - 4 }} L{{ $x - 1 }} {{ $y - 1 }} L{{ $x + 4.5 }} {{ $y - 7 }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            @else
                <circle class="f-gris s-ligne" cx="{{ $x }}" cy="{{ $y - 4 }}" r="9"/>
            @endif
        @endforeach
        <path class="s-ligne" d="M34 {{ $y + 12.5 }} H296"/>
    @endforeach
    <text class="t-primaire" x="34" y="347" font-size="10.5">+ Nouveau rôle</text>

    {{-- Magasins et dépôts --}}
    <rect class="f-blanc s-ligne" x="326" y="52" width="174" height="150" rx="10"/>
    <text class="t-fort" x="340" y="75" font-size="11.5">Magasins et dépôts</text>
    @foreach ([['Magasin principal', 'Magasin', 'primaire', 104], ['Magasin 2', 'Magasin', 'primaire', 138], ['Dépôt central', 'Dépôt', 'info', 172]] as [$nom, $type, $ton, $y])
        <text class="t" x="340" y="{{ $y }}" font-size="10">{{ $nom }}</text>
        <rect class="f-{{ $ton }}-doux" x="438" y="{{ $y - 13 }}" width="50" height="18" rx="9"/>
        <text class="t-{{ $ton }}" x="463" y="{{ $y }}" font-size="9" text-anchor="middle">{{ $type }}</text>
        @unless ($loop->last)
            <path class="s-ligne" d="M340 {{ $y + 13.5 }} H488"/>
        @endunless
    @endforeach

    {{-- Identité du commerce --}}
    <rect class="f-blanc s-ligne" x="326" y="214" width="174" height="150" rx="10"/>
    <text class="t-fort" x="340" y="237" font-size="11.5">Votre commerce</text>
    <rect class="f-primaire-doux" x="340" y="250" width="38" height="38" rx="10"/>
    <text class="t-primaire" x="359" y="274" font-size="13" font-weight="600" text-anchor="middle">VC</text>
    <text class="t-doux" x="388" y="264" font-size="9">Nom affiché</text>
    <text class="t-fort" x="388" y="280" font-size="10.5">Votre commerce</text>
    <text class="t-doux" x="340" y="312" font-size="9">Couleur</text>
    <circle class="s-primaire" cx="351" cy="337" r="13" stroke-width="1.5"/>
    @foreach ([['f-primaire', 351], ['f-info', 383], ['f-succes', 415], ['f-alerte', 447], ['f-sombre', 479]] as [$classe, $x])
        <circle class="{{ $classe }}" cx="{{ $x }}" cy="337" r="9"/>
    @endforeach
</x-apercu-ecran>
