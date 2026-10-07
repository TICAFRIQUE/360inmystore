<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelephoneFormulaireTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $utilisateur = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@example.test', 'password' => '1234', 'actif' => true,
        ]);
        $utilisateur->assignRole('Superadmin');

        return $utilisateur;
    }

    public function test_un_numero_valide_est_enregistre_au_format_international(): void
    {
        $this->actingAs($this->superadmin())
            ->post(route('clients.store'), ['nom' => 'Kouamé', 'code' => '', 'telephone' => '07 00 00 00 01', 'telephone_pays' => 'CI'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('clients.index'));

        $this->assertSame('+2250700000001', Client::where('nom', 'Kouamé')->value('telephone'));
    }

    public function test_le_pays_choisi_determine_l_indicatif(): void
    {
        $this->actingAs($this->superadmin())
            ->post(route('clients.store'), ['nom' => 'Diop', 'code' => '', 'telephone' => '77 123 45 67', 'telephone_pays' => 'SN'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('clients.index'));

        $this->assertSame('+221771234567', Client::where('nom', 'Diop')->value('telephone'));
    }

    public function test_un_numero_invalide_pour_le_pays_est_refuse_et_rien_n_est_enregistre(): void
    {
        $this->actingAs($this->superadmin())
            ->post(route('clients.store'), ['nom' => 'Refusé', 'code' => '', 'telephone' => '0700', 'telephone_pays' => 'CI'])
            ->assertSessionHasErrors('telephone');

        $this->assertDatabaseMissing('clients', ['nom' => 'Refusé']);
    }

    public function test_la_creation_rapide_renvoie_le_numero_formate(): void
    {
        $this->actingAs($this->superadmin())
            ->postJson(route('clients.rapide'), ['nom' => 'Rapide', 'telephone' => '0700000002', 'telephone_pays' => 'CI'])
            ->assertCreated()
            ->assertJsonPath('telephone', '+225 07 00 00 00 02');

        $this->actingAs(User::first())
            ->postJson(route('clients.rapide'), ['nom' => 'Rapide 2', 'telephone' => '123', 'telephone_pays' => 'CI'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('telephone');
    }

    public function test_le_telephone_reste_facultatif(): void
    {
        $this->actingAs($this->superadmin())
            ->post(route('clients.store'), ['nom' => 'Sans numéro', 'code' => '', 'telephone' => '', 'telephone_pays' => 'CI'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('clients.index'));

        $this->assertDatabaseHas('clients', ['nom' => 'Sans numéro', 'telephone' => null]);
    }
}
