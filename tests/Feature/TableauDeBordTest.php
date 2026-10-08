<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TableauDeBordTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $role, ?int $magasinId = null): User
    {
        $utilisateur = User::create([
            'name' => $role, 'username' => strtolower($role).uniqid(), 'email' => uniqid().'@example.test',
            'password' => '1234', 'actif' => true, 'magasin_id' => $magasinId,
        ]);
        $utilisateur->assignRole($role);

        return $utilisateur;
    }

    /**
     * Un magasin, une caisse, une session ouverte aujourd'hui par $caissier
     * et une vente de 12 000 payée en espèces — posés directement en base :
     * seul l'affichage est testé ici, pas le parcours de vente.
     */
    private function uneVente(User $caissier): void
    {
        $maintenant = now();
        $dates = ['created_at' => $maintenant, 'updated_at' => $maintenant];

        DB::table('caisses')->insert(['id' => 1, 'magasin_id' => 1, 'nom' => 'Caisse 1', 'actif' => true] + $dates);
        DB::table('session_caisses')->insert(['id' => 1, 'caisse_id' => 1, 'caissier_id' => $caissier->id, 'fond_de_caisse' => 10000, 'date_ouverture' => $maintenant] + $dates);
        DB::table('categories')->insert(['id' => 1, 'nom' => 'Divers', 'actif' => true] + $dates);
        DB::table('produits')->insert(['id' => 1, 'sku' => 'PRD-00001', 'nom' => 'Riz 5 kg', 'categorie_id' => 1, 'prix_piece' => 4000, 'seuil_alerte' => 10, 'actif' => true] + $dates);
        DB::table('stocks')->insert(['produit_id' => 1, 'magasin_id' => 1, 'quantite' => 3, 'cout_moyen_pondere' => 3000] + $dates);
        DB::table('moyen_paiements')->delete();
        DB::table('moyen_paiements')->insert(['id' => 1, 'nom' => 'Espèces', 'est_espece' => true, 'actif' => true] + $dates);
        DB::table('ventes')->insert(['id' => 1, 'numero' => 'M1-C1-000001', 'magasin_id' => 1, 'session_caisse_id' => 1, 'caissier_id' => $caissier->id,
            'sous_total' => 12000, 'total_net' => 12000, 'created_at' => $maintenant]);
        DB::table('ligne_ventes')->insert(['vente_id' => 1, 'produit_id' => 1, 'quantite' => 3, 'quantite_pieces' => 3, 'prix_unitaire_applique' => 4000,
            'cout_applique' => 3000, 'sous_total_ligne' => 12000, 'total_ligne' => 12000, 'created_at' => $maintenant]);
        DB::table('paiements')->insert(['vente_id' => 1, 'moyen_paiement_id' => 1, 'montant' => 12000, 'created_at' => $maintenant]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        DB::table('magasins')->insert(['id' => 1, 'nom' => 'Magasin principal', 'type' => 'magasin', 'actif' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('magasins')->insert(['id' => 2, 'nom' => 'Dépôt central', 'type' => 'depot', 'actif' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_sans_aucune_donnee_le_tableau_de_bord_affiche_ses_etats_vides(): void
    {
        $proprietaire = $this->utilisateur('Superadmin');
        $this->dansUnEspace($proprietaire);

        $this->actingAs($proprietaire)->get('/dashboard')
            ->assertOk()
            ->assertSee('Aperçu financier')
            ->assertSee('Aucune vente sur cette période')
            ->assertSee('Aucune caisse ouverte pour le moment')
            // La courbe n'est pas dessinée tant qu'il n'y a rien à montrer.
            ->assertDontSee('id="chart-evolution"', false);
    }

    public function test_le_tableau_de_bord_du_proprietaire_reprend_les_ventes(): void
    {
        $proprietaire = $this->utilisateur('Superadmin');
        $this->dansUnEspace($proprietaire);
        $this->uneVente($this->utilisateur('Caissier', 1));

        $this->actingAs($proprietaire)->get('/dashboard')
            ->assertOk()
            ->assertSee('Tous les magasins')
            ->assertSee('1 vente aujourd', false)
            ->assertSee('12 000')
            ->assertSee('id="chart-evolution"', false)
            ->assertSee('Riz 5 kg')
            ->assertSee('Stock à réapprovisionner')
            // Un dépôt ne vend jamais : absent du comparatif des ventes.
            ->assertSee('Ventes par magasin')
            ->assertDontSee('Dépôt central');
    }

    public function test_le_gerant_voit_son_magasin(): void
    {
        $this->dansUnEspace($this->utilisateur('Superadmin'));
        $this->uneVente($this->utilisateur('Caissier', 1));

        $this->actingAs($this->utilisateur('Gérant', 1))->get('/dashboard')
            ->assertOk()
            ->assertSee('Magasin principal')
            ->assertSee('Meilleures ventes')
            ->assertDontSee('Ventes par magasin');
    }

    public function test_le_caissier_voit_sa_session_ou_l_invitation_a_en_ouvrir_une(): void
    {
        $this->dansUnEspace($this->utilisateur('Superadmin'));
        $caissier = $this->utilisateur('Caissier', 1);

        $this->actingAs($caissier)->get('/dashboard')->assertOk()->assertSee('Aucune session ouverte');

        $this->uneVente($caissier);

        $this->actingAs($caissier)->get('/dashboard')
            ->assertOk()
            ->assertSee('Session ouverte')
            ->assertSee('Total en caisse')
            ->assertSee('Espèces');
    }

    public function test_la_periode_d_analyse_filtre_la_courbe_et_l_apercu_financier(): void
    {
        $proprietaire = $this->utilisateur('Superadmin');
        $this->dansUnEspace($proprietaire);
        $this->uneVente($this->utilisateur('Caissier', 1));

        // Une seconde vente, de 30 000, le mois dernier.
        $moisDernier = now()->startOfMonth()->subDays(10);
        DB::table('ventes')->insert(['id' => 2, 'numero' => 'M1-C1-000002', 'magasin_id' => 1, 'session_caisse_id' => 1, 'caissier_id' => $proprietaire->id,
            'sous_total' => 30000, 'total_net' => 30000, 'created_at' => $moisDernier]);

        // Par défaut : ce mois — la vente du jour (12 000), pas celle du mois dernier.
        $this->actingAs($proprietaire)->get('/dashboard')
            ->assertOk()
            ->assertViewHas('periode', fn (array $p) => $p['cle'] === 'mois')
            ->assertViewHas('caPeriode', 12000);

        $this->actingAs($proprietaire)->get('/dashboard?periode=mois_dernier')
            ->assertOk()
            ->assertSee('Mois dernier')
            ->assertViewHas('caPeriode', 30000)
            ->assertViewHas('evolutionVentes', fn (array $e) => $e['unite'] === 'jour' && array_sum($e['valeurs']) === 30000);

        // Aujourd'hui : la courbe passe à l'heure.
        $this->actingAs($proprietaire)->get('/dashboard?periode=jour')
            ->assertOk()
            ->assertViewHas('caPeriode', 12000)
            ->assertViewHas('evolutionVentes', fn (array $e) => $e['unite'] === 'heure' && array_sum($e['valeurs']) === 12000);

        // 30 jours : selon la date, la vente du mois dernier y tombe ou non — le total suit la période.
        $attendu = $moisDernier->gte(today()->subDays(29)) ? 42000 : 12000;
        $this->actingAs($proprietaire)->get('/dashboard?periode=30j')->assertOk()->assertViewHas('caPeriode', $attendu);

        // Une valeur inconnue retombe sur « ce mois ».
        $this->actingAs($proprietaire)->get('/dashboard?periode=nimporte')
            ->assertOk()
            ->assertViewHas('periode', fn (array $p) => $p['cle'] === 'mois');

        // Les chiffres du haut, eux, ne dépendent pas de la période choisie.
        $this->actingAs($proprietaire)->get('/dashboard?periode=mois_dernier')->assertViewHas('caMois', 12000)->assertViewHas('caJour', 12000);
    }

    public function test_la_page_de_connexion_presente_les_ecrans_a_cote_du_formulaire(): void
    {
        $this->dansUnEspace($this->utilisateur('Superadmin'));

        $this->get('/login')
            ->assertOk()
            ->assertSee('Se connecter')
            ->assertSee('ecran-vente')
            ->assertSee('ecran-rapports')
            ->assertSee('name="username"', false);
    }

    public function test_la_page_de_connexion_donne_les_coordonnees_du_support_pour_un_acces_perdu(): void
    {
        $this->dansUnEspace($this->utilisateur('Superadmin'));

        // Sans coordonnées saisies : le message reste utile, sans liste vide.
        $this->get('/login')->assertOk()->assertSee('Mot de passe oublié')->assertDontSee('login-layout__contacts', false);

        // Saisies par l'éditeur, sur la plateforme.
        \App\Models\ConfigurationAbonnement::on('plateforme')->firstOrCreate(['id' => 1])
            ->update(['telephone' => '+2250700000001', 'whatsapp' => '+2250700000002', 'email' => 'support@exemple.test']);

        $this->get('/login')
            ->assertOk()
            ->assertSee('+225 07 00 00 00 01')
            ->assertSee('wa.me/2250700000002', false)
            ->assertSee('mailto:support@exemple.test', false);
    }
}
