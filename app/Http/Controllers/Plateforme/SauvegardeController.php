<?php

namespace App\Http\Controllers\Plateforme;

use App\Http\Controllers\Controller;
use App\Models\Plateforme\Entreprise;
use App\Plateforme\ProcessusEntreprise;
use App\Services\SauvegardeBase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sauvegardes de la base d'une entreprise, depuis l'espace de l'éditeur :
 * celles de chaque soir (app:sauvegarder, 10 gardées), une sauvegarde à la
 * demande, le téléchargement et la restauration.
 *
 * Tout se fait depuis la plateforme, avec mysqldump / mysql visant la base de
 * l'entreprise : aucune connexion de l'application ne change de base en cours
 * de requête.
 */
class SauvegardeController extends Controller
{
    public function index(Entreprise $entreprise, SauvegardeBase $sauvegarde): View
    {
        return view('plateforme.sauvegardes', [
            'entreprise' => $entreprise,
            'sauvegardes' => $sauvegarde->lister($sauvegarde->dossier($entreprise->base)),
            'aGarder' => SauvegardeBase::A_GARDER,
        ]);
    }

    public function store(Entreprise $entreprise, SauvegardeBase $sauvegarde): RedirectResponse
    {
        try {
            $sauvegarde->sauvegarder($this->connexion($entreprise), $sauvegarde->dossier($entreprise->base), SauvegardeBase::MANUELLE);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('erreur', 'La sauvegarde a échoué : '.$this->raison($e));
        }

        return back()->with('succes', "Sauvegarde de « {$entreprise->nom} » enregistrée.");
    }

    public function telecharger(Entreprise $entreprise, string $fichier, SauvegardeBase $sauvegarde): BinaryFileResponse
    {
        $chemin = $sauvegarde->trouver($sauvegarde->dossier($entreprise->base), $fichier);
        abort_if($chemin === null, 404);

        return response()->download($chemin, $entreprise->sous_domaine.'-'.$fichier);
    }

    /**
     * Remplace toute la base de l'entreprise par le contenu de la sauvegarde
     * choisie. L'état actuel est d'abord sauvegardé (« avant-restauration ») :
     * si cette sauvegarde de sécurité échoue, rien n'est restauré.
     */
    public function restaurer(Request $request, Entreprise $entreprise, string $fichier, SauvegardeBase $sauvegarde, ProcessusEntreprise $processus): RedirectResponse
    {
        $dossier = $sauvegarde->dossier($entreprise->base);
        $chemin = $sauvegarde->trouver($dossier, $fichier);
        abort_if($chemin === null, 404);

        $connexion = $this->connexion($entreprise);

        try {
            $securite = $sauvegarde->sauvegarder($connexion, $dossier, SauvegardeBase::AVANT_RESTAURATION);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('erreur', "Restauration annulée : impossible de sauvegarder d'abord l'état actuel. ".$this->raison($e));
        }

        try {
            $sauvegarde->importer($connexion, $chemin);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('erreur', 'La restauration a échoué : '.$this->raison($e)." L'état précédent reste disponible : ".basename($securite).'.');
        }

        // L'espace garde en cache des données (paramètres, permissions,
        // catalogue…) qui ne correspondent plus à la base restaurée.
        $processus->artisan($entreprise->sous_domaine, ['cache:clear']);

        activity()
            ->causedBy($request->user())
            ->performedOn($entreprise)
            ->withProperties(['sauvegarde' => $fichier, 'sauvegarde_securite' => basename($securite)])
            ->log("Base de « {$entreprise->nom} » restaurée depuis une sauvegarde.");

        return back()->with('succes', "Base de « {$entreprise->nom} » restaurée à son état du ".$this->date($sauvegarde, $dossier, $fichier).". L'état précédent a été gardé : ".basename($securite).'.');
    }

    /**
     * Connexion visant la base de l'entreprise : celle de la plateforme
     * (même serveur, même compte), au nom de la base près.
     *
     * @return array<string, mixed>
     */
    private function connexion(Entreprise $entreprise): array
    {
        return ['database' => $entreprise->base] + config('database.connections.'.config('database.default'));
    }

    private function date(SauvegardeBase $sauvegarde, string $dossier, string $fichier): string
    {
        return $sauvegarde->lister($dossier)->firstWhere('nom', $fichier)['date']->format('d/m/Y à H:i');
    }

    /** Message court pour l'écran : le détail technique part au journal (report). */
    private function raison(\Throwable $e): string
    {
        return str_contains($e->getMessage(), 'introuvable') || str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'not recognized')
            ? "l'outil MySQL (mysqldump / mysql) est introuvable sur ce serveur — renseignez MYSQLDUMP_PATH et MYSQL_PATH dans le fichier .env."
            : "l'outil MySQL a renvoyé une erreur (voir le journal du serveur).";
    }
}
