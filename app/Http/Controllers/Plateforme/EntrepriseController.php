<?php

namespace App\Http\Controllers\Plateforme;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\TrieListe;
use App\Http\Controllers\Controller;
use App\Models\FormuleAbonnement;
use App\Models\Plateforme\AccesEspace;
use App\Models\Plateforme\Entreprise;
use App\Plateforme\LienAcces;
use App\Plateforme\Paiement\ActivationParEditeur;
use App\Support\Telephone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entreprises clientes de la plateforme, vues par l'éditeur : registre,
 * suspension et réouverture d'un espace, activation d'un abonnement payé en
 * direct, entrée dans un espace pour la maintenance. Aucune donnée métier
 * d'une entreprise n'est lue ici — uniquement la base de la plateforme.
 */
class EntrepriseController extends Controller
{
    use TrieListe, ExporteListe;

    private const ENTETES_EXPORT = ['Entreprise', 'Adresse', 'Propriétaire', 'Téléphone', 'Pays', 'Statut', 'Formule', 'Abonnement', 'Créée le'];

    public function index(Request $request): View
    {
        return view('plateforme.entreprises', [
            'entreprises' => $this->requeteListe($request)->paginate(20)->withQueryString(),
            'formules' => FormuleAbonnement::where('actif', true)->orderBy('prix')->get(),
            'moyens' => ActivationParEditeur::MOYENS,
            'domaine' => config('plateforme.domaine'),
        ]);
    }

    public function suspendre(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $donnees = $request->validate(['motif' => ['required', 'string', 'max:255']]);

        $entreprise->update(['statut' => Entreprise::SUSPENDUE, 'motif_suspension' => $donnees['motif']]);

        return back()->with('succes', "L'espace « {$entreprise->nom} » est suspendu : plus personne ne peut s'y connecter.");
    }

    public function reactiver(Entreprise $entreprise): RedirectResponse
    {
        abort_unless($entreprise->statut === Entreprise::SUSPENDUE, 404);

        $entreprise->update(['statut' => Entreprise::ACTIVE, 'motif_suspension' => null]);

        return back()->with('succes', "L'espace « {$entreprise->nom} » est de nouveau accessible.");
    }

    /**
     * Abonnement payé en direct à l'éditeur, ou jours offerts : même
     * formulaire que l'activation de la version installée (formule du
     * catalogue, ou durée et montant libres).
     */
    public function activer(Request $request, Entreprise $entreprise, ActivationParEditeur $activation): RedirectResponse
    {
        abort_unless($entreprise->estActive(), 404);

        // Une case décochée n'envoie aucun champ (comportement HTML standard) :
        // toujours lire via boolean(), jamais valider sa présence.
        $illimite = $request->boolean('illimite');

        $donnees = $request->validate([
            'formule_id' => ['nullable', Rule::exists(FormuleAbonnement::class, 'id')->where('actif', true)],
            'jours' => [$request->filled('formule_id') || $illimite ? 'nullable' : 'required', 'nullable', 'integer', 'min:1'],
            'montant' => ['required', 'integer', 'min:0'],
            'moyen' => ['required', Rule::in(array_keys(ActivationParEditeur::MOYENS))],
        ]);

        $formule = ! empty($donnees['formule_id']) ? FormuleAbonnement::findOrFail($donnees['formule_id']) : null;

        try {
            $paiement = $activation->activer(
                entreprise: $entreprise,
                formule: $formule,
                montant: (int) $donnees['montant'],
                jours: $formule ? $formule->jours : ($donnees['jours'] ?? null),
                illimite: $formule ? $formule->illimite : $illimite,
                moyen: $donnees['moyen'],
                remplacer: $request->boolean('remplacer'),
                editeur: $request->user(),
            );
        } catch (RuntimeException $erreur) {
            Log::error("Activation manuelle de « {$entreprise->sous_domaine} » en échec : ".$erreur->getMessage());

            return back()->with('erreur', "L'abonnement de « {$entreprise->nom} » n'a pas pu être activé. Aucun paiement n'a été enregistré.");
        }

        return back()->with('succes', "Abonnement de « {$entreprise->nom} » activé — paiement {$paiement->reference} enregistré.");
    }

    /**
     * Entrée de l'éditeur dans l'espace d'un client, pour la maintenance :
     * lien à usage unique, valable quelques minutes, tracé des deux côtés
     * (registre des accès ici, journal d'activité dans l'espace).
     */
    public function ouvrir(Request $request, Entreprise $entreprise, LienAcces $liens): RedirectResponse
    {
        abort_unless($entreprise->estActive(), 404);

        return redirect()->away($liens->creer($entreprise, AccesEspace::MAINTENANCE, $request->user()->name));
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe('Entreprises', self::ENTETES_EXPORT, $this->lignesExport($request), 'entreprises.pdf');
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe('Entreprises', self::ENTETES_EXPORT, $this->lignesExport($request), 'entreprises.xlsx');
    }

    /**
     * Requête de la liste (filtres + tri), partagée par l'écran et ses exports :
     * un export porte toujours sur ce que l'écran affiche, sans la pagination.
     */
    private function requeteListe(Request $request): Builder
    {
        $query = Entreprise::query()
            // Une fiche encore en « creation » est une inscription en cours
            // (ou interrompue) : pas encore une entreprise cliente.
            ->where('statut', '!=', Entreprise::CREATION)
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where(function ($sub) use ($recherche) {
                    $sub->where('nom', 'like', "%{$recherche}%")
                        ->orWhere('sous_domaine', 'like', "%{$recherche}%")
                        ->orWhere('proprietaire_nom', 'like', "%{$recherche}%")
                        ->when(Telephone::pourRecherche($recherche) !== '', fn ($t) => $t->orWhere('telephone', 'like', '%'.Telephone::pourRecherche($recherche).'%'));
                });
            })
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->string('abonnement')->toString(), fn ($q, $abonnement) => match ($abonnement) {
                'sans_formule' => $q->where('abonnement_illimite', false)->whereNull('abonnement_fin'),
                'expire' => $q->where('abonnement_illimite', false)->whereDate('abonnement_fin', '<', today()),
                'en_cours' => $q->where(fn ($s) => $s->where('abonnement_illimite', true)->orWhereDate('abonnement_fin', '>=', today())),
                default => $q,
            });

        return $this->appliquerTri($query, $request, ['nom', 'sous_domaine', 'statut', 'abonnement_fin', 'created_at']);
    }

    private function lignesExport(Request $request): Collection
    {
        return $this->requeteListe($request)->get()->map(fn (Entreprise $entreprise) => [
            $entreprise->nom,
            $entreprise->sous_domaine.'.'.config('plateforme.domaine'),
            $entreprise->proprietaire_nom,
            telephone($entreprise->telephone),
            Telephone::PAYS[$entreprise->pays] ?? $entreprise->pays,
            $entreprise->estActive() ? 'Active' : 'Suspendue',
            $entreprise->formule_nom ?? '—',
            $entreprise->libelleAbonnement(),
            $entreprise->created_at->format('d/m/Y'),
        ]);
    }
}
