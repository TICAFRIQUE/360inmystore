{{-- Cadre commun des aperçus d'écran (voir views/apercus), montrés sur la
     page de connexion et sur le site vitrine : un écran stylisé de
     l'application. Dessin vectoriel en
     ligne, pour qu'il suive la police et la palette de la page — les
     couleurs viennent des classes de .illu (resources/sass), jamais d'une
     valeur écrite ici. $description : ce que montre l'image, lu par les
     lecteurs d'écran. --}}
@props(['titre', 'description', 'largeur' => 520, 'hauteur' => 380])

<svg {{ $attributes->class('illu') }} viewBox="0 0 {{ $largeur }} {{ $hauteur }}" role="img" aria-label="{{ $description }}">
    <rect class="f-blanc s-ligne" x="1" y="1" width="{{ $largeur - 2 }}" height="{{ $hauteur - 2 }}" rx="14"/>
    <path class="f-gris" d="M1.5 39 V15 A13.5 13.5 0 0 1 15 1.5 H{{ $largeur - 15 }} A13.5 13.5 0 0 1 {{ $largeur - 1.5 }} 15 V39 Z"/>
    <path class="s-ligne" d="M1 39.5 H{{ $largeur - 1 }}"/>
    <circle class="f-point" cx="20" cy="20" r="4"/>
    <circle class="f-point" cx="34" cy="20" r="4"/>
    <circle class="f-point" cx="48" cy="20" r="4"/>
    <text class="t-doux" x="{{ $largeur / 2 }}" y="24" font-size="11" text-anchor="middle">{{ $titre }}</text>
    {{ $slot }}
</svg>
