<?php

namespace Tests\Feature;

use App\Models\Abonnement;
use App\Models\Categorie;
use App\Models\FormuleAbonnement;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\Stock;
use App\Models\User;
use App\Services\ImportProduits;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportProduitsTest extends TestCase
{
    use RefreshDatabase;

    private const ENTETE = ['Nom', 'Libellé distinctif', 'Référence', 'Code-barres', 'Catégorie', 'Sous-catégorie', 'Unité de base', 'Prix de vente',
        "Seuil d'alerte", 'Unité de vente 1', 'Quantité par unité 1', 'Prix unité 1', 'Unité de vente 2', 'Quantité par unité 2', 'Prix unité 2', 'Stock initial', "Prix d'achat"];

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->proprietaire = User::create(['name' => 'Awa', 'username' => 'awa', 'email' => 'awa@example.test', 'password' => '1234', 'actif' => true]);
        $this->proprietaire->assignRole('Superadmin');
        $this->dansUnEspace($this->proprietaire);

        DB::table('magasins')->insert(['id' => 1, 'nom' => 'Magasin principal', 'type' => 'magasin', 'actif' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param  list<list<mixed>>  $lignes  première ligne = intitulés */
    private function classeur(array $lignes): UploadedFile
    {
        $classeur = new Spreadsheet;
        $classeur->getActiveSheet()->fromArray($lignes, null, 'A1');
        $chemin = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($classeur))->save($chemin);

        return new UploadedFile($chemin, 'produits.xlsx', null, null, true);
    }

    private function importer(UploadedFile $fichier, ?int $magasinId = 1)
    {
        return $this->actingAs($this->proprietaire)->post(route('produits.import.store'), ['fichier' => $fichier, 'magasin_id' => $magasinId]);
    }

    public function test_un_fichier_correct_cree_produits_categories_unites_et_stock(): void
    {
        $this->importer($this->classeur([
            self::ENTETE,
            ['Riz parfumé', 'Sac de 5 kg', '', '', 'Alimentation', 'Épicerie sèche', 'Sac', '4 500', 20, 'Carton', 4, 17000, 'Palette', 80, 320000, 60, 3200],
            ['Câble électrique', '', 'ELE-001', '6191234567890', 'Électricité', '', 'Mètre', 350, '', 'Rouleau', 100, 32000, '', '', '', '', ''],
            ['Savon', '', '', '', 'Entretien', '', '', 500, 24, '', '', '', '', '', '', 0, ''],
        ]))->assertRedirect(route('produits.index'))->assertSessionHas('succes', '3 produits importés.');

        $this->assertSame(3, Produit::count());

        $riz = Produit::with(['categorie.parent', 'uniteBase', 'uniteVentes.unite'])->firstWhere('nom', 'Riz parfumé');
        $this->assertSame('Sac de 5 kg', $riz->libelle_distinctif);
        $this->assertSame(4500, $riz->prix_piece);
        $this->assertSame(20, $riz->seuil_alerte);
        $this->assertSame('Épicerie sèche', $riz->categorie->nom);
        $this->assertSame('Alimentation', $riz->categorie->parent->nom);
        $this->assertSame('Sac', $riz->uniteBase->nom);
        $this->assertMatchesRegularExpression('/^PRD-\d{6}$/', $riz->sku);
        $this->assertSame([[4, 17000, 'Carton'], [80, 320000, 'Palette']], $riz->uniteVentes->sortBy('facteur')->map(fn ($u) => [$u->facteur, $u->prix, $u->unite->nom])->values()->all());

        // Le stock entre par un mouvement (règle 1), au prix d'achat indiqué.
        $stock = Stock::where('produit_id', $riz->id)->sole();
        $this->assertEqualsWithDelta(60.0, (float) $stock->quantite, 0.001);
        $this->assertSame(3200, $stock->cout_moyen_pondere);
        $this->assertEqualsWithDelta(60.0, (float) MouvementStock::where('produit_id', $riz->id)->sum('quantite'), 0.001);

        $cable = Produit::firstWhere('sku', 'ELE-001');
        $this->assertSame('6191234567890', $cable->code_barre);
        $this->assertSame(0, $cable->seuil_alerte);
        $this->assertNull($cable->categorie->parent_id);
        $this->assertSame(0, Stock::where('produit_id', $cable->id)->count());

        // Unité de base absente : la pièce.
        $this->assertSame('Pièce', Produit::with('uniteBase')->firstWhere('nom', 'Savon')->uniteBase->nom);

        // Le catalogue de vente (en cache) connaît les nouveaux produits.
        $this->assertCount(3, Produit::catalogueVente());
    }

    public function test_a_la_moindre_erreur_rien_n_est_importe_et_chaque_ligne_est_signalee(): void
    {
        $reponse = $this->importer($this->classeur([
            self::ENTETE,
            ['Produit correct', '', '', '', 'Divers', '', 'Pièce', 1000, 5, '', '', '', '', '', '', '', ''],
            ['', '', '', '', 'Divers', '', 'Pièce', 1000, '', '', '', '', '', '', '', '', ''],            // ligne 3 : pas de nom
            ['Sans catégorie', '', '', '', '', '', 'Pièce', 'douze', '', '', '', '', '', '', '', '', ''],  // ligne 4 : catégorie, prix
            ['Mauvaise unité', '', '', '', 'Divers', '', 'Pièce', 500, '', 'Carton', 1, '', '', '', '', '', ''], // ligne 5 : facteur 1, prix manquant
            ['Stock sans prix', '', '', '', 'Divers', '', 'Pièce', 500, '', '', '', '', '', '', '', 10, ''],     // ligne 6 : prix d'achat
        ]));

        $erreurs = $reponse->assertSessionHas('erreursImport')->getSession()->get('erreursImport');

        $this->assertSame(0, Produit::count());
        $this->assertSame(0, Categorie::count());
        $this->assertSame(0, MouvementStock::count());

        $texte = implode("\n", $erreurs);
        $this->assertStringContainsString('Ligne 3 : le nom est obligatoire.', $texte);
        $this->assertStringContainsString('Ligne 4 : la catégorie est obligatoire.', $texte);
        $this->assertStringContainsString('Ligne 4 : le prix de vente doit être un nombre entier', $texte);
        $this->assertStringContainsString("Ligne 5 : la quantité par unité 1 doit être un nombre entier d'au moins 2", $texte);
        $this->assertStringContainsString("Ligne 5 : le prix de l'unité 1 doit être un nombre entier", $texte);
        $this->assertStringContainsString("Ligne 6 : un stock initial demande un prix d'achat", $texte);
        $this->assertStringNotContainsString('Ligne 2', $texte);

        // L'écran affiche le compte rendu.
        $this->actingAs($this->proprietaire)->withSession(['erreursImport' => $erreurs])->get(route('produits.import'))
            ->assertOk()->assertSee("Rien n'a été importé", false)->assertSee('Ligne 3 : le nom est obligatoire.');
    }

    public function test_les_doublons_sont_refuses_avec_la_base_comme_dans_le_fichier(): void
    {
        $dates = ['created_at' => now(), 'updated_at' => now()];
        DB::table('categories')->insert(['id' => 1, 'nom' => 'Divers', 'actif' => true] + $dates);
        DB::table('produits')->insert(['id' => 1, 'sku' => 'REF-1', 'nom' => 'Stylo bille', 'libelle_distinctif' => 'Bleu', 'code_barre' => '111', 'categorie_id' => 1, 'prix_piece' => 150, 'seuil_alerte' => 0, 'actif' => true] + $dates);

        $erreurs = implode("\n", $this->importer($this->classeur([
            self::ENTETE,
            ['Cahier', '', 'ref-1', '', 'Divers', '', '', 600, '', '', '', '', '', '', '', '', ''],      // référence prise (casse ignorée)
            ['Gomme', '', '', '111', 'Divers', '', '', 100, '', '', '', '', '', '', '', '', ''],          // code-barres pris
            ['Stylo bille', 'Bleu', '', '', 'Divers', '', '', 150, '', '', '', '', '', '', '', '', ''],   // même nom et même libellé
            ['Stylo bille', '', '', '', 'Divers', '', '', 150, '', '', '', '', '', '', '', '', ''],       // même nom, sans libellé
            ['Règle', '', 'REF-9', '', 'Divers', '', '', 300, '', '', '', '', '', '', '', '', ''],
            ['Équerre', '', 'REF-9', '', 'Divers', '', '', 400, '', '', '', '', '', '', '', '', ''],      // référence en double dans le fichier
        ]))->getSession()->get('erreursImport'));

        $this->assertStringContainsString('Ligne 2 : la référence « ref-1 » est déjà utilisée.', $erreurs);
        $this->assertStringContainsString('Ligne 3 : le code-barres « 111 » est déjà utilisé.', $erreurs);
        $this->assertStringContainsString('Ligne 4 : le produit « Stylo bille — Bleu » existe déjà.', $erreurs);
        $this->assertStringContainsString('Ligne 5 : un produit « Stylo bille » existe déjà : ajoutez un libellé distinctif', $erreurs);
        $this->assertStringContainsString('Ligne 7 : la référence « REF-9 » est déjà utilisée.', $erreurs);
        $this->assertSame(1, Produit::count());

        // Même nom avec un autre libellé : accepté (règle 4, seule la référence identifie un produit).
        $this->importer($this->classeur([self::ENTETE, ['Stylo bille', 'Noir', '', '', 'Divers', '', '', 150, '', '', '', '', '', '', '', '', '']]))
            ->assertSessionHas('succes', '1 produit importé.');
        $this->assertSame(2, Produit::where('nom', 'Stylo bille')->count());
    }

    public function test_un_stock_initial_exige_de_choisir_le_magasin(): void
    {
        $fichier = fn () => $this->classeur([self::ENTETE, ['Riz', '', '', '', 'Divers', '', '', 4500, '', '', '', '', '', '', '', 10, 3000]]);

        $erreurs = $this->importer($fichier(), null)->getSession()->get('erreursImport');
        $this->assertStringContainsString('choisissez le magasin ou le dépôt qui le reçoit', $erreurs[0]);
        $this->assertSame(0, Produit::count());

        $this->importer($fichier(), 1)->assertSessionHas('succes');
        $this->assertEqualsWithDelta(10.0, (float) Stock::sole()->quantite, 0.001);
    }

    public function test_un_fichier_sans_les_colonnes_obligatoires_ou_vide_est_refuse(): void
    {
        $erreurs = $this->importer($this->classeur([['Désignation', 'Tarif'], ['Riz', 4500]]))->getSession()->get('erreursImport');
        $this->assertStringContainsString('« Nom », « Catégorie », « Prix de vente »', $erreurs[0]);

        $erreurs = $this->importer($this->classeur([self::ENTETE]))->getSession()->get('erreursImport');
        $this->assertStringContainsString('ne contient aucun produit', $erreurs[0]);

        $this->actingAs($this->proprietaire)->post(route('produits.import.store'), ['fichier' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('fichier');
    }

    public function test_un_csv_francais_et_des_colonnes_dans_le_desordre_sont_acceptes(): void
    {
        $chemin = tempnam(sys_get_temp_dir(), 'import').'.csv';
        file_put_contents($chemin, "\u{FEFF}prix de vente;CATÉGORIE;nom\r\n1200;Papeterie;Cahier 200 pages\r\n150;Papeterie;Crayon\r\n");

        $this->importer(new UploadedFile($chemin, 'produits.csv', null, null, true), null)->assertSessionHas('succes', '2 produits importés.');

        $this->assertSame(1200, Produit::firstWhere('nom', 'Cahier 200 pages')->prix_piece);
    }

    public function test_le_modele_a_telecharger_s_importe_tel_quel_une_fois_rempli_comme_l_exemple(): void
    {
        $reponse = $this->actingAs($this->proprietaire)->get(route('produits.import.modele'))->assertOk();
        $chemin = tempnam(sys_get_temp_dir(), 'modele').'.xlsx';
        file_put_contents($chemin, $reponse->streamedContent());

        $modele = IOFactory::load($chemin);
        $this->assertSame(['Produits', 'Exemple'], $modele->getSheetNames());
        $this->assertSame(array_values(ImportProduits::COLONNES), $modele->getSheet(0)->toArray()[0]);
        // La feuille à remplir ne contient que les intitulés.
        $this->assertCount(1, $modele->getSheet(0)->toArray());

        // Les lignes d'exemple, recopiées dans la feuille à remplir, sont valides.
        $this->importer($this->classeur($modele->getSheet(1)->toArray(null, true, false, false)))->assertSessionHas('succes', '3 produits importés.');
    }

    public function test_la_formule_de_l_entreprise_limite_aussi_l_import(): void
    {
        $formule = FormuleAbonnement::create(['nom' => 'Essentielle', 'jours' => 30, 'illimite' => false, 'prix' => 15000, 'actif' => true, 'max_produits' => 2]);
        Abonnement::activer(formule: $formule, montant: 15000, jours: 30, illimite: false, note: 'Test', auteur: $this->proprietaire, remplacer: true);

        $ligne = fn (string $nom) => [$nom, '', '', '', 'Divers', '', '', 100, '', '', '', '', '', '', '', '', ''];

        $this->importer($this->classeur([self::ENTETE, $ligne('Un'), $ligne('Deux'), $ligne('Trois')]))
            ->assertSessionHas('erreur', fn (string $message) => str_contains($message, 'permet 2 produits au plus'));
        $this->assertSame(0, Produit::count());

        $this->importer($this->classeur([self::ENTETE, $ligne('Un'), $ligne('Deux')]))->assertSessionHas('succes');
        $this->assertSame(2, Produit::count());
    }

    public function test_l_import_est_reserve_a_qui_peut_creer_un_produit(): void
    {
        $caissier = User::create(['name' => 'Koffi', 'username' => 'koffi', 'email' => 'k@example.test', 'password' => '1234', 'actif' => true, 'magasin_id' => 1]);
        $caissier->assignRole('Caissier');

        $this->actingAs($caissier)->get(route('produits.import'))->assertForbidden();
        $this->actingAs($caissier)->post(route('produits.import.store'), [])->assertForbidden();

        $this->actingAs($this->proprietaire)->get(route('produits.import'))->assertOk()->assertSee('Télécharger le modèle');
        $this->actingAs($this->proprietaire)->get(route('produits.index'))->assertOk()->assertSee(route('produits.import'), false);
    }
}
