<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un commerce qui démarre n'a ni magasin, ni caisse, ni produit : chaque
 * écran qui en dépend doit dire ce qui manque et y conduire, jamais
 * s'arrêter sur une page vide.
 */
class MiseEnRouteTest extends TestCase
{
    use RefreshDatabase;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();

        $this->proprietaire = $this->utilisateur('Superadmin');
        $this->dansUnEspace($this->proprietaire);
    }

    private function utilisateur(string $role, ?int $magasinId = null): User
    {
        $utilisateur = User::create([
            'name' => $role, 'username' => strtolower($role).uniqid(), 'email' => uniqid().'@example.test',
            'password' => '1234', 'actif' => true, 'magasin_id' => $magasinId,
        ]);
        $utilisateur->assignRole($role);

        return $utilisateur;
    }

    private function magasin(): void
    {
        DB::table('magasins')->insert(['id' => 1, 'nom' => 'Magasin principal', 'type' => 'magasin', 'actif' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_un_espace_vide_propose_de_creer_un_magasin(): void
    {
        $this->actingAs($this->proprietaire)->get('/dashboard')
            ->assertOk()
            ->assertSee('Mise en route')
            ->assertSee('0 sur 5')
            ->assertSee(route('magasins.create'), false)
            // Seule la prochaine étape porte un bouton.
            ->assertDontSee(route('caisses.create'), false);

        $this->actingAs($this->proprietaire)->get(route('sessions.index'))
            ->assertOk()
            ->assertSee('Aucun magasin pour l', false)
            ->assertSee(route('magasins.create'), false);

        $this->actingAs($this->proprietaire)->get(route('caisses.create'))
            ->assertOk()
            ->assertSee('Créez d', false)
            ->assertSee(route('magasins.create'), false);
    }

    public function test_avec_un_magasin_l_etape_suivante_est_la_caisse(): void
    {
        $this->magasin();

        $this->actingAs($this->proprietaire)->get('/dashboard')
            ->assertOk()
            ->assertSee('1 sur 5')
            ->assertSee(route('caisses.create'), false);

        $this->actingAs($this->proprietaire)->get(route('sessions.index'))
            ->assertOk()
            ->assertSee('Aucune caisse pour le moment')
            ->assertSee(route('caisses.create'), false)
            ->assertDontSee('Aucun magasin pour l', false);

        // Le formulaire de caisse n'a plus d'avertissement : un magasin existe.
        $this->actingAs($this->proprietaire)->get(route('caisses.create'))->assertOk()->assertDontSee('Créez d', false);
    }

    public function test_un_caissier_sans_caisse_est_renvoye_vers_son_responsable(): void
    {
        $this->magasin();

        $this->actingAs($this->utilisateur('Caissier', 1))->get(route('sessions.index'))
            ->assertOk()
            ->assertSee('Aucune caisse dans votre magasin')
            ->assertSee('Demandez à votre responsable')
            ->assertDontSee(route('caisses.create'), false);
    }

    public function test_sans_categorie_le_produit_passe_d_abord_par_la_categorie(): void
    {
        $this->magasin();
        DB::table('caisses')->insert(['id' => 1, 'magasin_id' => 1, 'nom' => 'Caisse 1', 'actif' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->proprietaire)->get('/dashboard')
            ->assertOk()
            ->assertSee('2 sur 5')
            ->assertSee(route('categories.create'), false);

        $this->actingAs($this->proprietaire)->get(route('produits.create'))
            ->assertOk()
            ->assertSee(route('categories.create'), false);

        DB::table('categories')->insert(['id' => 1, 'nom' => 'Divers', 'actif' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->proprietaire)->get('/dashboard')->assertSee(route('produits.create'), false);
        $this->actingAs($this->proprietaire)->get(route('produits.create'))->assertOk()->assertDontSee(route('categories.create'), false);
    }

    public function test_la_caisse_sans_catalogue_conduit_a_l_ajout_d_un_produit(): void
    {
        $this->magasin();
        $dates = ['created_at' => now(), 'updated_at' => now()];
        DB::table('caisses')->insert(['id' => 1, 'magasin_id' => 1, 'nom' => 'Caisse 1', 'actif' => true] + $dates);
        DB::table('session_caisses')->insert(['id' => 1, 'caisse_id' => 1, 'caissier_id' => $this->proprietaire->id, 'fond_de_caisse' => 0, 'date_ouverture' => now()] + $dates);

        $this->actingAs($this->proprietaire)->get('/sessions/1/vente')
            ->assertOk()
            ->assertSee('Aucun produit dans le catalogue')
            ->assertSee(route('produits.create'), false);
    }

    public function test_le_guide_disparait_apres_la_premiere_vente(): void
    {
        $this->magasin();
        $dates = ['created_at' => now(), 'updated_at' => now()];
        DB::table('caisses')->insert(['id' => 1, 'magasin_id' => 1, 'nom' => 'Caisse 1', 'actif' => true] + $dates);
        DB::table('session_caisses')->insert(['id' => 1, 'caisse_id' => 1, 'caissier_id' => $this->proprietaire->id, 'fond_de_caisse' => 0, 'date_ouverture' => now()] + $dates);
        DB::table('categories')->insert(['id' => 1, 'nom' => 'Divers', 'actif' => true] + $dates);
        DB::table('produits')->insert(['id' => 1, 'sku' => 'PRD-00001', 'nom' => 'Riz', 'categorie_id' => 1, 'prix_piece' => 4000, 'seuil_alerte' => 1, 'actif' => true] + $dates);
        DB::table('stocks')->insert(['produit_id' => 1, 'magasin_id' => 1, 'quantite' => 5, 'cout_moyen_pondere' => 3000] + $dates);

        $this->actingAs($this->proprietaire)->get('/dashboard')->assertSee('Mise en route')->assertSee('4 sur 5');

        DB::table('ventes')->insert(['id' => 1, 'numero' => 'M1-C1-000001', 'magasin_id' => 1, 'session_caisse_id' => 1, 'caissier_id' => $this->proprietaire->id,
            'sous_total' => 4000, 'total_net' => 4000, 'created_at' => now()]);

        $this->actingAs($this->proprietaire)->get('/dashboard')->assertOk()->assertDontSee('Mise en route');
    }
}
