<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExporteListe;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RoleController extends Controller
{
    use ExporteListe;

    private const ENTETES_EXPORT = ['Nom', 'Permissions', 'Utilisateurs'];

    public function index(Request $request): View
    {
        $roles = $this->requeteListe($request)->get();

        return view('roles.index', ['roles' => $roles]);
    }

    public function pdf(Request $request): Response
    {
        return $this->pdfDepuisListe('Rôles', self::ENTETES_EXPORT, $this->lignesExport($request), 'roles.pdf');
    }

    public function excel(Request $request): StreamedResponse
    {
        return $this->excelDepuisListe('Rôles', self::ENTETES_EXPORT, $this->lignesExport($request), 'roles.xlsx');
    }

    /**
     * Requête de la liste, partagée par l'écran et ses exports.
     */
    private function requeteListe(Request $request): Builder
    {
        return Role::withCount(['users', 'permissions'])
            ->when(! $request->user()->hasRole('Superadmin'), fn ($query) => $query->where('name', '!=', 'Superadmin'))
            ->orderBy('name');
    }

    private function lignesExport(Request $request): Collection
    {
        return $this->requeteListe($request)->get()->map(fn (Role $role) => [
            $role->name,
            $role->name === 'Superadmin' ? 'Accès total' : $role->permissions_count,
            $role->users_count,
        ]);
    }

    public function create(Request $request): View
    {
        return view('roles.create', ['permissionsParModule' => $this->permissionsParModule($request->user())]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        $role = Role::create(['name' => $donnees['name'], 'guard_name' => 'web']);
        $permissions = $this->filtrerPermissionsAutorisees($donnees['permissions'] ?? [], $request->user());
        $role->syncPermissions($permissions);

        activity()
            ->causedBy($request->user())
            ->performedOn($role)
            ->withProperties(['permissions' => $permissions])
            ->log("Rôle « {$role->name} » créé");

        return redirect()->route('roles.index')->with('succes', 'Rôle créé.');
    }

    public function edit(Request $request, Role $role): View
    {
        abort_if($role->name === 'Superadmin', 403, 'Le rôle Superadmin ne se gère pas depuis cet écran.');

        return view('roles.edit', [
            'role' => $role,
            'permissionsParModule' => $this->permissionsParModule($request->user()),
            'permissionsActuelles' => $role->permissions->pluck('name')->all(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->name === 'Superadmin', 403);

        $donnees = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name,'.$role->id],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        // Ce que cet utilisateur ne peut pas voir/cocher sur cet écran (noyau
        // protégé + réservé Superadmin s'il ne l'est pas) n'est jamais
        // ajoutable/retirable depuis ce formulaire : on préserve tel quel ce
        // que le rôle possédait déjà parmi ces permissions-là.
        $protegeesConservees = $role->permissions->pluck('name')
            ->intersect($this->permissionsExclues($request->user()))
            ->all();

        $permissionsAvant = $role->permissions->pluck('name')->all();

        $role->update(['name' => $donnees['name']]);
        $permissionsApres = [
            ...$this->filtrerPermissionsAutorisees($donnees['permissions'] ?? [], $request->user()),
            ...$protegeesConservees,
        ];
        $role->syncPermissions($permissionsApres);

        activity()
            ->causedBy($request->user())
            ->performedOn($role)
            ->withProperties(['avant' => $permissionsAvant, 'apres' => $permissionsApres])
            ->log("Rôle « {$role->name} » modifié");

        return redirect()->route('roles.index')->with('succes', 'Rôle mis à jour.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->name === 'Superadmin', 403);

        if ($role->users()->exists()) {
            return redirect()->route('roles.index')->with('erreur', 'Ce rôle est attribué à des utilisateurs, il ne peut pas être supprimé.');
        }

        activity()
            ->causedBy($request->user())
            ->performedOn($role)
            ->withProperties(['nom' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()])
            ->log("Rôle « {$role->name} » supprimé");

        $role->delete();

        return redirect()->route('roles.index')->with('succes', 'Rôle supprimé.');
    }

    private function permissionsParModule(User $user): array
    {
        $exclues = $this->permissionsExclues($user);

        return collect(config('permissions.catalogue'))
            ->reject(fn (string $permission) => in_array($permission, $exclues, true))
            ->groupBy(fn (string $permission) => explode('.', $permission)[0])
            ->all();
    }

    private function filtrerPermissionsAutorisees(array $permissions, User $user): array
    {
        $autorisees = array_diff(config('permissions.catalogue'), $this->permissionsExclues($user));

        return array_values(array_intersect($permissions, $autorisees));
    }

    /**
     * Le noyau protégé (config('permissions.protected')) est exclu pour tout
     * le monde, y compris Superadmin. Le lot « réservé Superadmin »
     * (config('permissions.superadmin_only') — ex. role.gerer, utilisateur.gerer,
     * dangereux en délégation) n'est en plus exclu que si l'utilisateur courant
     * n'est pas Superadmin.
     */
    private function permissionsExclues(User $user): array
    {
        $exclues = config('permissions.protected');

        if (! $user->hasRole('Superadmin')) {
            $exclues = [...$exclues, ...config('permissions.superadmin_only')];
        }

        return $exclues;
    }
}
