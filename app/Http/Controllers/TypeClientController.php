<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\TrieListe;
use App\Models\TypeClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TypeClientController extends Controller
{
    use TrieListe, ExporteListe;

    private const ENTETES_EXPORT = ['Nom', 'Clients', 'Statut'];

    public function index(Request $request): View
    {
        $typesClient = $this->requeteListe($request)->paginate(20)->withQueryString();

        return view('type-clients.index', ['typesClient' => $typesClient]);
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe('Types de client', self::ENTETES_EXPORT, $this->lignesExport($request), 'types-de-client.pdf');
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe('Types de client', self::ENTETES_EXPORT, $this->lignesExport($request), 'types-de-client.xlsx');
    }

    /**
     * Requête de la liste (filtres + tri), partagée par l'écran et ses exports :
     * un export porte toujours sur ce que l'écran affiche, sans la pagination.
     */
    private function requeteListe(Request $request): Builder
    {
        $query = TypeClient::query()
            ->withCount('clients')
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where('nom', 'like', "%{$recherche}%");
            });

        return $this->appliquerTri($query, $request, ['nom', 'actif', 'created_at']);
    }

    private function lignesExport(Request $request): Collection
    {
        return $this->requeteListe($request)->get()->map(fn (TypeClient $typeClient) => [
            $typeClient->nom,
            $typeClient->clients_count,
            $typeClient->actif ? 'Actif' : 'Inactif',
        ]);
    }

    public function create(): View
    {
        return view('type-clients.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request);

        TypeClient::create($donnees);

        return redirect()->route('type-clients.index')->with('succes', 'Type de client créé.');
    }

    public function edit(TypeClient $typeClient): View
    {
        return view('type-clients.edit', ['typeClient' => $typeClient]);
    }

    public function update(Request $request, TypeClient $typeClient): RedirectResponse
    {
        $donnees = $this->valider($request, $typeClient);

        $typeClient->update($donnees);

        return redirect()->route('type-clients.index')->with('succes', 'Type de client mis à jour.');
    }

    public function destroy(TypeClient $typeClient): RedirectResponse
    {
        if ($typeClient->clients()->exists()) {
            return redirect()->route('type-clients.index')->with('erreur', 'Ce type est utilisé par des clients, il ne peut pas être supprimé.');
        }

        $typeClient->delete();

        return redirect()->route('type-clients.index')->with('succes', 'Type de client supprimé.');
    }

    private function valider(Request $request, ?TypeClient $typeClient = null): array
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:255', 'unique:type_clients,nom,'.($typeClient?->id)],
        ]);

        $donnees['actif'] = $request->boolean('actif', true);

        return $donnees;
    }
}
