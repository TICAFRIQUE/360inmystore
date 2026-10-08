<?php

namespace Tests\Feature;

use App\Models\CommandeAchat;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReapprovisionnementTest extends TestCase
{
    use RefreshDatabase;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->proprietaire = $this->utilisateur('Superadmin');

        $dates = ['created_at' => now(), 'updated_at' => now()];
        DB::table('magasins')->insert(['id' => 1, 'nom' => 'Magasin principal', 'type' => 'magasin', 'actif' => true] + $dates);
        DB::table('magasins')->insert(['id' => 2, 'nom' => 'Dépôt central', 'type' => 'depot', 'actif' => true] + $dates);
        DB::table('categories')->insert(['id' => 1, 'nom' => 'Divers', 'actif' => true] + $dates);
        DB::table('fournisseurs')->insert(['id' => 1, 'nom' => 'Grossiste', 'code' => 'FRN-000001', 'actif' => true] + $dates);

        // [id, nom, seuil, stock magasin, stock dépôt, coût moyen]
        foreach ([
            [1, 'Riz', 10, 3, 80, 3100],     // sous le seuil au magasin : il en faut 17
            [2, 'Huile', 5, 0, null, 0],     // rupture, jamais acheté : il en faut 10, prix inconnu
            [3, 'Sucre', 10, 50, 2, 600],    // bien pourvu au magasin, bas au dépôt : il en faut 18 au dépôt
            [4, 'Savon', 10, 40, null, 350], // rien à commander
        ] as [$id, $nom, $seuil, $magasin, $depot, $cout]) {
            DB::table('produits')->insert(['id' => $id, 'sku' => 'PRD-0000'.$id, 'nom' => $nom, 'categorie_id' => 1, 'prix_piece' => 1000, 'seuil_alerte' => $seuil, 'actif' => true] + $dates);
            DB::table('stocks')->insert(['produit_id' => $id, 'magasin_id' => 1, 'quantite' => $magasin, 'cout_moyen_pondere' => $cout] + $dates);
            if ($depot !== null) {
                DB::table('stocks')->insert(['produit_id' => $id, 'magasin_id' => 2, 'quantite' => $depot, 'cout_moyen_pondere' => $cout] + $dates);
            }
        }
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

    public function test_le_bon_de_commande_s_ouvre_prerempli_des_produits_sous_leur_seuil(): void
    {
        $reponse = $this->actingAs($this->proprietaire)->get(route('commande-achats.create', ['reappro' => 1]))->assertOk();

        $lignes = $reponse->viewData('lignesReappro')->keyBy(fn (array $l) => $l['produit_id'].'-'.$l['magasin_destination_id']);

        $this->assertCount(3, $lignes);
        // Quantité : de quoi remonter au double du seuil. Prix : dernier coût connu.
        $this->assertSame(['quantite' => '17', 'prix_achat' => '3100'], array_intersect_key($lignes['1-1'], ['quantite' => 1, 'prix_achat' => 1]));
        $this->assertSame(['quantite' => '10', 'prix_achat' => '0'], array_intersect_key($lignes['2-1'], ['quantite' => 1, 'prix_achat' => 1]));
        // Le sucre ne manque qu'au dépôt : c'est là qu'il est commandé.
        $this->assertSame('18', $lignes['3-2']['quantite']);
        $this->assertArrayNotHasKey('3-1', $lignes->all());
        $this->assertArrayNotHasKey('4-1', $lignes->all());

        $reponse->assertSee('3 produits sous leur seuil');
    }

    public function test_sans_la_demande_le_bon_de_commande_reste_vide(): void
    {
        $this->actingAs($this->proprietaire)->get(route('commande-achats.create'))
            ->assertOk()
            ->assertViewHas('lignesReappro', fn ($lignes) => $lignes->isEmpty())
            ->assertDontSee('sous leur seuil');
    }

    public function test_un_gerant_ne_reapprovisionne_que_son_magasin(): void
    {
        $this->actingAs($this->utilisateur('Gérant', 1))->get(route('commande-achats.create', ['reappro' => 1]))
            ->assertOk()
            ->assertViewHas('lignesReappro', fn ($lignes) => $lignes->count() === 2 && $lignes->every(fn (array $l) => $l['magasin_destination_id'] === '1'));
    }

    public function test_les_lignes_proposees_s_enregistrent_telles_quelles(): void
    {
        $lignes = $this->actingAs($this->proprietaire)->get(route('commande-achats.create', ['reappro' => 1]))->viewData('lignesReappro');

        $this->actingAs($this->proprietaire)->post(route('commande-achats.store'), [
            // Comme le formulaire : le numéro est toujours envoyé, vide pour une génération automatique.
            'numero' => '', 'fournisseur_id' => 1, 'date_commande' => now()->toDateString(), 'action' => 'brouillon', 'lignes' => $lignes->all(),
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('erreur');

        $commande = CommandeAchat::with('lignes')->sole();
        $this->assertCount(3, $commande->lignes);
        $this->assertEqualsWithDelta(17.0, (float) $commande->lignes->firstWhere('produit_id', 1)->quantite, 0.001);

        // Un brouillon ne mouvemente rien : le stock n'a pas bougé.
        $this->assertEqualsWithDelta(3.0, (float) DB::table('stocks')->where('produit_id', 1)->where('magasin_id', 1)->value('quantite'), 0.001);
    }

    public function test_le_tableau_de_bord_propose_de_commander(): void
    {
        $this->actingAs($this->proprietaire)->get('/dashboard')
            ->assertOk()
            ->assertSee(route('commande-achats.create', ['reappro' => 1]), false);
    }

    public function test_quand_rien_ne_manque_le_bon_de_commande_le_dit(): void
    {
        DB::table('stocks')->update(['quantite' => 500]);

        $this->actingAs($this->proprietaire)->get(route('commande-achats.create', ['reappro' => 1]))
            ->assertOk()
            ->assertSee('rien à réapprovisionner');
    }
}
