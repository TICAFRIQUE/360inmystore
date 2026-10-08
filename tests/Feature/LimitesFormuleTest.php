<?php

namespace Tests\Feature;

use App\Models\Abonnement;
use App\Models\FormuleAbonnement;
use App\Models\Magasin;
use App\Models\User;
use App\Plateforme\LimitesFormule;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Limites d'une formule (magasins, caisses, utilisateurs, produits), réglées
 * par l'éditeur et appliquées dans l'espace de chaque entreprise.
 */
class LimitesFormuleTest extends TestCase
{
    use RefreshDatabase;

    private User $proprietaire;

    private FormuleAbonnement $catalogue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // Formule du catalogue de l'éditeur : 1 magasin, 1 caisse, 2 utilisateurs, 2 produits.
        $this->catalogue = FormuleAbonnement::create([
            'nom' => 'Essentielle', 'jours' => 30, 'illimite' => false, 'prix' => 15000, 'actif' => true,
            'max_magasins' => 1, 'max_caisses' => 1, 'max_utilisateurs' => 2, 'max_produits' => 2,
        ]);

        $this->proprietaire = User::create(['name' => 'Awa', 'username' => 'awa', 'email' => 'awa@example.test', 'password' => '1234', 'actif' => true]);
        $this->proprietaire->assignRole('Superadmin');
        $this->dansUnEspace($this->proprietaire, avecAbonnement: false);
    }

    /** L'entreprise souscrit la formule du catalogue, comme après un paiement. */
    private function souscrire(): void
    {
        Abonnement::activer(
            formule: FormuleAbonnement::copieLocale($this->catalogue->nom, $this->catalogue->jours, false, $this->catalogue->prix, $this->catalogue->limites()),
            montant: $this->catalogue->prix, jours: 30, illimite: false, note: 'Test', auteur: $this->proprietaire,
        );
    }

    private function magasin(string $nom, string $type = 'magasin')
    {
        return $this->actingAs($this->proprietaire)->post(route('magasins.store'), ['nom' => $nom, 'type' => $type, 'actif' => 1]);
    }

    public function test_un_magasin_de_plus_que_la_formule_est_refuse_mais_pas_un_depot(): void
    {
        $this->souscrire();

        $this->magasin('Magasin un')->assertSessionMissing('erreur');
        $this->assertSame(1, Magasin::magasins()->count());

        $this->magasin('Magasin deux')
            ->assertSessionHas('erreur', fn (string $message) => str_contains($message, 'permet 1 magasin au plus') && str_contains($message, 'Essentielle'));
        $this->assertSame(1, Magasin::magasins()->count());

        // Un dépôt n'est pas un point de vente : il ne compte pas dans la limite.
        $this->magasin('Dépôt central', 'depot')->assertSessionMissing('erreur');
        $this->assertSame(2, Magasin::count());
    }

    public function test_les_formulaires_de_creation_sont_refuses_des_la_limite_atteinte(): void
    {
        $this->souscrire();
        $dates = ['created_at' => now(), 'updated_at' => now()];
        DB::table('magasins')->insert(['id' => 1, 'nom' => 'Magasin', 'type' => 'magasin', 'actif' => true] + $dates);

        // Caisses : aucune encore, le formulaire s'ouvre.
        $this->actingAs($this->proprietaire)->get(route('caisses.create'))->assertOk();

        DB::table('caisses')->insert(['id' => 1, 'magasin_id' => 1, 'nom' => 'Caisse 1', 'actif' => true] + $dates);
        $this->actingAs($this->proprietaire)->get(route('caisses.create'))
            ->assertRedirect(route('caisses.index'))
            ->assertSessionHas('erreur', fn (string $message) => str_contains($message, 'permet 1 caisse au plus'));

        // Utilisateurs : le propriétaire compte pour un, il reste une place.
        $this->actingAs($this->proprietaire)->get(route('utilisateurs.create'))->assertOk();
        User::create(['name' => 'Koffi', 'username' => 'koffi', 'email' => 'koffi@example.test', 'password' => '1234', 'actif' => true]);
        $this->actingAs($this->proprietaire)->get(route('utilisateurs.create'))
            ->assertRedirect(route('utilisateurs.index'))
            ->assertSessionHas('erreur', fn (string $message) => str_contains($message, 'permet 2 utilisateurs au plus'));

        // Produits.
        DB::table('categories')->insert(['id' => 1, 'nom' => 'Divers', 'actif' => true] + $dates);
        foreach ([1, 2] as $id) {
            DB::table('produits')->insert(['id' => $id, 'sku' => 'PRD-'.$id, 'nom' => 'Produit '.$id, 'categorie_id' => 1, 'prix_piece' => 100, 'seuil_alerte' => 1, 'actif' => true] + $dates);
        }
        $this->actingAs($this->proprietaire)->get(route('produits.create'))
            ->assertRedirect(route('produits.index'))
            ->assertSessionHas('erreur', fn (string $message) => str_contains($message, 'permet 2 produits au plus'));
    }

    public function test_un_reglage_de_l_editeur_vaut_tout_de_suite_pour_ses_clients(): void
    {
        $this->souscrire();
        $this->magasin('Magasin un');
        $this->magasin('Magasin deux')->assertSessionHas('erreur');

        // L'éditeur passe la formule à 3 magasins dans son catalogue.
        $this->catalogue->update(['max_magasins' => 3]);

        $this->assertSame(3, LimitesFormule::limite('magasins'));
        $this->magasin('Magasin deux')->assertSessionMissing('erreur');
        $this->assertSame(2, Magasin::magasins()->count());

        // … puis retire toute limite.
        $this->catalogue->update(['max_magasins' => null]);
        $this->assertNull(LimitesFormule::refus('magasins', 50));
    }

    public function test_abaisser_une_limite_ne_retire_rien_de_ce_qui_existe(): void
    {
        $this->catalogue->update(['max_magasins' => 3]);
        $this->souscrire();
        $this->magasin('Magasin un');
        $this->magasin('Magasin deux');

        $this->catalogue->update(['max_magasins' => 1]);

        $this->assertSame(2, Magasin::magasins()->count());
        $this->magasin('Magasin trois')->assertSessionHas('erreur');
        // Les magasins existants restent modifiables.
        $this->actingAs($this->proprietaire)->get(route('magasins.edit', Magasin::first()))->assertOk();
    }

    public function test_sans_formule_il_n_y_a_aucune_limite(): void
    {
        // Durée libre accordée par l'éditeur : aucune formule rattachée.
        Abonnement::activer(formule: null, montant: 0, jours: null, illimite: true, note: 'Test', auteur: $this->proprietaire);

        $this->assertNull(LimitesFormule::formule());
        $this->magasin('Magasin un')->assertSessionMissing('erreur');
        $this->magasin('Magasin deux')->assertSessionMissing('erreur');
    }

    public function test_si_la_formule_a_disparu_du_catalogue_les_limites_de_la_souscription_font_foi(): void
    {
        // Souscrite à un ancien prix : la copie gardée dans l'espace est une
        // ligne distincte de celle du catalogue (dans ce test, les deux bases
        // n'en font qu'une).
        $copie = FormuleAbonnement::copieLocale('Essentielle', 30, false, 12000, $this->catalogue->limites());
        Abonnement::activer(formule: $copie, montant: 12000, jours: 30, illimite: false, note: 'Test', auteur: $this->proprietaire);

        // Tant que la formule est au catalogue, c'est son réglage qui vaut…
        $this->catalogue->update(['max_magasins' => 4]);
        $this->assertSame(4, LimitesFormule::limite('magasins'));

        // … une fois disparue, celui de la souscription.
        $this->catalogue->delete();

        $this->assertSame(1, LimitesFormule::limite('magasins'));
        $this->assertSame(2, LimitesFormule::limite('produits'));
    }

    public function test_les_limites_se_lisent_en_clair_sur_les_tarifs(): void
    {
        $this->assertSame(['1 magasin', '1 caisse', '2 utilisateurs', '2 produits'], $this->catalogue->limitesLisibles());
        $this->assertSame([], FormuleAbonnement::create(['nom' => 'Libre', 'jours' => 30, 'prix' => 1, 'actif' => true])->limitesLisibles());

        plateforme()->definir(\App\Plateforme\Plateforme::CENTRALE);
        auth()->logout();

        $this->get('/')->assertOk()->assertSee("Jusqu'à 1 magasin", false)->assertSee("Jusqu'à 2 utilisateurs", false);
    }

    public function test_l_editeur_regle_les_limites_d_une_formule(): void
    {
        plateforme()->definir(\App\Plateforme\Plateforme::CENTRALE);

        $this->actingAs($this->proprietaire)->put(route('plateforme.formules.update', $this->catalogue), [
            'nom' => 'Essentielle', 'jours' => 30, 'prix' => 15000,
            'max_magasins' => 2, 'max_caisses' => '', 'max_utilisateurs' => 5, 'max_produits' => 1000,
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            ['max_magasins' => 2, 'max_caisses' => null, 'max_utilisateurs' => 5, 'max_produits' => 1000],
            $this->catalogue->fresh()->limites(),
        );

        // Une limite à zéro n'a pas de sens : vide pour « sans limite ».
        $this->actingAs($this->proprietaire)->put(route('plateforme.formules.update', $this->catalogue), [
            'nom' => 'Essentielle', 'jours' => 30, 'prix' => 15000, 'max_magasins' => 0,
        ])->assertSessionHasErrors('max_magasins');
    }
}
