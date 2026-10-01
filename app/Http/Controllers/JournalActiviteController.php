<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consultation du journal d'activité (traçabilité, CLAUDE.md §Traçabilité) :
 * réservé au gérant et au superadmin (permission rapport.voir), au même
 * titre que les autres rapports.
 */
class JournalActiviteController extends Controller
{
    use ExporteListe;

    private const ENTETES_EXPORT = ['Date', 'Auteur', 'Action', 'Concerne'];

    /** Libellé affiché pour chaque événement activitylog (écran et exports). */
    public const LIBELLES_EVENEMENT = ['created' => 'Créé', 'updated' => 'Modifié', 'deleted' => 'Supprimé'];

    public function index(Request $request): View
    {
        [$debut, $fin] = $this->periodeListe($request);

        $activites = $this->requeteListe($request)->paginate(30)->withQueryString();

        return view('journal.index', [
            'activites' => $activites,
            'debut' => $debut,
            'fin' => $fin,
            'types' => $this->typesDisponibles(),
            'utilisateurs' => User::orderBy('name')->get(),
        ]);
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe('Journal d\'activité', self::ENTETES_EXPORT, $this->lignesExport($request), 'journal-activite.pdf', $this->sousTitrePeriode($request));
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe('Journal d\'activité', self::ENTETES_EXPORT, $this->lignesExport($request), 'journal-activite.xlsx');
    }

    /**
     * Période affichée par la liste : du début du mois à aujourd'hui tant
     * qu'aucune date n'est choisie.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodeListe(Request $request): array
    {
        return [
            $request->filled('debut') ? Carbon::parse($request->string('debut'))->startOfDay() : Carbon::now()->startOfMonth(),
            $request->filled('fin') ? Carbon::parse($request->string('fin'))->endOfDay() : Carbon::now()->endOfDay(),
        ];
    }

    private function sousTitrePeriode(Request $request): string
    {
        [$debut, $fin] = $this->periodeListe($request);

        return 'Du '.$debut->format('d/m/Y').' au '.$fin->format('d/m/Y');
    }

    /**
     * Requête de la liste (filtres + tri), partagée par l'écran et ses exports :
     * un export porte toujours sur ce que l'écran affiche, sans la pagination.
     */
    private function requeteListe(Request $request): Builder
    {
        [$debut, $fin] = $this->periodeListe($request);

        return Activity::query()
            ->with('causer')
            ->whereBetween('created_at', [$debut, $fin])
            ->when($request->filled('type'), fn ($q) => $q->where('subject_type', $request->string('type')))
            ->when($request->filled('causeur_id'), fn ($q) => $q->where('causer_id', $request->integer('causeur_id')))
            ->orderByDesc('created_at');
    }

    private function lignesExport(Request $request): Collection
    {
        return $this->requeteListe($request)->get()->map(fn (Activity $activite) => [
            $activite->created_at->format('d/m/Y H:i'),
            $activite->causer?->name ?? '—',
            self::LIBELLES_EVENEMENT[$activite->description] ?? $activite->description,
            trim(($activite->subject_type ? class_basename($activite->subject_type) : '').($activite->subject_id ? ' #'.$activite->subject_id : '')) ?: '—',
        ]);
    }

    /**
     * @return array<string, string> classe complète => libellé court affiché
     */
    private function typesDisponibles(): array
    {
        return Activity::query()
            ->whereNotNull('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->mapWithKeys(fn (string $fqcn) => [$fqcn => class_basename($fqcn)])
            ->all();
    }
}
