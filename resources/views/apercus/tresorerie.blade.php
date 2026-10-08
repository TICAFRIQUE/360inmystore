<x-apercu-ecran titre="Trésorerie" description="Trésorerie : solde de la caisse générale et d'un compte bancaire, un virement interne de l'une vers l'autre, et les dernières écritures — dépôts des sessions clôturées, règlement fournisseur, entrée.">
    <rect class="f-primaire" x="20" y="52" width="232" height="80" rx="12"/>
    <text class="t-blanc" x="36" y="75" font-size="10.5" opacity="0.85">Caisse générale</text>
    <text class="t-blanc" x="36" y="103" font-size="19" font-weight="600">2 450 000 F</text>
    <text class="t-blanc" x="36" y="120" font-size="9" opacity="0.75">Solde actuel</text>

    <rect class="f-blanc s-ligne" x="268" y="52" width="232" height="80" rx="12"/>
    <text class="t-doux" x="284" y="75" font-size="10.5">Compte bancaire</text>
    <text class="t-fort" x="284" y="103" font-size="19">8 120 000 F</text>
    <text class="t-doux" x="284" y="120" font-size="9">Solde actuel</text>

    {{-- Virement interne --}}
    <rect class="f-gris" x="20" y="144" width="480" height="34" rx="8"/>
    <text class="t-fort" x="34" y="165" font-size="10">Virement interne</text>
    <text class="t-doux" x="126" y="165" font-size="10">Caisse générale</text>
    <path class="s-doux" d="M208 161.5 H226" stroke-width="1.5"/>
    <path class="f-doux" d="M226 157.5 L233 161.5 L226 165.5 Z"/>
    <text class="t-doux" x="242" y="165" font-size="10">Compte bancaire</text>
    <text class="t-fort" x="488" y="165" font-size="10.5" text-anchor="end">500 000 F</text>

    <text class="t-fort" x="20" y="204" font-size="11">Dernières écritures</text>
    @foreach ([
        ['Dépôt — session clôturée, Caisse 1', '+ 185 000 F', true, 232],
        ['Règlement fournisseur', '− 320 000 F', false, 262],
        ['Virement vers le compte bancaire', '− 500 000 F', false, 292],
        ['Dépôt — session clôturée, Caisse 2', '+ 142 500 F', true, 322],
        ['Entrée — apport', '+ 100 000 F', true, 352],
    ] as [$libelle, $montant, $entree, $y])
        <circle class="{{ $entree ? 'f-succes-doux' : 'f-danger-doux' }}" cx="30" cy="{{ $y - 4 }}" r="10"/>
        <path class="{{ $entree ? 's-succes' : 's-danger' }}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"
              d="{{ $entree ? 'M30 '.($y - 9).' V'.($y + 1).' M26 '.($y - 3).' L30 '.($y + 1).' L34 '.($y - 3) : 'M30 '.($y + 1).' V'.($y - 9).' M26 '.($y - 5).' L30 '.($y - 9).' L34 '.($y - 5) }}"/>
        <text class="t" x="50" y="{{ $y }}" font-size="10.5">{{ $libelle }}</text>
        <text class="{{ $entree ? 't-succes' : 't-danger' }}" x="500" y="{{ $y }}" font-size="10.5" text-anchor="end">{{ $montant }}</text>
        @unless ($loop->last)
            <path class="s-ligne" d="M50 {{ $y + 12.5 }} H500"/>
        @endunless
    @endforeach
</x-apercu-ecran>
