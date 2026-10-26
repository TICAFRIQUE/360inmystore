<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApresMiseAJourTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_permission_ajoutee_au_catalogue_est_creee_sans_toucher_aux_roles(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // Un commerce a retiré une permission au Gérant ; une nouvelle
        // permission arrive ensuite dans le catalogue avec la mise à jour.
        $gerant = Role::findByName('Gérant');
        $gerant->revokePermissionTo('stock.voir');
        config(['permissions.catalogue' => [...config('permissions.catalogue'), 'essai.nouvelle']]);

        $this->artisan('app:apres-mise-a-jour')->assertSuccessful();

        $this->assertTrue(Permission::where('name', 'essai.nouvelle')->exists());
        $this->assertFalse($gerant->fresh()->hasPermissionTo('stock.voir'), 'Le réglage du commerce est gardé.');
        $this->assertFalse($gerant->fresh()->hasPermissionTo('essai.nouvelle'), 'Une nouvelle permission n\'est donnée à personne d\'office.');
    }

    public function test_relancee_elle_ne_cree_rien_de_plus(): void
    {
        $this->artisan('app:apres-mise-a-jour')->assertSuccessful();
        $permissions = Permission::count();
        $roles = Role::count();

        $this->artisan('app:apres-mise-a-jour')->assertSuccessful();

        $this->assertSame($permissions, Permission::count());
        $this->assertSame($roles, Role::count());
        $this->assertSame(count(config('permissions.catalogue')), $permissions);
    }
}
