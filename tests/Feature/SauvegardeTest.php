<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SauvegardeBase;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

/**
 * Sauvegarde et restauration, avec les vrais outils MySQL, sur une base
 * jetable créée pour le test (jamais la base de test elle-même : la
 * restaurer en plein test la verrouillerait).
 */
class SauvegardeTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'ims_test_sauvegarde';

    private PDO $serveur;

    private SauvegardeBase $sauvegarde;

    private string $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sauvegarde = app(SauvegardeBase::class);

        // Connexion à part : créer une base valide d'office les écritures en
        // cours sur la connexion qui l'exécute, donc jamais celle du test.
        $c = $this->connexion();
        $this->serveur = new PDO("mysql:host={$c['host']};port={$c['port']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->serveur->exec('DROP DATABASE IF EXISTS `'.self::BASE.'`');
        $this->serveur->exec('CREATE DATABASE `'.self::BASE.'` CHARACTER SET utf8mb4');
        $this->serveur->exec('CREATE TABLE `'.self::BASE.'`.notes (id INT PRIMARY KEY, texte VARCHAR(50))');
        $this->serveur->exec('INSERT INTO `'.self::BASE."`.notes VALUES (1, 'état sauvegardé')");

        $this->dossier = $this->sauvegarde->dossier(self::BASE);
        File::deleteDirectory($this->dossier);
    }

    protected function tearDown(): void
    {
        $this->serveur->exec('DROP DATABASE IF EXISTS `'.self::BASE.'`');
        File::deleteDirectory($this->dossier);

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function connexion(): array
    {
        return ['database' => self::BASE] + config('database.connections.'.config('database.default'));
    }

    /** @return list<string> */
    private function notes(): array
    {
        return $this->serveur->query('SELECT texte FROM `'.self::BASE.'`.notes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    private function modifierLaBase(): void
    {
        $this->serveur->exec('UPDATE `'.self::BASE."`.notes SET texte = 'modifié après'");
        $this->serveur->exec('INSERT INTO `'.self::BASE."`.notes VALUES (2, 'ajouté après')");
    }

    public function test_une_sauvegarde_se_restaure_a_l_identique(): void
    {
        $fichier = $this->sauvegarde->sauvegarder($this->connexion(), $this->dossier);

        $this->assertFileExists($fichier);
        $this->assertMatchesRegularExpression('/auto-\d{4}-\d{2}-\d{2}-\d{6}\.sql$/', $fichier);
        $this->assertStringContainsString('état sauvegardé', file_get_contents($fichier));

        $this->modifierLaBase();
        $this->assertSame(['modifié après', 'ajouté après'], $this->notes());

        $this->sauvegarde->importer($this->connexion(), $fichier);

        $this->assertSame(['état sauvegardé'], $this->notes());
    }

    public function test_seules_les_dix_plus_recentes_sont_gardees_par_type(): void
    {
        File::ensureDirectoryExists($this->dossier);

        // 12 sauvegardes automatiques, une par jour, et une sauvegarde d'un autre type.
        foreach (range(1, 12) as $jour) {
            file_put_contents($this->dossier.'/auto-2026-09-'.str_pad($jour, 2, '0', STR_PAD_LEFT).'-233000.sql', '-- '.$jour);
        }
        file_put_contents($this->dossier.'/avant-restauration-2026-08-01-100000.sql', '--');
        file_put_contents($this->dossier.'/document-personnel.sql', 'à ne jamais toucher');

        // La treizième, bien réelle, déclenche la purge.
        $this->sauvegarde->sauvegarder($this->connexion(), $this->dossier);

        $restantes = $this->sauvegarde->lister($this->dossier);
        $automatiques = $restantes->where('type', 'auto')->pluck('nom');

        $this->assertCount(10, $automatiques);
        // Les plus anciennes sont parties, la plus récente est en tête.
        $this->assertFalse($automatiques->contains('auto-2026-09-01-233000.sql'));
        $this->assertFalse($automatiques->contains('auto-2026-09-03-233000.sql'));
        $this->assertTrue($automatiques->contains('auto-2026-09-04-233000.sql'));
        $this->assertStringStartsWith('auto-'.now()->format('Y-m-d'), $restantes->first()['nom']);

        // Les autres types ne sont pas touchés, ni un fichier qui n'est pas une sauvegarde.
        $this->assertCount(1, $restantes->where('type', 'avant-restauration'));
        $this->assertFileExists($this->dossier.'/document-personnel.sql');
        $this->assertNull($restantes->firstWhere('nom', 'document-personnel.sql'));
    }

    public function test_un_nom_de_fichier_quelconque_n_est_jamais_accepte(): void
    {
        File::ensureDirectoryExists($this->dossier);
        file_put_contents($this->dossier.'/auto-2026-09-01-233000.sql', '--');

        $this->assertNotNull($this->sauvegarde->trouver($this->dossier, 'auto-2026-09-01-233000.sql'));
        $this->assertNull($this->sauvegarde->trouver($this->dossier, '../auto-2026-09-01-233000.sql'));
        $this->assertNull($this->sauvegarde->trouver($this->dossier, '..\\..\\.env'));
        $this->assertNull($this->sauvegarde->trouver($this->dossier, 'auto-2026-09-02-233000.sql'));
    }

    public function test_la_commande_du_soir_sauvegarde_la_base_courante_dans_le_dossier_regle(): void
    {
        File::ensureDirectoryExists($this->dossier);
        DB::table('parametres')->updateOrInsert(['id' => 1], ['nom' => 'Test', 'dossier_sauvegarde' => $this->dossier]);

        $this->artisan('app:sauvegarder', ['--garder' => 3])->assertSuccessful();

        $fichiers = $this->sauvegarde->lister($this->dossier);
        $this->assertCount(1, $fichiers);
        $this->assertSame('auto', $fichiers->first()['type']);
        // C'est bien la base de l'application (ici, celle de test) qui est sauvegardée.
        $this->assertStringContainsString('CREATE TABLE `produits`', file_get_contents($fichiers->first()['chemin']));
    }

    /** Non-régression : la sauvegarde à la demande d'un commerce passe désormais par le même service. */
    public function test_la_sauvegarde_a_la_demande_d_un_commerce_se_telecharge_toujours(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $proprietaire = User::create(['name' => 'Awa', 'username' => 'awa', 'email' => 'a@example.test', 'password' => '1234', 'actif' => true]);
        $proprietaire->assignRole('Superadmin');

        $reponse = $this->actingAs($proprietaire)->post(route('parametres.backup'));

        $reponse->assertOk()->assertDownload();
        $this->assertStringContainsString('CREATE TABLE `produits`', file_get_contents($reponse->baseResponse->getFile()->getPathname()));
    }
}
