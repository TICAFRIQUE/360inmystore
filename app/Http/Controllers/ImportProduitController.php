<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Models\Magasin;
use App\Services\ImportProduits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Import du catalogue depuis un fichier Excel ou CSV (voir
 * App\Services\ImportProduits) : le modèle à télécharger, l'envoi du fichier,
 * et le compte rendu — produits créés, ou liste des lignes à corriger.
 */
class ImportProduitController extends Controller
{
    use ExporteListe;

    public function create(): View
    {
        return view('produits.import', [
            'magasins' => Magasin::where('actif', true)->orderBy('nom')->get(),
            'colonnes' => ImportProduits::COLONNES,
            'lignesMax' => ImportProduits::LIGNES_MAX,
        ]);
    }

    public function modele(ImportProduits $import): StreamedResponse
    {
        return $this->telechargerTableur($import->modele(), 'modele-import-produits.xlsx');
    }

    public function store(Request $request, ImportProduits $import): RedirectResponse
    {
        $donnees = $request->validate([
            'fichier' => ['required', 'file', 'max:5120', 'extensions:xlsx,xls,csv'],
            'magasin_id' => ['nullable', 'exists:magasins,id'],
        ], [
            'fichier.required' => 'Choisissez le fichier à importer.',
            'fichier.max' => 'Le fichier ne doit pas dépasser 5 Mo.',
            'fichier.extensions' => 'Envoyez un classeur Excel (.xlsx, .xls) ou un fichier CSV.',
        ]);

        $magasin = isset($donnees['magasin_id']) ? Magasin::find($donnees['magasin_id']) : null;
        $analyse = $import->analyser($request->file('fichier')->getRealPath(), $magasin !== null);

        if ($analyse['erreurs']) {
            return back()->withInput()->with('erreursImport', $analyse['erreurs']);
        }

        // Point d'extension facultatif : sur la plateforme, la formule de
        // l'entreprise peut limiter le nombre de produits. Absent sinon.
        if (app()->bound('limite.creation') && ($refus = app('limite.creation')('produits', count($analyse['lignes'])))) {
            return back()->withInput()->with('erreur', $refus);
        }

        $nombre = $import->importer($analyse['lignes'], $magasin, $request->user());

        return redirect()->route('produits.index')
            ->with('succes', $nombre.' produit'.($nombre > 1 ? 's importés' : ' importé').'.');
    }
}
