<x-apercu-ecran titre="Produits" description="Catalogue de produits filtré par catégorie : chaque produit a sa référence, son seuil d'alerte et plusieurs unités de vente avec leur prix, par exemple le mètre et le rouleau de 50 mètres.">
    @foreach ([[20, 44, 'Tous', true], [72, 82, 'Alimentation', false], [162, 66, 'Entretien', false], [236, 70, 'Papeterie', false], [314, 72, 'Électricité', false]] as [$x, $largeur, $categorie, $active])
        <rect class="{{ $active ? 'f-primaire' : 'f-blanc s-ligne' }}" x="{{ $x }}" y="52" width="{{ $largeur }}" height="24" rx="12"/>
        <text class="{{ $active ? 't-blanc' : 't-doux' }}" x="{{ $x + $largeur / 2 }}" y="68" font-size="10" text-anchor="middle">{{ $categorie }}</text>
    @endforeach
    <rect class="f-primaire-doux" x="404" y="52" width="96" height="24" rx="8"/>
    <text class="t-primaire" x="452" y="68" font-size="10" text-anchor="middle">+ Nouveau produit</text>

    @foreach ([
        [90, 'Riz 5 kg', 'ALI-00012', 'Seuil : 10', 'primaire', [[106, 80, 'Sac · 4 500 F'], [194, 124, 'Carton de 4 · 17 000 F']]],
        [184, 'Câble électrique', 'ELE-00087', 'Seuil : 100', 'info', [[106, 84, 'Mètre · 350 F'], [198, 138, 'Rouleau de 50 m · 15 000 F']]],
        [278, 'Savon 250 g', 'ENT-00031', 'Seuil : 24', 'succes', [[106, 80, 'Pièce · 500 F'], [194, 130, 'Carton de 24 · 11 000 F']]],
    ] as [$y, $nom, $reference, $seuil, $ton, $unites])
        <rect class="f-blanc s-ligne" x="20" y="{{ $y }}" width="480" height="84" rx="10"/>
        <rect class="f-{{ $ton }}-doux" x="32" y="{{ $y + 12 }}" width="60" height="60" rx="8"/>
        <rect class="f-{{ $ton }}" x="50" y="{{ $y + 30 }}" width="24" height="24" rx="6" opacity="0.85"/>
        <text class="t-fort" x="106" y="{{ $y + 27 }}" font-size="12">{{ $nom }}</text>
        <text class="t-doux" x="106" y="{{ $y + 43 }}" font-size="9.5">Réf. {{ $reference }}</text>
        <text class="t-doux" x="488" y="{{ $y + 27 }}" font-size="9.5" text-anchor="end">{{ $seuil }}</text>
        <rect class="f-succes-doux" x="446" y="{{ $y + 34 }}" width="42" height="17" rx="8.5"/>
        <text class="t-succes" x="467" y="{{ $y + 46 }}" font-size="9" text-anchor="middle">Actif</text>
        @foreach ($unites as [$x, $largeur, $libelle])
            <rect class="f-gris" x="{{ $x }}" y="{{ $y + 52 }}" width="{{ $largeur }}" height="20" rx="6"/>
            <text class="t" x="{{ $x + $largeur / 2 }}" y="{{ $y + 66 }}" font-size="9.5" text-anchor="middle">{{ $libelle }}</text>
        @endforeach
    @endforeach
</x-apercu-ecran>
