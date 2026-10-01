<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export PDF/Excel/CSV partagé par toutes les listes et rapports de l'app —
 * un seul gabarit PDF générique (resources/views/exports/liste.blade.php),
 * une seule implémentation tableur : chaque contrôleur ne fournit que ses
 * en-têtes de colonnes et ses lignes déjà formatées (chaînes/nombres),
 * jamais de logique métier dupliquée (voir CLAUDE.md — rapports = agrégats,
 * sans dupliquer la logique).
 *
 * Les quatre entrées du menu « Exporter » (voir x-export-buttons) reposent
 * sur deux routes seulement par liste : la route PDF (téléchargement, ou
 * impression avec ?imprimer=1) et la route Excel (xlsx, ou CSV avec
 * ?format=csv) — jamais une route ni une méthode de contrôleur de plus par
 * format.
 */
trait ExporteListe
{
    /**
     * @param  array<int, string>  $entetes
     * @param  iterable<int, array<int, string|int|float|null>>  $lignes
     * @param  array<string, string>|null  $bilan  Récapitulatif label => valeur affiché
     *   sous le tableau (ex. "Nombre de ventes" => "12", voir CLAUDE.md — jamais un
     *   nouveau calcul : toujours les mêmes chiffres que les KPI déjà affichés à l'écran).
     */
    protected function pdfDepuisListe(string $titre, array $entetes, iterable $lignes, string $nomFichier, ?string $sousTitre = null, ?array $bilan = null): Response
    {
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.liste', [
            'titre' => $titre,
            'sousTitre' => $sousTitre,
            'entetes' => $entetes,
            'lignes' => $lignes,
            'bilan' => $bilan,
        ]);

        // ?imprimer=1 (voir x-bouton-imprimer) : ouvre le PDF dans l'onglet au
        // lieu de forcer un téléchargement — le bouton "Imprimer" utilise
        // ainsi le même rendu PDF que le bouton "PDF", pour que l'impression
        // ne diverge jamais visuellement du fichier téléchargé.
        return request()->boolean('imprimer') ? $pdf->stream($nomFichier) : $pdf->download($nomFichier);
    }

    /**
     * @param  array<int, string>  $entetes
     * @param  iterable<int, array<int, string|int|float|null>>  $lignes
     * @param  array<string, string>|null  $bilan  Même récapitulatif que pdfDepuisListe(),
     *   ajouté sous forme de lignes après un séparateur vide.
     */
    protected function excelDepuisListe(string $titre, array $entetes, iterable $lignes, string $nomFichier, ?array $bilan = null): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $feuille = $spreadsheet->getActiveSheet();
        // Un titre de feuille Excel n'accepte ni \ / ? * [ ] ni plus de 31
        // caractères (ex. "Casse / pertes" fait échouer setTitle() sinon).
        $feuille->setTitle(mb_substr(str_replace(['\\', '/', '?', '*', '[', ']'], '-', $titre), 0, 31));
        $feuille->fromArray($entetes, null, 'A1');

        $ligneIndex = 2;
        foreach ($lignes as $ligne) {
            $this->ecrireLigne($feuille, $ligne, $ligneIndex);
            $ligneIndex++;
        }

        if (! empty($bilan)) {
            $ligneIndex++; // ligne vide de séparation
            $feuille->setCellValue('A'.$ligneIndex, 'Bilan');
            $feuille->getStyle('A'.$ligneIndex)->getFont()->setBold(true);
            $ligneIndex++;
            foreach ($bilan as $label => $valeur) {
                $feuille->fromArray([$label, $valeur], null, 'A'.$ligneIndex);
                $ligneIndex++;
            }
        }

        $derniereColonne = $this->lettreColonne(count($entetes));
        foreach (range('A', $derniereColonne) as $colonne) {
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }

        return $this->telechargerTableur($spreadsheet, $nomFichier);
    }

    /**
     * Envoie un classeur déjà construit, en Excel (par défaut) ou en CSV si
     * la requête porte ?format=csv — même contenu dans les deux cas, seul
     * le format d'écriture change. Point de sortie unique de tous les
     * exports tableur de l'app, y compris ceux qui construisent leur propre
     * classeur plutôt que de passer par excelDepuisListe().
     *
     * CSV au format attendu par un Excel français : séparateur « ; », UTF-8
     * avec BOM (sinon les accents s'affichent mal à l'ouverture), fins de
     * ligne Windows.
     */
    protected function telechargerTableur(Spreadsheet $spreadsheet, string $nomFichier): StreamedResponse
    {
        if (request()->string('format')->toString() === 'csv') {
            $writer = (new Csv($spreadsheet))
                ->setDelimiter(';')
                ->setEnclosure('"')
                ->setLineEnding("\r\n")
                ->setUseBOM(true)
                ->setSheetIndex(0);

            return response()->streamDownload(function () use ($writer) {
                $writer->save('php://output');
            }, preg_replace('/\.xlsx$/i', '.csv', $nomFichier), ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $nomFichier, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Écrit une ligne de données en gardant en texte ce que le classeur
     * réinterpréterait à tort : un numéro commençant par 0 ou + (téléphone,
     * référence — sinon le 0 initial disparaît, « 0700… » devenant 700…) et
     * un libellé libre commençant par « = » (motif, nom…), que le classeur
     * prendrait pour une formule, jusqu'à faire échouer l'export.
     *
     * @param  array<int, string|int|float|null>  $valeurs
     */
    private function ecrireLigne(Worksheet $feuille, array $valeurs, int $ligneIndex): void
    {
        foreach (array_values($valeurs) as $index => $valeur) {
            $cellule = $this->lettreColonne($index + 1).$ligneIndex;

            if (is_string($valeur) && preg_match('/^(=|\+|0\d)/', $valeur) === 1) {
                $feuille->setCellValueExplicit($cellule, $valeur, DataType::TYPE_STRING);
            } else {
                $feuille->setCellValue($cellule, $valeur);
            }
        }
    }

    /**
     * Conversion 1-indexée vers une lettre de colonne Excel (1 → A, 27 → AA…),
     * pour ne pas se limiter aux listes de moins de 26 colonnes.
     */
    private function lettreColonne(int $index): string
    {
        $lettre = '';
        while ($index > 0) {
            $index--;
            $lettre = chr(65 + ($index % 26)).$lettre;
            $index = intdiv($index, 26);
        }

        return $lettre;
    }
}
