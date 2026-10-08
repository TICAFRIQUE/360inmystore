<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\Stock;
use App\Models\UniteVente;
use App\Models\User;
use Database\Seeders\CatalogueTestSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogueTestSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $proprietaire = User::create(['name' => 'Awa', 'username' => 'awa', 'email' => 'awa@example.test', 'password' => '1234', 'actif' => true]);
        $proprietaire->assignRole('Superadmin');
        $this->dansUnEspace($proprietaire);

        $dates = ['created_at' => now(), 'updated_at' => now()];
        DB::table('magasins')->insert(['id' => 1, 'nom' => 'Magasin principal', 'type' => 'magasin', 'actif' => true] + $dates);
        DB::table('magasins')->insert(['id' => 2, 'nom' => 'Dépôt central', 'type' => 'depot', 'actif' => true] + $dates);
    }

    public function test_le_catalogue_de_test_cree_120_produits_avec_categories_et_unites_de_vente(): void
    {
        $this->seed(CatalogueTestSeeder::class);

        $this->assertSame(120, Produit::count());
        $this->assertSame(120, Produit::distinct()->count('sku'));
        $this->assertSame(120, Produit::distinct()->count('code_barre'));

        // 10 familles, 3 catégories chacune, 4 produits par catégorie.
        $this->assertSame(10, Categorie::whereNull('parent_id')->count());
        $this->assertSame(30, Categorie::whereNotNull('parent_id')->count());
        $this->assertSame(30, Categorie::whereNotNull('parent_id')->has('produits', '=', 4)->count());

        // Des unités de vente sur la grande majorité des produits, parfois plusieurs.
        $this->assertGreaterThanOrEqual(85, Produit::has('uniteVentes')->count());
        $this->assertGreaterThanOrEqual(10, Produit::has('uniteVentes', '>=', 2)->count());
        $this->assertSame(0, UniteVente::where('facteur', '<', 2)->count());

        // Plusieurs unités de base, pas seulement la pièce.
        $this->assertGreaterThanOrEqual(5, Produit::distinct()->count('unite_base_id'));

        // Deux produits peuvent porter le même nom (règle 4) : le libellé distinctif les sépare.
        $this->assertSame(2, Produit::where('nom', 'Stylo bille')->count());
    }

    public function test_les_codes_barres_sont_des_ean_13_valides(): void
    {
        $this->seed(CatalogueTestSeeder::class);

        foreach (Produit::pluck('code_barre') as $code) {
            $this->assertMatchesRegularExpression('/^200\d{10}$/', $code);

            $somme = 0;
            foreach (str_split(substr($code, 0, 12)) as $position => $chiffre) {
                $somme += (int) $chiffre * ($position % 2 === 0 ? 1 : 3);
            }
            $this->assertSame((10 - $somme % 10) % 10, (int) $code[12], "Clé de contrôle de {$code}");
        }
    }

    public function test_le_stock_initial_passe_par_des_mouvements_et_couvre_ruptures_et_alertes(): void
    {
        $this->seed(CatalogueTestSeeder::class);

        // Règle 1 : la quantité de chaque stock est la somme de ses mouvements.
        $this->assertGreaterThan(0, Stock::count());
        foreach (Stock::all() as $stock) {
            $somme = (float) MouvementStock::where('produit_id', $stock->produit_id)->where('magasin_id', $stock->magasin_id)->sum('quantite');
            $this->assertEqualsWithDelta($somme, (float) $stock->quantite, 0.001);
            $this->assertGreaterThan(0, $stock->cout_moyen_pondere);
        }

        // Un produit sur onze en rupture (aucun stock nulle part), d'autres sous leur seuil.
        $this->assertSame(10, Produit::doesntHave('stocks')->count());
        $this->assertGreaterThanOrEqual(10, Stock::query()
            ->join('produits', 'produits.id', '=', 'stocks.produit_id')
            ->where('stocks.magasin_id', 1)
            ->whereColumn('stocks.quantite', '<=', 'produits.seuil_alerte')
            ->count());

        // Le dépôt reçoit le triple du magasin.
        $magasin = Stock::where('magasin_id', 1)->first();
        $depot = Stock::where('magasin_id', 2)->where('produit_id', $magasin->produit_id)->first();
        $this->assertEqualsWithDelta((float) $magasin->quantite * 3, (float) $depot->quantite, 0.001);
    }

    public function test_relancer_le_seeder_ne_duplique_rien(): void
    {
        $this->seed(CatalogueTestSeeder::class);
        $mouvements = MouvementStock::count();
        $unites = UniteVente::count();

        $this->seed(CatalogueTestSeeder::class);

        $this->assertSame(120, Produit::count());
        $this->assertSame($unites, UniteVente::count());
        $this->assertSame($mouvements, MouvementStock::count());
    }

    public function test_sans_magasin_le_catalogue_est_cree_sans_stock(): void
    {
        DB::table('magasins')->delete();

        $this->seed(CatalogueTestSeeder::class);

        $this->assertSame(120, Produit::count());
        $this->assertSame(0, MouvementStock::count());
    }
}
