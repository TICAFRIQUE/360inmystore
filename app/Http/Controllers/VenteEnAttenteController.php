<?php

namespace App\Http\Controllers;

use App\Enums\VenteType;
use App\Exceptions\LimiteCreditDepasseeException;
use App\Exceptions\SessionNonOuverteException;
use App\Exceptions\StockInsuffisantException;
use App\Http\Controllers\Concerns\AutoriseMagasin;
use App\Http\Controllers\Concerns\AutoriseVenteEnAttente;
use App\Http\Controllers\Concerns\ValideRemises;
use App\Models\Client;
use App\Models\SessionCaisse;
use App\Models\VenteEnAttente;
use App\Services\VenteEnAttenteService;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

class VenteEnAttenteController extends Controller
{
    use ValideRemises, AutoriseVenteEnAttente, AutoriseMagasin;

    public function index(Request $request, SessionCaisse $session): View
    {
        $this->assurerMagasin($session->caisse->magasin_id);

        // Un caissier ne voit que ses propres paniers en attente ; un gérant
        // (caisse.gerer) voit ceux de toute la caisse (voir CLAUDE.md).
        $ventesEnAttente = $session->venteEnAttentes()
            ->when(! $request->user()->can('caisse.gerer'), fn ($q) => $q->where('caissier_id', $request->user()->id))
            ->with(['lignes.produit', 'lignes.uniteVente', 'client'])
            ->latest()
            ->get();

        return view('ventes-en-attente.index', [
            'session' => $session,
            'ventesEnAttente' => $ventesEnAttente,
        ]);
    }

    public function store(Request $request, SessionCaisse $session, VenteEnAttenteService $venteEnAttenteService): RedirectResponse
    {
        $this->assurerMagasin($session->caisse->magasin_id);
        $this->nettoyerLignes($request);
        $this->bloquerCreditSansPermission($request);

        $donnees = $request->validate([
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'exists:produits,id'],
            'lignes.*.unite_vente_id' => ['nullable', 'exists:unite_ventes,id'],
            'lignes.*.magasin_source_id' => ['nullable', 'exists:magasins,id'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0.001'],
            'libelle' => ['nullable', 'string', 'max:255'],
            'client_id' => ['nullable', 'exists:clients,id'],
        ]);

        $client = ! empty($donnees['client_id']) ? Client::findOrFail($donnees['client_id']) : null;

        try {
            $venteEnAttenteService->mettreEnAttente(
                $session,
                $request->user(),
                $donnees['lignes'],
                $donnees['libelle'] ?? null,
                client: $client,
            );
        } catch (SessionNonOuverteException|InvalidArgumentException $e) {
            return back()->with('erreur', $e->getMessage());
        }

        return redirect()->route('sessions.show', $session)->with('succes', 'Vente mise en attente.');
    }

