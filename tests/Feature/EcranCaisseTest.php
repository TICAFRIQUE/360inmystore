<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Produit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EcranCaisseTest extends TestCase
{
    use RefreshDatabase;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();

        $this->proprietaire = User::create(['name' => 'Awa', 'username' => 'awa', 'email' => 'awa@example.test', 'password' => '1234', 'actif' => true]);
        $this->proprietaire->assignRole('Superadmin');
        $this->dansUnEspace($this->proprietaire);

        $dates = ['created_at' => now(), 'updated_at' => now()];
        DB::table('magasins')->insert(['id' => 1, 'nom' => 'Magasin principal', 'type' => 'magasin', 'actif' => true] + $dates);
        DB::table('caisses')->insert(['id' => 1, 'magasin_id' => 1, 'nom' => 'Caisse 1', 'actif' => true] + $dates);
        DB::table('session_caisses')->insert(['id' => 1, 'caisse_id' => 1, 'caissier_id' => $this->proprietaire->id, 'fond_de_caisse' => 10000, 'date_ouverture' => now()] + $dates);
        DB::table('categories')->insert(['id' => 1, 'nom' => 'Papeterie', 'actif' => true] + $dates);
        DB::table('produits')->insert(['id' => 1, 'sku' => 'PRD-00001', 'nom' => 'Cahier 200 pages', 'code_barre' => '6190000000001', 'categorie_id' => 1, 'prix_piece' => 1200, 'seuil_alerte' => 10, 'actif' => true] + $dates);
        DB::table('produits')->insert(['id' => 2, 'sku' => 'PRD-00002', 'nom' => 'Stylo bille', 'categorie_id' => 1, 'prix_piece' => 150, 'seuil_alerte' => 10, 'actif' => true] + $dates);
        DB::table('stocks')->insert(['produit_id' => 1, 'magasin_id' => 1, 'quantite' => 35, 'cout_moyen_pondere' => 800] + $dates);
    }

    public function test_l_ecran_de_caisse_presente_les_produits_en_vignettes_et_garde_ses_formulaires(): void
    {
        $this->actingAs($this->proprietaire)->get('/sessions/1/vente')
            ->assertOk()
            ->assertSee('id="produit-recherche"', false)
            ->assertSee('caisse__grille', false)
            ->assertSee('Cahier 200 pages')
            // Les champs envoyés au serveur restent ceux du formulaire de vente.
            ->assertSee("lignes['+index+'][magasin_source_id]", false)
            ->assertSee("paiements['+index+'][moyen_paiement_id]", false)
            ->assertSee('id="formVente"', false)
            ->assertSee('id="formAttente"', false);
    }

    public function test_le_devis_reprend_le_catalogue_en_vignettes_et_garde_son_formulaire(): void
    {
        DB::table('clients')->insert(['id' => 50, 'nom' => 'Entreprise Kouamé', 'code' => 'CLI-000050', 'actif' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->proprietaire)->get(route('devis.create'))
            ->assertOk()
            ->assertSee('id="produit-recherche"', false)
            ->assertSee('caisse__grille', false)
            ->assertSee('Lignes du devis')
            ->assertSee('Cahier 200 pages')
            // Plus de liste déroulante de produits ; les champs envoyés restent ceux du devis.
            ->assertDontSee('produit-picker', false)
            ->assertSee("lignes['+index+'][remise_valeur]", false)
            ->assertSee('id="formDevis"', false);
    }

    public function test_le_catalogue_de_vente_porte_categorie_et_code_barres(): void
    {
        $cahier = Produit::catalogueVente()->firstWhere('id', 1);

        $this->assertSame('Papeterie', $cahier['categorie']);
        $this->assertSame('6190000000001', $cahier['code_barre']);
        $this->assertNull($cahier['image']);
    }

    public function test_renommer_une_categorie_rafraichit_le_catalogue_de_vente(): void
    {
        Produit::catalogueVente();

        Categorie::find(1)->update(['nom' => 'Fournitures scolaires']);

        $this->assertSame('Fournitures scolaires', Produit::catalogueVente()->firstWhere('id', 1)['categorie']);
    }
}
