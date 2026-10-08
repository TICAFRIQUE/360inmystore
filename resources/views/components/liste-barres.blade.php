{{-- Classement en barres horizontales : une ligne par élément (libellé,
     valeur écrite en clair, barre proportionnelle à la plus grande valeur).
     Remplace un camembert ou un tableau quand il s'agit de comparer des
     montants : la valeur se lit sans légende ni survol.
     $lignes : liste de ['libelle', 'valeur' (nombre), 'texte' (valeur
     affichée) et, au choix, 'detail' (précision à droite du libellé)]. Une
     seule couleur pour toutes les barres — $ton, une couleur du thème. --}}
@props(['lignes', 'ton' => 'primary', 'rang' => false])

@php $maximum = max(1, collect($lignes)->max('valeur')); @endphp

<ol class="liste-barres liste-barres--{{ $ton }}">
    @foreach ($lignes as $ligne)
        <li class="liste-barres__ligne">
            <div class="liste-barres__tete">
                <span class="liste-barres__libelle">
                    @if ($rang) <span class="liste-barres__rang">{{ $loop->iteration }}</span> @endif
                    <span class="text-truncate">{{ $ligne['libelle'] }}</span>
                    @if (! empty($ligne['detail'])) <span class="liste-barres__detail">{{ $ligne['detail'] }}</span> @endif
                </span>
                <span class="liste-barres__valeur">{{ $ligne['texte'] }}</span>
            </div>
            <div class="liste-barres__piste" aria-hidden="true">
                <div class="liste-barres__barre" style="width: {{ max(0, round($ligne['valeur'] / $maximum * 100, 1)) }}%"></div>
            </div>
        </li>
    @endforeach
</ol>
