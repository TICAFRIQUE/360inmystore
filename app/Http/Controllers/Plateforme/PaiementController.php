<?php

namespace App\Http\Controllers\Plateforme;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Controller;
use App\Models\Plateforme\PaiementAbonnement;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Registre des paiements d'abonnement de la plateforme, vu par l'éditeur :
 * tous les paiements de toutes les entreprises — en ligne, enregistrés à la
 * main, simulés ; réussis comme refusés. Lecture seule : un paiement est
 * immuable.
 */
class PaiementController extends Controller
{
    use ExporteListe;

    private const ENTETES_EXPORT = ['Date', 'Entreprise', 'Formule', 'Origine', 'Moyen', 'Référence', 'Statut', 'Montant'];

    private const ORIGINES = ['manuel' => 'Enregistré à la main', 'simulation' => 'Simulation'];

    public function index(Request $request): View
    {
        [$debut, $fin] = $this->periode($request);

        // Total de la période filtrée : uniquement l'argent réellement reçu.
        $encaisse = $this->requeteListe($request)
            ->where('statut', PaiementAbonnement::REUSSI)->where('passerelle', '!=', 'simulation')->sum('montant');

        return view('plateforme.paiements', [
            'paiements' => $this->requeteListe($request)->paginate(30)->withQueryString(),
            'encaisse' => $encaisse,
            'dateDebut' => $debut->toDateString(),
            'dateFin' => $fin->toDateString(),
        ]);
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe("Paiements d'abonnement", self::ENTETES_EXPORT, $this->lignesExport($request), 'paiements-abonnement.pdf', $this->sousTitrePeriode($request));
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe("Paiements d'abonnement", self::ENTETES_EXPORT, $this->lignesExport($request), 'paiements-abonnement.xlsx');
    }

    /**
     * Période affichée : les 30 derniers jours tant qu'aucune date n'est choisie.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periode(Request $request): array
    {
        return [
            $request->filled('date_debut') ? Carbon::parse($request->string('date_debut'))->startOfDay() : today()->subDays(30),
            $request->filled('date_fin') ? Carbon::parse($request->string('date_fin'))->endOfDay() : now()->endOfDay(),
        ];
    }

    private function sousTitrePeriode(Request $request): string
    {
        [$debut, $fin] = $this->periode($request);

        return 'Du '.$debut->format('d/m/Y').' au '.$fin->format('d/m/Y');
    }

    /**
     * Requête de la liste (filtres + tri), partagée par l'écran et ses exports :
     * un export porte toujours sur ce que l'écran affiche, sans la pagination.
     */
    private function requeteListe(Request $request): Builder
    {
        [$debut, $fin] = $this->periode($request);

        return PaiementAbonnement::query()
            ->with('entreprise')
            ->whereBetween('created_at', [$debut, $fin])
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where(function ($sub) use ($recherche) {
                    $sub->where('reference', 'like', "%{$recherche}%")
                        ->orWhereHas('entreprise', fn ($e) => $e->where('nom', 'like', "%{$recherche}%")->orWhere('sous_domaine', 'like', "%{$recherche}%"));
                });
            })
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->string('origine')->toString(), fn ($q, $origine) => match ($origine) {
                'en_ligne' => $q->whereNotIn('passerelle', array_keys(self::ORIGINES)),
                default => $q->where('passerelle', $origine),
            })
            ->latest('created_at')->latest('id');
    }

    private function lignesExport(Request $request): Collection
    {
        return $this->requeteListe($request)->get()->map(fn (PaiementAbonnement $paiement) => [
            $paiement->created_at->format('d/m/Y H:i'),
            $paiement->entreprise->nom,
            $paiement->formule_nom,
            $paiement->libelleOrigine(),
            $paiement->libelleMoyen(),
            $paiement->reference,
            $paiement->statut === PaiementAbonnement::REUSSI ? 'Réussi' : 'Refusé',
            montant($paiement->montant),
        ]);
    }
}
