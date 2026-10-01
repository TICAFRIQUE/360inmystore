<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Http\Controllers\Concerns\TrieListe;
use App\Models\Magasin;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UtilisateurController extends Controller
{
    use TrieListe, ExporteListe;

    private const ENTETES_EXPORT = ['Nom', "Nom d'utilisateur", 'Rôle', 'Magasin', 'Statut'];

    public function index(Request $request): View
    {
        $utilisateurs = $this->requeteListe($request)->paginate(20)->withQueryString();

        return view('utilisateurs.index', ['utilisateurs' => $utilisateurs]);
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe('Utilisateurs', self::ENTETES_EXPORT, $this->lignesExport($request), 'utilisateurs.pdf');
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe('Utilisateurs', self::ENTETES_EXPORT, $this->lignesExport($request), 'utilisateurs.xlsx');
    }

    /**
     * Requête de la liste (filtres + tri), partagée par l'écran et ses exports :
     * un export porte toujours sur ce que l'écran affiche, sans la pagination.
     */
    private function requeteListe(Request $request): Builder
    {
        $query = User::query()
            ->with(['magasin', 'roles'])
            // Un Superadmin/développeur voit tout ; les autres (ex. un Gérant
            // habilité utilisateur.gerer) ne voient jamais les comptes
            // Superadmin ni le compte développeur — pas juste "non gérables
            // depuis cet écran" (déjà le cas via edit()/update()), carrément
            // invisibles dans la liste, donc dans ses exports aussi.
            ->when(! $request->user()->estGestionnaireAbonnement(), function ($q) {
                $q->whereDoesntHave('roles', fn ($r) => $r->where('name', 'Superadmin'))
                    ->when(config('abonnement.developpeur_username'), fn ($q2, $developpeur) => $q2->where('username', '!=', $developpeur));
            })
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $recherche = $request->string('recherche');
                $q->where(function ($sub) use ($recherche) {
                    $sub->where('name', 'like', "%{$recherche}%")
                        ->orWhere('username', 'like', "%{$recherche}%");
                });
            });

        return $this->appliquerTri($query, $request, ['name', 'username', 'actif', 'created_at']);
    }

    private function lignesExport(Request $request): Collection
    {
        return $this->requeteListe($request)->get()->map(fn (User $utilisateur) => [
            $utilisateur->name,
            $utilisateur->username,
            $utilisateur->roles->pluck('name')->implode(', ') ?: '—',
            $utilisateur->magasin->nom ?? '—',
            $utilisateur->actif ? 'Actif' : 'Inactif',
        ]);
    }

    public function create(): View
    {
        return view('utilisateurs.create', [
            'utilisateur' => null,
            'magasins' => Magasin::where('actif', true)->orderBy('nom')->get(),
            'roles' => Role::where('name', '!=', 'Superadmin')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request);
        $code = $this->genererCode();

        $utilisateur = User::create([
            'name' => $donnees['name'],
            'username' => $donnees['username'],
            'email' => $donnees['email'] ?? null,
            'telephone' => $donnees['telephone'] ?? null,
            'password' => $code,
            'magasin_id' => $donnees['magasin_id'] ?? null,
            'actif' => $donnees['actif'],
        ]);

        $utilisateur->assignRole($donnees['role']);

        return redirect()->route('utilisateurs.index')
            ->with('succes', "Utilisateur créé.")
            ->with('codeGenere', $code)
            ->with('utilisateurGenere', $utilisateur->username);
    }

    public function edit(User $utilisateur): View
    {
        // Un superadmin ne se gère pas depuis cet écran — sauf par lui-même :
        // il doit pouvoir changer son propre identifiant/code sans dépendre
        // d'un accès direct à la base (voir aussi update()/reinitialiserCode()).
        abort_if($utilisateur->hasRole('Superadmin') && $utilisateur->isNot(auth()->user()), 403, 'Le compte Superadmin ne se gère pas depuis cet écran.');

        return view('utilisateurs.edit', [
            'utilisateur' => $utilisateur,
            'magasins' => Magasin::where('actif', true)->orderBy('nom')->get(),
            'roles' => Role::where('name', '!=', 'Superadmin')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $utilisateur): RedirectResponse
    {
        $estSoiMemeSuperadmin = $utilisateur->hasRole('Superadmin') && $utilisateur->is(auth()->user());
        abort_if($utilisateur->hasRole('Superadmin') && ! $estSoiMemeSuperadmin, 403);

        $donnees = $this->valider($request, $utilisateur, ignorerRoleEtStatut: $estSoiMemeSuperadmin);

        $utilisateur->update([
            'name' => $donnees['name'],
            'username' => $donnees['username'],
            'email' => $donnees['email'] ?? null,
            'telephone' => $donnees['telephone'] ?? null,
            // Un superadmin qui modifie son propre compte garde son magasin/
            // statut actif tels quels : le formulaire ne les expose pas dans
            // ce cas (voir _form.blade.php), pas de risque de se désactiver
            // ou de se retirer un rattachement par erreur.
            'magasin_id' => $estSoiMemeSuperadmin ? $utilisateur->magasin_id : ($donnees['magasin_id'] ?? null),
            'actif' => $estSoiMemeSuperadmin ? $utilisateur->actif : $donnees['actif'],
        ]);

        // Le rôle Superadmin n'est jamais modifiable depuis ce formulaire (le
        // select ne le propose même pas) : on ne touche pas aux rôles ici.
        if (! $estSoiMemeSuperadmin) {
            $utilisateur->syncRoles([$donnees['role']]);
        }

        return redirect()->route('utilisateurs.index')->with('succes', 'Utilisateur mis à jour.');
    }

    public function reinitialiserCode(User $utilisateur): RedirectResponse
    {
        abort_if($utilisateur->hasRole('Superadmin') && $utilisateur->isNot(auth()->user()), 403);

        $code = $this->genererCode();
        $utilisateur->update(['password' => $code]);

        return redirect()->route('utilisateurs.index')
            ->with('succes', 'Code réinitialisé.')
            ->with('codeGenere', $code)
            ->with('utilisateurGenere', $utilisateur->username);
    }

    public function destroy(User $utilisateur): RedirectResponse
    {
        abort_if($utilisateur->hasRole('Superadmin'), 403);
        abort_if($utilisateur->id === auth()->id(), 403, 'Vous ne pouvez pas supprimer votre propre compte.');

        try {
            $utilisateur->delete();
        } catch (QueryException) {
            return redirect()->route('utilisateurs.index')->with('erreur', 'Cet utilisateur a des données associées (ventes, sessions…), désactivez-le plutôt.');
        }

        return redirect()->route('utilisateurs.index')->with('succes', 'Utilisateur supprimé.');
    }

    /**
     * $ignorerRoleEtStatut : un superadmin qui modifie son propre compte n'a
     * ni sélecteur de rôle ni sélecteur de magasin/statut dans le formulaire
     * (voir _form.blade.php) — inutile, et dangereux, d'exiger/valider ces
     * champs dans ce cas précis.
     */
    private function valider(Request $request, ?User $utilisateur = null, bool $ignorerRoleEtStatut = false): array
    {
        $donnees = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'unique:users,username,'.($utilisateur?->id)],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,'.($utilisateur?->id)],
            'telephone' => ['nullable', 'string', 'max:30'],
            'magasin_id' => ['nullable', 'exists:magasins,id'],
            'role' => [$ignorerRoleEtStatut ? 'sometimes' : 'required', 'nullable', 'exists:roles,name', 'not_in:Superadmin'],
            'actif' => ['boolean'],
        ]);

        $donnees['actif'] = $request->boolean('actif', true);

        return $donnees;
    }

    /**
     * Code de connexion à 4 chiffres (remplace le mot de passe pour un
     * utilisateur non technique) : jamais saisi, toujours généré et affiché
     * une seule fois à l'écran (voir store()/reinitialiserCode()) — plus
     * d'envoi par e-mail.
     */
    private function genererCode(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}
