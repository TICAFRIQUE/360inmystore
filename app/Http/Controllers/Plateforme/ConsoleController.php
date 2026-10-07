<?php

namespace App\Http\Controllers\Plateforme;

use App\Http\Controllers\Controller;
use App\Models\Plateforme\Entreprise;
use App\Models\Plateforme\PaiementAbonnement;
use App\Plateforme\Paiement\PasserellePaiement;
use Illuminate\View\View;

/**
 * Tableau de bord de l'éditeur (domaine principal) : où en est la plateforme
 * — clients, abonnements, argent encaissé — et ce qui demande son attention
 * (formule pas encore choisie, échéance proche, abonnement expiré). Ne lit
 * que la base de la plateforme, jamais celle d'une entreprise.
 */
class ConsoleController extends Controller
{
    /** Une échéance est « proche » à moins de ce nombre de jours. */
    private const JOURS_ECHEANCE_PROCHE = 7;

    public function index(PasserellePaiement $passerelle): View
    {
        $clientes = fn () => Entreprise::where('statut', '!=', Entreprise::CREATION);
        $actives = fn () => Entreprise::where('statut', Entreprise::ACTIVE);
        $aEcheance = fn () => $actives()->where('abonnement_illimite', false)->whereNotNull('abonnement_fin');
        $limite = today()->addDays(self::JOURS_ECHEANCE_PROCHE);

        // Argent réellement reçu : les paiements simulés n'en sont pas.
        $encaisses = fn () => PaiementAbonnement::where('statut', PaiementAbonnement::REUSSI)->where('passerelle', '!=', 'simulation');

        $mois = collect(range(5, 0))->map(fn (int $i) => now()->startOfMonth()->subMonths($i));

        return view('plateforme.tableau-de-bord', [
            'indicateurs' => [
                'total' => $clientes()->count(),
                'nouvelles_mois' => $clientes()->where('created_at', '>=', now()->startOfMonth())->count(),
                'abonnees' => $actives()->where(fn ($q) => $q->where('abonnement_illimite', true)->orWhereDate('abonnement_fin', '>=', today()))->count(),
                'sans_formule' => $actives()->where('abonnement_illimite', false)->whereNull('abonnement_fin')->count(),
                'expirees' => $aEcheance()->whereDate('abonnement_fin', '<', today())->count(),
                'suspendues' => Entreprise::where('statut', Entreprise::SUSPENDUE)->count(),
                'encaisse_mois' => $encaisses()->where('created_at', '>=', now()->startOfMonth())->sum('montant'),
                'encaisse_total' => $encaisses()->sum('montant'),
            ],
            'echeances' => $aEcheance()->whereDate('abonnement_fin', '>=', today())->whereDate('abonnement_fin', '<=', $limite)
                ->orderBy('abonnement_fin')->limit(8)->get(),
            'expirees' => $aEcheance()->whereDate('abonnement_fin', '<', today())->orderByDesc('abonnement_fin')->limit(8)->get(),
            'inscriptions' => $clientes()->latest()->limit(6)->get(),
            'paiements' => PaiementAbonnement::with('entreprise')->latest('created_at')->latest('id')->limit(8)->get(),
            'graphique' => [
                'mois' => $mois->map(fn ($m) => $m->translatedFormat('M Y'))->all(),
                'inscriptions' => $mois->map(fn ($m) => $clientes()->whereBetween('created_at', [$m, $m->copy()->endOfMonth()])->count())->all(),
                'encaisse' => $mois->map(fn ($m) => (int) $encaisses()->whereBetween('created_at', [$m, $m->copy()->endOfMonth()])->sum('montant'))->all(),
            ],
            'paiementSimule' => $passerelle->estSimulation(),
            'joursEcheanceProche' => self::JOURS_ECHEANCE_PROCHE,
        ]);
    }
}
