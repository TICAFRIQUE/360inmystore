<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\TrieListe;
use App\Models\Devise;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeviseController extends Controller
{
    use TrieListe, ExporteListe;

    private const ENTETES_EXPORT = ['Nom', 'Abréviation', 'Statut'];

    public function index(Request $request): View
    {
        $devises = $this->requeteListe($request)->paginate(20)->withQueryString();

        return view('devises.index', ['devises' => $devises]);
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe('Devises', self::ENTETES_EXPORT, $this->lignesExport($request), 'devises.pdf');
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe('Devises', self::ENTETES_EXPORT, $this->lignesExport($request), 'devises.xlsx');
    }

    /**
     * Requête de la liste (filtres + tri), partagée par l'écran et ses exports :
     * un export porte toujours sur ce que l'écran affiche, sans la pagination.
     */
    private function requeteListe(Request $request): Builder
    {
        $query = Devise::query()
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where(function ($sub) use ($recherche) {
                    $sub->where('nom', 'like', "%{$recherche}%")
                        ->orWhere('abreviation', 'like', "%{$recherche}%");
                });
            });

        return $this->appliquerTri($query, $request, ['nom', 'abreviation', 'actif', 'created_at']);
    }

    private function lignesExport(Request $request): Collection
    {
        return $this->requeteListe($request)->get()->map(fn (Devise $devise) => [
            $devise->nom,
            $devise->abreviation,
            $devise->actif ? 'Active' : 'Inactive',
        ]);
    }

    public function create(): View
    {
        return view('devises.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request);

        Devise::create($donnees);

        return redirect()->route('devises.index')->with('succes', 'Devise créée.');
    }

    public function edit(Devise $devise): View
    {
        return view('devises.edit', ['devise' => $devise]);
    }

    public function update(Request $request, Devise $devise): RedirectResponse
    {
        $donnees = $this->valider($request, $devise);

        $devise->update($donnees);

        return redirect()->route('devises.index')->with('succes', 'Devise mise à jour.');
    }

    public function destroy(Devise $devise): RedirectResponse
    {
        if (\App\Models\Parametre::actuel()->devise_id === $devise->id) {
            return redirect()->route('devises.index')->with('erreur', "C'est la devise actuellement utilisée, choisissez-en une autre dans Paramètres avant de la supprimer.");
        }

        $devise->delete();

        return redirect()->route('devises.index')->with('succes', 'Devise supprimée.');
    }

    private function valider(Request $request, ?Devise $devise = null): array
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:255', 'unique:devises,nom,'.($devise?->id)],
            'abreviation' => ['required', 'string', 'max:20'],
        ]);

        $donnees['actif'] = $request->boolean('actif', true);

        return $donnees;
    }
}
