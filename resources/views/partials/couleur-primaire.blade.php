@php
    $couleurPrimaire = $parametre->couleur_primaire ?? null;
@endphp
@if ($couleurPrimaire && \App\Support\PaletteCouleur::estValide($couleurPrimaire) && strtolower($couleurPrimaire) !== \App\Support\PaletteCouleur::DEFAUT)
    @php($n = \App\Support\PaletteCouleur::nuances($couleurPrimaire))
    <style>
        :root {
            --bs-primary: {{ $n['primaire'] }};
            --bs-primary-rgb: {{ $n['primaire_rgb'] }};
            --bs-primary-text-emphasis: {{ $n['texte_emphase'] }};
            --bs-primary-bg-subtle: {{ $n['fond_attenue'] }};
            --bs-primary-border-subtle: {{ $n['bordure_attenuee'] }};
            --bs-link-color: {{ $n['primaire'] }};
            --bs-link-color-rgb: {{ $n['primaire_rgb'] }};
            --bs-link-hover-color: {{ $n['lien_survol'] }};
            --bs-link-hover-color-rgb: {{ $n['lien_survol_rgb'] }};
        }

        .btn-primary {
            --bs-btn-bg: {{ $n['primaire'] }};
            --bs-btn-border-color: {{ $n['primaire'] }};
            --bs-btn-hover-bg: {{ $n['btn_hover_bg'] }};
            --bs-btn-hover-border-color: {{ $n['btn_hover_border'] }};
            --bs-btn-active-bg: {{ $n['btn_active_bg'] }};
            --bs-btn-active-border-color: {{ $n['btn_active_border'] }};
            --bs-btn-disabled-bg: {{ $n['primaire'] }};
            --bs-btn-disabled-border-color: {{ $n['primaire'] }};
            --bs-btn-focus-shadow-rgb: {{ $n['primaire_rgb'] }};
        }

        .btn-outline-primary {
            --bs-btn-color: {{ $n['primaire'] }};
            --bs-btn-border-color: {{ $n['primaire'] }};
            --bs-btn-hover-color: #fff;
            --bs-btn-hover-bg: {{ $n['primaire'] }};
            --bs-btn-hover-border-color: {{ $n['primaire'] }};
            --bs-btn-active-color: #fff;
            --bs-btn-active-bg: {{ $n['primaire'] }};
            --bs-btn-active-border-color: {{ $n['primaire'] }};
            --bs-btn-disabled-color: {{ $n['primaire'] }};
            --bs-btn-disabled-border-color: {{ $n['primaire'] }};
            --bs-btn-focus-shadow-rgb: {{ $n['primaire_rgb'] }};
        }

        .form-control:focus,
        .form-select:focus {
            border-color: {{ $n['bordure_champ_focus'] }};
            box-shadow: 0 0 0 0.2rem rgba({{ $n['primaire_rgb'] }}, 0.25);
        }

        .select2-container--bootstrap-5.select2-container--focus .select2-selection,
        .select2-container--bootstrap-5.select2-container--open .select2-selection {
            border-color: {{ $n['primaire'] }} !important;
            box-shadow: 0 0 0 0.25rem rgba({{ $n['primaire_rgb'] }}, 0.25) !important;
        }

        .select2-container--bootstrap-5 .select2-dropdown {
            border-color: {{ $n['primaire'] }} !important;
        }

        .select2-container--bootstrap-5 .select2-dropdown .select2-search__field:focus {
            border-color: {{ $n['primaire'] }} !important;
            box-shadow: 0 0 0 0.25rem rgba({{ $n['primaire_rgb'] }}, 0.25) !important;
        }

        .select2-container--bootstrap-5 .select2-dropdown .select2-results__options .select2-results__option--selected,
        .select2-container--bootstrap-5 .select2-dropdown .select2-results__options .select2-results__option[aria-selected='true']:not(.select2-results__option--highlighted) {
            background-color: {{ $n['primaire'] }} !important;
        }

        .login-layout__vitrine {
            background: linear-gradient(160deg, var(--erp-ink) 0%, {{ $n['primaire'] }} 100%);
        }

        .pagination {
            --bs-pagination-active-bg: {{ $n['primaire'] }};
            --bs-pagination-active-border-color: {{ $n['primaire'] }};
            --bs-pagination-focus-box-shadow: 0 0 0 0.25rem rgba({{ $n['primaire_rgb'] }}, 0.25);
        }
    </style>
@endif
