<?php

namespace App\Http\Controllers;

use App\Exceptions\AvoirClientInsuffisantException;
use App\Exceptions\SoldeCaisseInsuffisantException;
use App\Http\Controllers\Concerns\ExporteListe;
use App\Models\Client;
use App\Models\MoyenPaiement;
use App\Models\SessionCaisse;
use App\Services\RemboursementAvoirClientService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Règle un avoir client (solde négatif — voir CLAUDE.md, section Retours) :
 * action distincte du retour lui-même, qui ne rembourse jamais directement
 * en espèces. Exige une session de caisse ouverte (celle du caissier qui
 * traite le remboursement) : la part payée en espèces sort du tiroir de
 * cette session, plafonnée à son solde théorique.
 */
class RemboursementAvoirClientController extends Controller
{
    use ExporteListe;

    private const ENTETES_EXPORT = ['Client', 'Code', 'Avoir disponible'];

    /**
     * Liste centralisée de tous les clients ayant un avoir disponible
     * (solde négatif), avec un remboursement possible directement depuis
     * cette page — symétrique de ReglementClientController::create() côté
     * dette. $sessionOuverte sert à afficher un bandeau/désactiver le
     * bouton "Rembourser" si l'utilisateur n'a pas de session ouverte.
     */
    public function index(Request $request): View
    {
        return view('remboursements-avoir-client.index', [
            'clients' => Client::actifsAvecAvoir(),
            'moyensPaiement' => MoyenPaiement::actifs(),
            'sessionOuverte' => SessionCaisse::where('caissier_id', $request->user()->id)
                ->whereNull('date_fermeture')
                ->whereNull('date_cloture')
                ->first(),
        ]);
    }

    public function pdf(): Response
    {
        return $this->pdfDepuisListe('Avoirs clients', self::ENTETES_EXPORT, $this->lignesExport(), 'avoirs-clients.pdf');
    }

    public function excel(): StreamedResponse
    {
        return $this->excelDepuisListe('Avoirs clients', self::ENTETES_EXPORT, $this->lignesExport(), 'avoirs-clients.xlsx');
    }

    private function lignesExport(): Collection
    {
        return Client::actifsAvecAvoir()->map(fn (Client $client) => [
            $client->nom,
            $client->code,
            montant(-$client->solde),
        ]);
    }

    public function store(Request $request, Client $client, RemboursementAvoirClientService $service): RedirectResponse
    {
        $donnees = $request->validate([
            'paiements' => ['required', 'array', 'min:1'],
            'paiements.*.moyen_paiement_id' => ['required', 'exists:moyen_paiements,id'],
            'paiements.*.montant' => ['required', 'integer', 'min:1'],
        ]);

        $sessionOuverte = SessionCaisse::where('caissier_id', $request->user()->id)
            ->whereNull('date_fermeture')
            ->whereNull('date_cloture')
            ->first();

        if (! $sessionOuverte) {
            return back()->withInput()->with('erreur', "Aucune session de caisse ouverte : impossible de rembourser un avoir.");
        }

        try {
            $service->rembourser(
                client: $client,
                auteur: $request->user(),
                session: $sessionOuverte,
                paiements: $donnees['paiements'],
            );
        } catch (AvoirClientInsuffisantException|SoldeCaisseInsuffisantException|InvalidArgumentException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        return back()->with('succes', 'Avoir remboursé.');
    }
}
