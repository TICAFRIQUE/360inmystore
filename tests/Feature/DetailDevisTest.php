<?php

namespace Tests\Feature;

use App\Enums\DevisStatut;
use App\Models\Devis;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Détail d'un devis : les actions proposées et l'alerte affichée dépendent
 * de son état, et la couleur dit le sens (voir devis/show.blade.php).
 */
class DetailDevisTest extends TestCase
{
    use RefreshDatabase;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $dates = ['created_at' => now(), 'updated_at' => now()];

        DB::table('magasins')->insert(['id' => 1, 'nom' => 'Magasin principal', 'type' => 'magasin', 'actif' => true] + $dates);
        DB::table('caisses')->insert(['id' => 1, 'magasin_id' => 1, 'nom' => 'Caisse 1', 'actif' => true] + $dates);
        DB::table('categories')->insert(['id' => 1, 'nom' => 'Divers', 'actif' => true] + $dates);
        DB::table('clients')->insert(['id' => 1, 'code' => 'CLI-000001', 'nom' => 'Client test', 'actif' => true] + $dates);
        DB::table('produits')->insert(['id' => 1, 'sku' => 'PRD-00001', 'nom' => 'Cahier', 'categorie_id' => 1, 'prix_piece' => 1000, 'seuil_alerte' => 0, 'actif' => true] + $dates);
        DB::table('stocks')->insert(['produit_id' => 1, 'magasin_id' => 1, 'quantite' => 50, 'cout_moyen_pondere' => 600] + $dates);

        $this->proprietaire = User::create(['name' => 'Awa', 'username' => 'awa', 'email' => 'awa@example.test', 'password' => '1234', 'actif' => true, 'magasin_id' => 1]);
        $this->proprietaire->assignRole('Superadmin');
        DB::table('session_caisses')->insert(['id' => 1, 'caisse_id' => 1, 'caissier_id' => $this->proprietaire->id, 'fond_de_caisse' => 0, 'date_ouverture' => now()] + $dates);
    }

    private function devis(string $statut = 'brouillon', int $jours = 20, int $quantite = 5): Devis
    {
        $id = DB::table('devis')->insertGetId([
            'numero' => 'D-'.uniqid(), 'client_id' => 1, 'created_by' => $this->proprietaire->id, 'statut' => $statut,
            'date_validite' => now()->addDays($jours)->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ligne_devis')->insert(['devis_id' => $id, 'produit_id' => 1, 'quantite' => $quantite, 'created_at' => now(), 'updated_at' => now()]);

        return Devis::findOrFail($id);
    }

    private function voir(Devis $devis)
    {
        return $this->actingAs($this->proprietaire)->get(route('devis.show', $devis))->assertOk();
    }

    public function test_un_brouillon_propose_documents_modifier_refuser_et_transformer(): void
    {
        $devis = $this->devis();

        $this->voir($devis)
            ->assertSee('Document')->assertSee('Télécharger en PDF')->assertSee('Télécharger en Excel')
            ->assertSee(route('devis.edit', $devis))
            ->assertSee('Refuser / annuler le devis')
            ->assertSee(route('devis.transformer.form', [1, $devis]))
            ->assertSee('badge-doux-info', false)
            ->assertDontSee('class="alert', false);
    }

    public function test_un_devis_qui_expire_bientot_le_signale_en_information(): void
    {
        $this->voir($this->devis(jours: 2))->assertSee('alert alert-info', false)->assertSee('Plus que 2 jours de validité');
    }

    public function test_un_stock_insuffisant_alerte_en_ambre_sans_bloquer_la_transformation(): void
    {
        $devis = $this->devis(quantite: 80);

        // La transformation ouvre l'écran de vente, où la ligne se corrige :
        // le bouton reste donc actif, l'alerte dit quoi ajuster.
        $this->voir($devis)
            ->assertSee('alert alert-warning', false)->assertSee('Stock insuffisant')
            ->assertSee("cette ligne sur l'écran de vente avant d'encaisser", false)
            ->assertSee('badge badge-doux-warning', false)
            ->assertSee(route('devis.transformer.form', [1, $devis]))
            ->assertDontSee('disabled', false);
    }

    public function test_un_devis_expire_alerte_en_ambre_et_ne_se_transforme_plus(): void
    {
        $devis = $this->devis(jours: -3);

        $this->voir($devis)
            ->assertSee('alert alert-warning', false)->assertSee('Devis expiré')
            ->assertSee('badge-doux-warning', false)
            ->assertDontSee(route('devis.transformer.form', [1, $devis]));
    }

    public function test_un_devis_refuse_alerte_en_rouge_et_ne_propose_plus_que_le_document(): void
    {
        $devis = $this->devis('refuse');

        $this->voir($devis)
            ->assertSee('alert alert-danger', false)->assertSee('Devis refusé / annulé')
            ->assertSee('badge-doux-danger', false)
            ->assertSee('Document')
            ->assertDontSee(route('devis.edit', $devis))
            ->assertDontSee('Refuser / annuler le devis');
    }

    public function test_refuser_un_devis_puis_le_refuser_encore_ne_change_rien(): void
    {
        $devis = $this->devis();

        $this->actingAs($this->proprietaire)->post(route('devis.annuler', $devis))->assertRedirect(route('devis.show', $devis));
        $this->assertSame(DevisStatut::Refuse, $devis->fresh()->statut);
        $this->assertFalse($devis->fresh()->peutEtreAnnule());

        $this->actingAs($this->proprietaire)->post(route('devis.annuler', $devis))->assertSessionHas('erreur');
        $this->assertSame(DevisStatut::Refuse, $devis->fresh()->statut);
    }

    public function test_sans_caisse_ouverte_le_devis_propose_d_en_ouvrir_une(): void
    {
        DB::table('session_caisses')->update(['date_cloture' => now()]);

        $this->voir($this->devis())->assertSee('Ouvrir une caisse pour transformer')->assertSee(route('sessions.index'));
    }
}
