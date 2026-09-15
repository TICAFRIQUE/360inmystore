<?php

namespace App\Http\Controllers;

use App\Exceptions\AvoirClientInsuffisantException;
use App\Exceptions\QuantiteRetourInvalideException;
use App\Exceptions\RetourDateLimiteDepasseeException;
use App\Exceptions\SoldeCaisseInsuffisantException;
use App\Http\Controllers\Concerns\AutoriseMagasin;
use App\Models\Client;
use App\Models\SessionCaisse;
use App\Models\Vente;
use App\Services\RemboursementAvoirClientService;
use App\Services\RetourVenteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class RetourVenteController extends Controller
{
    use AutoriseMagasin;

    /**
     * Point d'entrée dédié pour "faire un retour" sans devoir déjà être sur
     * la facture concernée : recherche par numéro, même principe que
     * BonLivraisonController::index() (liste globale, pas de dépendance à
     * une session de caisse). Chaque résultat mène à la facture (ticket),
     * où le retour se fait réellement — un retour reste toujours rattaché à
     * un document précis, jamais un avoir libre (règle 18).
     */
    public function rechercher(Request $request): View
    {
        $ventes = Vente::query()
            ->retournable()
            ->when($request->user()->magasin_id, fn ($q, $magasinId) => $q->where('ventes.magasin_id', $magasinId))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where('numero', 'like', "%{$recherche}%");
            })
            // retours.lignes : quantité déjà retournée (colonne "Retourné",
            // format X/Y pièce(s)). retours.client : à qui l'avoir a été
            // crédité pour une vente comptant (colonne "Client"), sans quoi
            // un retour déjà fait sur cette facture restait invisible ici —
            // évite un N+1 sur chaque ligne de la liste.
            ->with(['client', 'lignes', 'retours.lignes', 'retours.client'])
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        // Bandeau d'avertissement si l'utilisateur n'a pas de session ouverte
        // — un retour ne peut de toute façon plus être enregistré sans elle.
        $sessionOuverte = SessionCaisse::where('caissier_id', $request->user()->id)
            ->whereNull('date_fermeture')
            ->whereNull('date_cloture')
            ->exists();

        return view('retours.rechercher', ['ventes' => $ventes, 'sessionOuverte' => $sessionOuverte]);
    }

    public function store(
        Request $request,
        Vente $vente,
        RetourVenteService $retourService,
        RemboursementAvoirClientService $remboursementService
    ): RedirectResponse {
        $this->assurerMagasin($vente->magasin_id);

        // Un retour exige désormais une session de caisse ouverte — celle du
        // caissier qui traite le retour, pas forcément celle de la vente
        // d'origine (voir CLAUDE.md, section Retours). Résolue une seule
        // fois, réutilisée pour le retour ET le remboursement immédiat
        // éventuel ci-dessous.
        $sessionOuverte = SessionCaisse::where('caissier_id', $request->user()->id)
            ->whereNull('date_fermeture')
            ->whereNull('date_cloture')
            ->first();

        if (! $sessionOuverte) {
            return back()->with('erreur', "Aucune session de caisse ouverte : impossible d'enregistrer un retour.");
        }

        // Sans la permission client.reglement, aucun remboursement immédiat
        // ne doit jamais être déclenché — on efface le champ avant
        // validation plutôt que de faire confiance à l'écran (qui masque
        // déjà le bouton "Rembourser maintenant"), au cas où une requête
        // serait construite à la main pour contourner l'écran. Même motif
        // que ValideRemises::bloquerCreditSansPermission(). Le superadmin
        // passe toujours (bypass Gate::before).
        if (! $request->user()->can('client.reglement')) {
            $request->merge(['rembourser_immediat' => false]);
        }

        $donnees = $request->validate([
            'motif' => ['nullable', 'string', 'max:500'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.ligne_vente_id' => ['required', Rule::exists('ligne_ventes', 'id')->where('vente_id', $vente->id)],
            'lignes.*.quantite_pieces' => ['required', 'numeric', 'min:0'],
            // Vente comptant (aucun client) : le formulaire impose de choisir
            // ou créer le client réellement crédité — jamais un simple repli
            // silencieux sur "Client comptant" (plusieurs personnes
            // différentes font des ventes comptant, voir CLAUDE.md Retours).
            // Exception : remboursé tout de suite, rien ne reste sur un
            // compte, donc pas besoin d'identifier qui que ce soit.
            'client_id_retour' => [
                Rule::requiredIf($vente->client_id === null && ! $request->boolean('rembourser_immediat')),
                'nullable', 'exists:clients,id',
            ],
            'rembourser_immediat' => ['nullable', 'boolean'],
            // required_if (pas required_with) : les champs de paiement restent
            // dans le DOM même masqués (x-show, pas retirés) — leurs valeurs
            // vides sont soumises même en mode "avoir", required_with se
            // déclenchait alors à tort dès que le tableau était présent.
            'paiements' => ['required_if:rembourser_immediat,1', 'nullable', 'array'],
            'paiements.*.moyen_paiement_id' => ['required_if:rembourser_immediat,1', 'nullable', 'exists:moyen_paiements,id'],
            'paiements.*.montant' => ['required_if:rembourser_immediat,1', 'nullable', 'integer', 'min:1'],
        ]);

        $clientCredite = $vente->client
            ?? (($donnees['client_id_retour'] ?? null) ? Client::findOrFail($donnees['client_id_retour']) : Client::comptantDefaut());

        try {
            $retour = $retourService->retourner($vente, $donnees['lignes'], $request->user(), $sessionOuverte, $donnees['motif'] ?? null, $clientCredite);
        } catch (QuantiteRetourInvalideException|RetourDateLimiteDepasseeException|InvalidArgumentException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        // Le retour est déjà enregistré à ce stade (avoir crédité, stock
        // remis) : un remboursement immédiat n'est qu'une seconde action,
        // distincte et postérieure (règle 20) — si elle échoue, l'avoir
        // reste posé sur le compte et se rembourse plus tard normalement,
        // depuis la fiche client ou l'écran "Avoirs clients".
        if ($request->boolean('rembourser_immediat')) {
            try {
                $remboursementService->rembourser($clientCredite, $request->user(), $sessionOuverte, $donnees['paiements']);
            } catch (AvoirClientInsuffisantException|SoldeCaisseInsuffisantException|InvalidArgumentException $e) {
                return redirect()->route('ventes.ticket', $vente)
                    ->with('succes', 'Retour enregistré. Le stock et le compte client ont été mis à jour.')
                    ->with('erreur', "Remboursement immédiat impossible : {$e->getMessage()} L'avoir reste disponible sur le compte.");
            }
        }

        return redirect()->route('ventes.ticket', $vente)
            ->with('succes', 'Retour enregistré. Le stock et le compte client ont été mis à jour.'
                .($request->boolean('rembourser_immediat') ? ' Avoir remboursé.' : ''));
    }
}
