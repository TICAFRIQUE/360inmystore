@props(['pdfRoute', 'excelRoute', 'tout' => false])

{{-- Un seul bouton « Exporter » ouvrant un menu (PDF, Excel, CSV, Imprimer)
     plutôt qu'une rangée de boutons : même encombrement sur toutes les
     listes, quel que soit le nombre de formats. Deux routes suffisent pour
     les quatre entrées (voir ExporteListe) : le CSV est la route Excel avec
     ?format=csv, l'impression la route PDF avec ?imprimer=1 (voir
     x-bouton-imprimer) — même rendu que le PDF téléchargé, jamais un print()
     du HTML de la page qui rendrait différemment. --}}
@php
    $csvRoute = $excelRoute.(str_contains($excelRoute, '?') ? '&' : '?').'format=csv';
@endphp
<div class="dropdown d-print-none">
    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-download me-1"></i>Exporter
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li>
            <a href="{{ $pdfRoute }}" class="dropdown-item">
                <i class="bi bi-file-earmark-pdf me-2"></i>PDF
            </a>
        </li>
        <li>
            <a href="{{ $excelRoute }}" class="dropdown-item">
                <i class="bi bi-file-earmark-excel me-2"></i>Excel
            </a>
        </li>
        <li>
            <a href="{{ $csvRoute }}" class="dropdown-item">
                <i class="bi bi-filetype-csv me-2"></i>CSV
            </a>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <x-bouton-imprimer :tout="$tout" :pdf-route="$pdfRoute" dans-menu />
        </li>
    </ul>
</div>
