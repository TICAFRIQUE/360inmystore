<?php

namespace App\Http\Controllers;

use App\Exceptions\QuantiteLivraisonInvalideException;
use App\Http\Controllers\Concerns\AutoriseMagasin;
use App\Models\BonLivraison;
use App\Models\Parametre;
use App\Models\Vente;
use App\Services\BonLivraisonService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class BonLivraisonController extends Controller
{
    use AutoriseMagasin;

    /**
     * Liste toutes les factures pas entièrement livrées, tous magasins/
     * clients confondus (comptant comme crédit, aucune restriction par type
     * de client) — pour qu'un utilisateur disposant de vente.livrer traite
     * les livraisons en attente sans devoir déjà connaître la facture
     * concernée. Aucune dépendance à une session de caisse.
     */
    public function index(Request $request): View
    {
        // Restriction silencieuse au magasin de l'utilisateur (même logique
        // que SessionCaisseController/StockMouvementController) — pas un
        // filtre affiché : un utilisateur non rattaché à un magasin
        // (superadmin) voit toutes les factures, tous magasins confondus.
        $ventes = Vente::query()
            ->livraisonIncomplete()
            ->when($request->user()->magasin_id, fn ($q, $magasinId) => $q->where('ventes.magasin_id', $magasinId))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where('numero', 'like', "%{$recherche}%");
            })
            ->with(['client', 'lignes', 'bonsLivraison.lignes'])
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('bons-livraison.index', ['ventes' => $ventes]);
    }

    public function store(Request $request, Vente $vente, BonLivraisonService $service): RedirectResponse
    {
        $this->assurerMagasin($vente->magasin_id);

        $donnees = $request->validate([
            'motif' => ['nullable', 'string', 'max:500'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.ligne_vente_id' => ['required', Rule::exists('ligne_ventes', 'id')->where('vente_id', $vente->id)],
            'lignes.*.quantite_pieces' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $bonLivraison = $service->livrer($vente, $donnees['lignes'], $request->user(), $donnees['motif'] ?? null);
        } catch (QuantiteLivraisonInvalideException|InvalidArgumentException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        return redirect()->route('ventes.ticket', $vente)
            ->with('succes', "Bon de livraison {$bonLivraison->numero} enregistré.");
    }

    public function annuler(Request $request, BonLivraison $bonLivraison, BonLivraisonService $service): RedirectResponse
    {
        $this->assurerMagasin($bonLivraison->vente->magasin_id);

        $donnees = $request->validate([
            'motif' => ['required', 'string', 'max:500'],
        ]);

        try {
            $service->annuler($bonLivraison, $request->user(), $donnees['motif']);
        } catch (InvalidArgumentException $e) {
            return back()->with('erreur', $e->getMessage());
        }

        return redirect()->route('ventes.ticket', $bonLivraison->vente)
            ->with('succes', 'Bon de livraison annulé.');
    }

    public function imprimer(BonLivraison $bonLivraison): View
    {
        $this->assurerMagasin($bonLivraison->vente->magasin_id);

        return view('bons-livraison.imprimer', $this->chargerDonnees($bonLivraison));
    }

    public function pdf(BonLivraison $bonLivraison): Response
    {
        $this->assurerMagasin($bonLivraison->vente->magasin_id);

        $pdf = Pdf::loadView('bons-livraison.imprimer', $this->chargerDonnees($bonLivraison) + ['pourPdf' => true]);

        $nomFichier = "bon-livraison-{$bonLivraison->numero}.pdf";

        return request()->boolean('imprimer') ? $pdf->stream($nomFichier) : $pdf->download($nomFichier);
    }

    private function chargerDonnees(BonLivraison $bonLivraison): array
    {
        $bonLivraison->load(['vente.client', 'vente.magasin', 'vente.caissier', 'lignes.produit', 'lignes.magasin', 'auteur']);

        $parametre = Parametre::actuel();

        return [
            'bonLivraison' => $bonLivraison,
            'parametre' => $parametre,
            'logoDataUri' => $parametre->logoDataUri(),
        ];
    }
}
