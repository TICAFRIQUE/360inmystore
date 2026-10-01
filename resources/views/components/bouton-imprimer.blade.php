{{-- dansMenu : rendu comme entrée d'un menu déroulant (voir x-export-buttons)
     plutôt que comme bouton autonome — même comportement, seules les classes
     changent. --}}
@props(['tout' => false, 'pdfRoute' => null, 'dansMenu' => false])
@php
    $classesBouton = $dansMenu ? 'dropdown-item' : 'btn btn-sm btn-outline-secondary d-print-none';
    $classesIcone = $dansMenu ? 'bi bi-printer me-2' : 'bi bi-printer me-1';
@endphp
@if ($pdfRoute)
    {{-- Un pdfRoute fourni : "Imprimer" ouvre directement le dialogue
         d'impression sur le même PDF que le bouton "PDF" (voir
         ExporteListe::pdfDepuisListe(), ?imprimer=1 → stream() au lieu de
         download()) — jamais un window.print() du HTML de la page (qui
         rendait différemment : cards, formulaires…). --}}
    <button type="button" class="{{ $classesBouton }}"
        onclick="window.__imprimerPdf(this.dataset.pdfUrl)"
        data-pdf-url="{{ $pdfRoute . (str_contains($pdfRoute, '?') ? '&' : '?') . 'imprimer=1' }}">
        <i class="{{ $classesIcone }}"></i>Imprimer
    </button>
    <script>
        window.__imprimerPdf = function (url) {
            // Coquille desktop (Electron) : affiche directement le PDF dans
            // sa propre fenêtre (lecteur PDF intégré) — plus fiable qu'un
            // <iframe> caché, dont l'impression retombait sur la page visible
            // derrière au lieu du PDF (Electron ne cible pas correctement un
            // cadre caché avec printToPDF()).
            if (window.inmystore && window.inmystore.printPdfUrl) {
                window.inmystore.printPdfUrl(url);
                return;
            }
            // Navigateur classique : chargé dans un iframe caché plutôt
            // qu'un nouvel onglet, pour ne jamais faire "sortir" l'utilisateur
            // de l'écran courant.
            let iframe = document.getElementById('__iframeImpressionPdf');
            if (! iframe) {
                iframe = document.createElement('iframe');
                iframe.id = '__iframeImpressionPdf';
                iframe.style.display = 'none';
                document.body.appendChild(iframe);
            }
            iframe.onload = function () {
                setTimeout(() => {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                }, 200);
            };
            iframe.src = url;
        };
    </script>
@elseif ($tout)
    <button type="button" class="{{ $classesBouton }}" onclick="window.__imprimerRapportComplet()">
        <i class="{{ $classesIcone }}"></i>Imprimer
    </button>
    <script>
        window.__imprimerRapportComplet = function () {
            const url = new URL(window.location.href);
            url.searchParams.set('tout', '1');
            window.location.href = url.toString();
        };
        @if (request()->boolean('tout'))
            window.addEventListener('load', () => setTimeout(() => {
                (window.inmystore && window.inmystore.print) ? window.inmystore.print() : window.print();
            }, 200));
        @endif
    </script>
@else
    <button type="button" class="{{ $classesBouton }}"
        onclick="(window.inmystore && window.inmystore.print) ? window.inmystore.print() : window.print()">
        <i class="{{ $classesIcone }}"></i>Imprimer
    </button>
@endif
