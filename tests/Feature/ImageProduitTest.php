<?php

namespace Tests\Feature;

use App\Models\Produit;
use App\Models\User;
use App\Support\ImageProduit;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageProduitTest extends TestCase
{
    use RefreshDatabase;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();

        if (! ImageProduit::traitementDisponible()) {
            $this->markTestSkipped('Ni GD ni Imagick : aucun traitement d\'image sur ce poste.');
        }

        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);

        $this->proprietaire = User::create(['name' => 'Awa', 'username' => 'awa', 'email' => 'awa@example.test', 'password' => '1234', 'actif' => true]);
        $this->proprietaire->assignRole('Superadmin');

        $dates = ['created_at' => now(), 'updated_at' => now()];
        DB::table('categories')->insert(['id' => 1, 'nom' => 'Divers', 'actif' => true] + $dates);
        DB::table('unites')->insert(['id' => 1, 'nom' => 'Pièce', 'abbreviation' => 'pc', 'actif' => true] + $dates);
    }

    /**
     * Photo « de téléphone » : 2400 × 1800 pixels de bruit, incompressible —
     * environ 3,5 Mo en JPEG : bien au-delà de la limite d'enregistrement
     * (1 Mo), et nettement sous la limite d'envoi (8 Mo). Le poids exact
     * dépend de la bibliothèque JPEG du poste : une photo plus grande
     * (3000 × 2250, 7,3 Mo ici) dépassait 8 Mo sur l'intégration continue et
     * se faisait refuser par la validation, avant même d'être réduite.
     */
    private function photoLourde(): UploadedFile
    {
        $image = imagecreatetruecolor(2400, 1800);
        for ($y = 0; $y < 1800; $y += 3) {
            for ($x = 0; $x < 2400; $x += 3) {
                imagefilledrectangle($image, $x, $y, $x + 2, $y + 2, imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
            }
        }
        $chemin = tempnam(sys_get_temp_dir(), 'photo').'.jpg';
        imagejpeg($image, $chemin, 90);
        imagedestroy($image);

        return new UploadedFile($chemin, 'Photo du Produit.jpg', 'image/jpeg', null, true);
    }

    private function donnees(array $surcharge = []): array
    {
        return $surcharge + [
            'sku' => '', 'nom' => 'Riz parfumé', 'categorie_id' => 1, 'categorie_parent_id' => 1,
            'prix_piece' => 4500, 'unite_base_id' => 1, 'seuil_alerte' => 10, 'actif' => 1,
        ];
    }

    public function test_une_photo_lourde_est_reduite_sous_1_mo_et_recoit_une_miniature(): void
    {
        $photo = $this->photoLourde();
        $this->assertGreaterThan(ImageProduit::POIDS_MAX, $photo->getSize(), 'La photo de test doit dépasser 1 Mo.');
        $this->assertLessThan(ImageProduit::ENVOI_MAX_KO * 1024, $photo->getSize(), 'La photo de test doit rester sous la limite d\'envoi, sinon la validation la refuse avant toute réduction.');

        $this->actingAs($this->proprietaire)
            ->post(route('produits.store'), $this->donnees(['image' => $photo]))
            ->assertSessionHasNoErrors();

        $media = Produit::firstWhere('nom', 'Riz parfumé')->getFirstMedia('image');

        $this->assertNotNull($media);
        $this->assertLessThanOrEqual(ImageProduit::POIDS_MAX, $media->size);
        $this->assertSame('photo-du-produit.jpg', $media->file_name);
        [$largeur, $hauteur] = getimagesize($media->getPath());
        $this->assertLessThanOrEqual(1600, max($largeur, $hauteur));

        // Miniature produite tout de suite, et c'est elle que sert le catalogue de vente.
        $this->assertTrue($media->hasGeneratedConversion('vignette'));
        [$largeurVignette, $hauteurVignette] = getimagesize($media->getPath('vignette'));
        $this->assertSame([320, 240], [$largeurVignette, $hauteurVignette]);
        $this->assertStringContainsString('vignette', Produit::catalogueVente()->firstWhere('nom', 'Riz parfumé')['image']);
    }

    public function test_un_fichier_qui_n_est_pas_une_image_est_refuse(): void
    {
        $this->actingAs($this->proprietaire)
            ->post(route('produits.store'), $this->donnees(['image' => UploadedFile::fake()->create('document.pdf', 200, 'application/pdf')]))
            ->assertSessionHasErrors('image');

        $this->assertSame(0, Produit::count());
    }

    public function test_un_produit_sans_image_s_enregistre_toujours(): void
    {
        $this->actingAs($this->proprietaire)
            ->post(route('produits.store'), $this->donnees())
            ->assertSessionHasNoErrors();

        $this->assertNull(Produit::firstWhere('nom', 'Riz parfumé')->getFirstMedia('image'));
        $this->assertNull(Produit::catalogueVente()->firstWhere('nom', 'Riz parfumé')['image']);
    }
}
