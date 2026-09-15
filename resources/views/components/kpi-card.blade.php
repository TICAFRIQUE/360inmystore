{{-- solid : fond plein (bg-{color}) + texte blanc, pour faire ressortir une
     carte précise au milieu d'autres KPI (ex. "Total en caisse") — sinon
     comportement inchangé (fond blanc, icône teintée). --}}
@props(['label', 'value', 'icon', 'color' => 'primary', 'compact' => false, 'solid' => false])
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
