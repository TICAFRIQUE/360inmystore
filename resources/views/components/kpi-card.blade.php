{{-- solid : fond plein (bg-{color}) + texte blanc, pour faire ressortir une
     carte précise au milieu d'autres KPI (ex. "Total en caisse") — sinon
     comportement inchangé (fond blanc, icône teintée).

     stacked : présentation des tableaux de bord — libellé et icône en haut,
     valeur en grand sur toute la largeur (jamais tronquée, y compris sur
     téléphone), précision en dessous. Avec stacked uniquement :
     - hint : précision sous la valeur ;
     - trend : évolution en % par rapport à une période de référence (null =
       aucune comparaison possible), décrite par trendLabel ;
     - href : la carte entière devient un lien vers l'écran concerné. --}}
@props(['label', 'value', 'icon', 'color' => 'primary', 'compact' => false, 'solid' => false,
    'stacked' => false, 'hint' => null, 'trend' => null, 'trendLabel' => null, 'href' => null])

@if ($stacked)
    <{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif
        class="card h-100 border-0 shadow-sm kpi-card kpi-card--{{ $color }} {{ $href ? 'kpi-card--lien' : '' }}">
        <div class="card-body">
            <div class="kpi-card__tete">
                <span class="kpi-card__libelle">{{ $label }}</span>
                <span class="kpi-card__icone"><i class="bi {{ $icon }}"></i></span>
            </div>
            <div class="kpi-card__valeur">{{ $value }}</div>
            @if ($trend !== null || $hint)
                <div class="kpi-card__pied">
                    @if ($trend !== null)
                        <span class="kpi-card__evolution kpi-card__evolution--{{ $trend >= 0 ? 'hausse' : 'baisse' }}">
                            <i class="bi {{ $trend >= 0 ? 'bi-arrow-up-right' : 'bi-arrow-down-right' }}"></i>{{ $trend >= 0 ? '+' : '−' }}{{ abs($trend) }} %
                        </span>
                        @if ($trendLabel) <span>{{ $trendLabel }}</span> @endif
                    @else
                        <span>{{ $hint }}</span>
                    @endif
                </div>
            @endif
        </div>
    </{{ $href ? 'a' : 'div' }}>
@else
    <div class="card h-100 border-0 shadow-sm {{ $solid ? 'bg-'.$color : '' }}">
        <div class="card-body d-flex align-items-center {{ $compact ? 'gap-2 p-2' : 'gap-3' }}">
            <div class="d-flex align-items-center justify-content-center rounded-circle flex-shrink-0 {{ $solid ? 'bg-white bg-opacity-25 text-white' : 'bg-'.$color.'-subtle text-'.$color.'-emphasis' }}"
                 style="width: {{ $compact ? '2.25rem' : '2.75rem' }}; height: {{ $compact ? '2.25rem' : '2.75rem' }}; font-size: {{ $compact ? '1rem' : '1.1rem' }};">
                <i class="bi {{ $icon }}"></i>
            </div>
            <div class="overflow-hidden">
                <div class="{{ $solid ? 'text-white' : 'text-secondary' }} {{ $compact ? 'small' : '' }}" style="{{ $compact ? 'font-size: .75rem;' : '' }} {{ $solid ? 'opacity: .85;' : '' }}">{{ $label }}</div>
                <div class="fw-semibold text-truncate {{ $compact ? '' : 'fs-5' }} {{ $solid ? 'text-white' : '' }}" style="{{ $compact ? 'font-size: 1.05rem;' : '' }}">{{ $value }}</div>
            </div>
        </div>
    </div>
@endif