    /**
     * Remettre en attente un panier déjà repris (voir VenteController::reprendre) :
     * met à jour le même brouillon au lieu d'en créer un doublon.
     */
    public function update(Request $request, VenteEnAttente $venteEnAttente, VenteEnAttenteService $venteEnAttenteService): RedirectResponse
    {
        $this->assurerProprietaireOuGerant($venteEnAttente);
        $this->assurerMagasin($venteEnAttente->sessionCaisse->caisse->magasin_id);
        $this->nettoyerLignes($request);
        $this->bloquerCreditSansPermission($request);

        $donnees = $request->validate([
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'exists:produits,id'],
            'lignes.*.unite_vente_id' => ['nullable', 'exists:unite_ventes,id'],
            'lignes.*.magasin_source_id' => ['nullable', 'exists:magasins,id'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0.001'],
            'libelle' => ['nullable', 'string', 'max:255'],
            'client_id' => ['nullable', 'exists:clients,id'],
        ]);

        $client = ! empty($donnees['client_id']) ? Client::findOrFail($donnees['client_id']) : null;

        try {
            $venteEnAttenteService->mettreEnAttente(
                $venteEnAttente->sessionCaisse,
                $request->user(),
                $donnees['lignes'],
                $donnees['libelle'] ?? null,
                existant: $venteEnAttente,
                client: $client,
            );
        } catch (SessionNonOuverteException|InvalidArgumentException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        return redirect()->route('sessions.show', $venteEnAttente->sessionCaisse)->with('succes', 'Vente en attente mise à jour.');
    }

    public function reprendre(Request $request, VenteEnAttente $venteEnAttente, VenteEnAttenteService $venteEnAttenteService): RedirectResponse
    {
        $this->assurerProprietaireOuGerant($venteEnAttente);
        $this->assurerMagasin($venteEnAttente->sessionCaisse->caisse->magasin_id);
        $this->nettoyerLignes($request);
        $request->merge([
            'remise_totale_type' => $request->input('remise_totale_type') ?: null,
            'remise_totale_valeur' => $request->input('remise_totale_valeur') ?: null,
        ]);
        $this->bloquerRemiseSansPermission($request);
        $this->bloquerCreditSansPermission($request);
        $this->bloquerTypeSansPermission($request);

        $donnees = $request->validate([
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'exists:produits,id'],
            'lignes.*.unite_vente_id' => ['nullable', 'exists:unite_ventes,id'],
            'lignes.*.taxe_id' => ['nullable', 'exists:taxes,id'],
            'lignes.*.magasin_source_id' => ['required', 'exists:magasins,id'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0.001'],
            'lignes.*.remise_type' => ['nullable', 'in:montant,pourcentage'],
            'lignes.*.remise_valeur' => ['nullable', 'integer', $this->remiseValeurMin(), $this->remisePourcentageMax()],
            'lignes.*.prix_personnalise' => ['nullable', 'boolean'],
            'remise_totale_type' => ['nullable', 'in:montant,pourcentage'],
            'remise_totale_valeur' => ['nullable', 'integer', 'min:0', $this->remisePourcentageMax()],
            'paiements' => ['present', 'array'],
            'paiements.*.moyen_paiement_id' => ['required', 'exists:moyen_paiements,id'],
            'paiements.*.montant' => ['required', 'integer', 'min:1'],
            'montant_recu' => ['nullable', 'integer', 'min:0'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'type' => ['nullable', 'in:facture,bon_livraison'],
        ]);

        $client = ! empty($donnees['client_id']) ? Client::findOrFail($donnees['client_id']) : null;

        try {
            $vente = $venteEnAttenteService->reprendre(
                $venteEnAttente,
                $donnees['lignes'],
                $donnees['paiements'],
                $donnees['remise_totale_type'] ?? null,
                $donnees['remise_totale_valeur'] ?? null,
                $donnees['montant_recu'] ?? null,
                client: $client,
                autoriserDepassementLimite: $request->user()->can('client.depasser_limite'),
                type: VenteType::tryFrom($donnees['type'] ?? '') ?? VenteType::Facture,
            );
        } catch (StockInsuffisantException|SessionNonOuverteException|RuntimeException|InvalidArgumentException|LimiteCreditDepasseeException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        return redirect()->route('ventes.ticket', $vente)->with('succes', 'Vente finalisée.');
    }

    public function annuler(VenteEnAttente $venteEnAttente, VenteEnAttenteService $venteEnAttenteService): RedirectResponse
    {
        $this->assurerProprietaireOuGerant($venteEnAttente);
        $this->assurerMagasin($venteEnAttente->sessionCaisse->caisse->magasin_id);

        $session = $venteEnAttente->sessionCaisse;

        $venteEnAttenteService->annuler($venteEnAttente);

        return redirect()->route('sessions.show', $session)->with('succes', 'Vente en attente annulée.');
    }

    private function nettoyerLignes(Request $request): void
    {
        $lignes = collect($request->input('lignes', []))->map(function (array $ligne) {
            $ligne['unite_vente_id'] = ($ligne['unite_vente_id'] ?? null) ?: null;
            $ligne['taxe_id'] = ($ligne['taxe_id'] ?? null) ?: null;
            $ligne['magasin_source_id'] = ($ligne['magasin_source_id'] ?? null) ?: null;
            $ligne['remise_type'] = ($ligne['remise_type'] ?? null) ?: null;
            $ligne['remise_valeur'] = ($ligne['remise_valeur'] ?? null) ?: null;
            $ligne['quantite'] = Decimal::normaliser($ligne['quantite'] ?? null);

            return $ligne;
        })->all();

        // Une vente entièrement à crédit (aucun paiement saisi) ne génère
        // aucun champ paiements[...] côté JS (boucle vide) : la clé n'existe
        // alors pas du tout dans la requête — normalisée en tableau vide,
        // autorisé (règle 13 : paiement partiel ou nul si un client est
        // identifié — voir aussi VenteController::nettoyerChampsOptionnels()).
        $request->merge(['lignes' => $lignes, 'paiements' => $request->input('paiements', [])]);
    }
}
