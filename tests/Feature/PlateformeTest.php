<?php

namespace Tests\Feature;

use App\Http\Controllers\AccesEspaceController;
use App\Models\AbonnementActivation;
use App\Models\FormuleAbonnement;
use App\Models\Plateforme\AccesEspace;
use App\Models\Plateforme\Entreprise;
use App\Models\Plateforme\PaiementAbonnement;
use App\Models\User;
use App\Plateforme\CreationEntreprise;
use App\Plateforme\LienAcces;
use App\Plateforme\Plateforme;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Mockery;
use Tests\TestCase;

/**
 * Plateforme SaaS : ce qui est ouvert ou fermé selon le contexte (domaine
 * principal, espace d'une entreprise), l'inscription et sa protection, le
 * choix de la formule, et l'espace de l'éditeur. La création réelle d'une
 * base de données n'est pas exercée ici (CreationEntreprise et les processus
 * par entreprise sont simulés) — voir PLATEFORME.md pour l'essai de bout en
 * bout.
 */
class PlateformeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['plateforme.domaine' => 'plateforme.test', 'app.url' => 'http://plateforme.test']);
    }

    private function contexte(string $etat, ?int $entrepriseId = null, ?string $sousDomaine = null): void
    {
        app(Plateforme::class)->definir($etat, $entrepriseId, $sousDomaine);
    }

    private function superadmin(string $username = 'admin'): User
    {
        $this->seed(RolePermissionSeeder::class);

        $utilisateur = User::create(['name' => 'Admin', 'username' => $username, 'password' => 'motdepasse', 'actif' => true]);
        $utilisateur->assignRole('Superadmin');

        return $utilisateur;
    }

    private function formule(array $attributs = []): FormuleAbonnement
    {
        return FormuleAbonnement::create($attributs + ['nom' => 'Mensuelle', 'jours' => 30, 'illimite' => false, 'prix' => 15000, 'actif' => true]);
    }

    /** Formulaire d'inscription valide, question anti-robot comprise. */
    private function formulaire(array $surcharges = []): array
    {
        $this->get(route('plateforme.inscription'))->assertOk();

        return $surcharges + [
            'nom' => 'Boutique Awa',
            'sous_domaine' => 'boutique-awa',
            'proprietaire_nom' => 'Awa Koné',
            'telephone' => '07 00 00 00 31',
            'telephone_pays' => 'CI',
            'username' => 'awa',
            'mot_de_passe' => 'motdepasse-awa',
            'mot_de_passe_confirmation' => 'motdepasse-awa',
            'reponse' => (string) session('inscription.anti_robot.reponse'),
        ];
    }

    // --- Domaine principal ---

    public function test_le_site_vitrine_affiche_les_formules_actives_du_catalogue(): void
    {
        $this->formule(['nom' => 'Essentielle', 'description' => 'Pour un seul magasin', 'avantages' => "Un magasin\nDeux caisses"]);
        $this->formule(['nom' => 'Retirée', 'actif' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Créer un compte')
            ->assertSee('Essentielle')->assertSee('Pour un seul magasin')->assertSee('Deux caisses')
            ->assertDontSee('Retirée');
    }

    public function test_sur_le_domaine_principal_les_ecrans_de_commerce_ramenent_au_tableau_de_bord(): void
    {
        $editeur = $this->superadmin();

        $this->actingAs($editeur)->get('/')->assertRedirect(route('plateforme.console'));
        $this->actingAs($editeur)->get(route('dashboard'))->assertRedirect(route('plateforme.console'));
        $this->actingAs($editeur)->get(route('produits.index'))->assertRedirect(route('plateforme.console'));
    }

    public function test_l_editeur_accede_a_ses_ecrans(): void
    {
        $editeur = $this->superadmin();

        foreach (['plateforme.console', 'plateforme.entreprises.index', 'plateforme.paiements.index', 'plateforme.formules.index'] as $route) {
            $this->actingAs($editeur)->get(route($route))->assertOk();
        }
    }

    public function test_l_editeur_gere_les_formules_de_la_page_des_tarifs(): void
    {
        $editeur = $this->superadmin();

        $this->actingAs($editeur)->post(route('plateforme.formules.store'), [
            'nom' => 'Annuelle', 'jours' => 365, 'prix' => 150000,
            'description' => 'Deux mois offerts', 'avantages' => "Magasins illimités\nSupport prioritaire", 'mise_en_avant' => '1',
        ])->assertSessionHasNoErrors();

        $formule = FormuleAbonnement::where('nom', 'Annuelle')->firstOrFail();
        $this->assertTrue($formule->mise_en_avant);
        $this->assertSame(['Magasins illimités', 'Support prioritaire'], $formule->listeAvantages());

        $this->actingAs($editeur)->patch(route('plateforme.formules.basculer', $formule));
        $this->assertFalse($formule->fresh()->actif);
    }

    public function test_l_editeur_enregistre_un_paiement_recu_en_direct_et_active_l_abonnement(): void
    {
        $editeur = $this->superadmin();
        $formule = $this->formule();
        $entreprise = Entreprise::create([
            'nom' => 'Boutique Awa', 'sous_domaine' => 'boutique-awa', 'base' => 'ims_boutique_awa', 'proprietaire_nom' => 'Awa',
            'telephone' => '+2250700000051', 'pays' => 'CI', 'statut' => Entreprise::ACTIVE,
        ]);

        // L'activation se fait dans la base de l'entreprise, par un processus séparé.
        Process::fake(['*' => Process::result(output: '{"date_fin":"2026-11-06","illimite":false}')]);

        $this->actingAs($editeur)->post(route('plateforme.entreprises.activer', $entreprise), [
            'formule_id' => $formule->id, 'montant' => 15000, 'moyen' => 'especes',
        ])->assertSessionHasNoErrors()->assertSessionHas('succes');

        $paiement = PaiementAbonnement::firstOrFail();
        $this->assertSame(['manuel', 'especes', 15000, PaiementAbonnement::REUSSI], [$paiement->passerelle, $paiement->moyen, $paiement->montant, $paiement->statut]);
        $this->assertSame('2026-11-06', $entreprise->fresh()->abonnement_fin->toDateString());
        $this->assertSame('Mensuelle', $entreprise->fresh()->formule_nom);
    }

    public function test_aucun_paiement_n_est_enregistre_si_l_activation_echoue(): void
    {
        $editeur = $this->superadmin();
        $entreprise = Entreprise::create([
            'nom' => 'Boutique Awa', 'sous_domaine' => 'boutique-awa', 'base' => 'ims_boutique_awa', 'proprietaire_nom' => 'Awa',
            'telephone' => '+2250700000051', 'pays' => 'CI', 'statut' => Entreprise::ACTIVE,
        ]);

        Process::fake(['*' => Process::result(errorOutput: 'base injoignable', exitCode: 1)]);

        $this->actingAs($editeur)->post(route('plateforme.entreprises.activer', $entreprise), [
            'jours' => 30, 'montant' => 15000, 'moyen' => 'mobile_money',
        ])->assertSessionHas('erreur');

        $this->assertSame(0, PaiementAbonnement::count());
    }

    public function test_l_editeur_suspend_et_reouvre_un_espace(): void
    {
        $editeur = $this->superadmin();
        $entreprise = Entreprise::create([
            'nom' => 'Boutique Awa', 'sous_domaine' => 'boutique-awa', 'base' => 'ims_boutique_awa', 'proprietaire_nom' => 'Awa',
            'telephone' => '+2250700000051', 'pays' => 'CI', 'statut' => Entreprise::ACTIVE,
        ]);

        $this->actingAs($editeur)->post(route('plateforme.entreprises.suspendre', $entreprise), ['motif' => 'Impayé']);
        $this->assertSame(Entreprise::SUSPENDUE, $entreprise->fresh()->statut);

        $this->actingAs($editeur)->post(route('plateforme.entreprises.reactiver', $entreprise));
        $this->assertSame(Entreprise::ACTIVE, $entreprise->fresh()->statut);
    }

    // --- Inscription ---

    public function test_inscription_valide_cree_l_espace_et_y_conduit_par_un_lien_a_usage_unique(): void
    {
        $donnees = $this->formulaire();

        $this->mock(CreationEntreprise::class)
            ->shouldReceive('creer')->once()
            ->with(Mockery::on(fn (array $d) => $d['sous_domaine'] === 'boutique-awa'
                && $d['telephone'] === '+2250700000031' && $d['pays'] === 'CI' && $d['username'] === 'awa'))
            ->andReturnUsing(fn () => Entreprise::create([
                'nom' => 'Boutique Awa', 'sous_domaine' => 'boutique-awa', 'base' => 'ims_boutique_awa', 'proprietaire_nom' => 'Awa Koné',
                'telephone' => '+2250700000031', 'pays' => 'CI', 'statut' => Entreprise::ACTIVE,
            ]));

        $this->travel(10)->seconds();

        $reponse = $this->post(route('plateforme.inscription.store'), $donnees);

        $this->assertStringStartsWith('http://boutique-awa.plateforme.test/acces/', $reponse->headers->get('Location'));
        $this->assertSame(AccesEspace::INSCRIPTION, AccesEspace::firstOrFail()->motif);
    }

    public function test_un_robot_qui_remplit_le_champ_piege_est_refuse(): void
    {
        $donnees = $this->formulaire(['site_web' => 'http://spam.example']);
        $this->mock(CreationEntreprise::class)->shouldNotReceive('creer');

        $this->travel(10)->seconds();

        $this->post(route('plateforme.inscription.store'), $donnees)->assertSessionHasErrors('reponse');
    }

    public function test_un_envoi_instantane_est_refuse(): void
    {
        $donnees = $this->formulaire();
        $this->mock(CreationEntreprise::class)->shouldNotReceive('creer');

        $this->post(route('plateforme.inscription.store'), $donnees)->assertSessionHasErrors('reponse');
    }

    public function test_la_question_ne_peut_pas_etre_rejouee(): void
    {
        $donnees = $this->formulaire(['reponse' => '999']);
        $this->mock(CreationEntreprise::class)->shouldNotReceive('creer');

        $this->travel(10)->seconds();
        $this->post(route('plateforme.inscription.store'), $donnees)->assertSessionHasErrors('reponse');

        // La question est consommée : il faut réafficher le formulaire.
        $this->assertNull(session('inscription.anti_robot'));
    }

    public function test_adresse_reservee_numero_invalide_et_doublons_sont_refuses(): void
    {
        Entreprise::create([
            'nom' => 'Déjà là', 'sous_domaine' => 'deja-la', 'base' => 'ims_deja_la', 'proprietaire_nom' => 'X',
            'telephone' => '+2250700000041', 'pays' => 'CI', 'statut' => Entreprise::ACTIVE,
        ]);
        $this->mock(CreationEntreprise::class)->shouldNotReceive('creer');

        foreach ([
            ['sous_domaine' => 'admin'],
            ['sous_domaine' => 'Mauvaise Adresse'],
            ['sous_domaine' => 'deja-la'],
            ['telephone' => '0700'],
            ['telephone' => '07 00 00 00 41'],
            ['mot_de_passe' => 'court', 'mot_de_passe_confirmation' => 'court'],
        ] as $cas) {
            $donnees = $this->formulaire($cas);
            $this->travel(10)->seconds();

            $this->post(route('plateforme.inscription.store'), $donnees)
                ->assertSessionHasErrors(array_key_first($cas));
        }
    }

    // --- Espace d'une entreprise ---

    public function test_un_espace_introuvable_ou_ferme_est_arrete_avant_tout(): void
    {
        $this->contexte(Plateforme::INTROUVABLE, null, 'nexiste-pas');
        $this->get('/login')->assertNotFound()->assertSee("Cet espace n'existe pas", false);

        $this->contexte(Plateforme::INDISPONIBLE, 1, 'suspendue');
        $this->get('/login')->assertForbidden()->assertSee('momentanément indisponible');
    }

    public function test_dans_un_espace_les_ecrans_de_la_plateforme_n_existent_pas(): void
    {
        $proprietaire = $this->superadmin();
        $this->dansUnEspace($proprietaire);

        $this->get('/inscription')->assertNotFound();
        $this->actingAs($proprietaire)->get('/plateforme')->assertNotFound();
        $this->actingAs($proprietaire)->get('/plateforme/entreprises')->assertNotFound();
    }

    public function test_le_proprietaire_d_un_espace_ne_s_active_pas_d_abonnement(): void
    {
        $proprietaire = $this->superadmin();
        $this->assertTrue($proprietaire->estGestionnaireAbonnement());

        $this->dansUnEspace($proprietaire);

        $this->assertFalse($proprietaire->estGestionnaireAbonnement());
        $this->actingAs($proprietaire)->get(route('abonnement.gestion'))->assertForbidden();
        $this->actingAs($proprietaire)->post(route('abonnement.activer'), ['illimite' => 1, 'montant' => 0])->assertForbidden();
    }

    public function test_restauration_et_dossier_de_sauvegarde_refuses_dans_un_espace(): void
    {
        $proprietaire = $this->superadmin();
        $this->dansUnEspace($proprietaire);

        $this->actingAs($proprietaire)->post(route('parametres.restaurer'))->assertForbidden();
        $this->actingAs($proprietaire)->put(route('parametres.dossier-sauvegarde'), ['dossier_sauvegarde' => 'C:/'])->assertForbidden();
    }

    public function test_sans_formule_l_espace_se_reduit_au_choix_de_la_formule(): void
    {
        $this->formule();
        $proprietaire = $this->superadmin();
        $this->dansUnEspace($proprietaire, avecAbonnement: false);

        $this->actingAs($proprietaire)->get(route('dashboard'))->assertRedirect(route('abonnement.mon'));
        $this->actingAs($proprietaire)->get(route('produits.index'))->assertRedirect(route('abonnement.mon'));
        $this->actingAs($proprietaire)->get(route('abonnement.mon'))
            ->assertOk()->assertSee('Choisissez votre formule')->assertSee('Mensuelle')->assertSee('Choisir et payer');
    }

    public function test_l_essai_gratuit_ouvre_l_espace_et_ne_se_prend_qu_une_fois(): void
    {
        $essai = $this->formule(['nom' => 'Essai', 'jours' => 14, 'prix' => 0]);
        $proprietaire = $this->superadmin();
        $entreprise = $this->dansUnEspace($proprietaire, avecAbonnement: false);

        $this->actingAs($proprietaire)->post(route('abonnement.essai', $essai->id))->assertRedirect(route('dashboard'));

        $this->actingAs($proprietaire)->get(route('dashboard'))->assertOk();
        $this->assertSame('Essai gratuit', AbonnementActivation::firstOrFail()->note);
        $this->assertSame('Essai', $entreprise->fresh()->formule_nom);
        $this->assertSame(today()->addDays(14)->toDateString(), $entreprise->fresh()->abonnement_fin->toDateString());

        // Une fois un abonnement pris, la formule gratuite n'est plus proposée ni acceptée.
        $this->actingAs($proprietaire)->get(route('abonnement.mon'))->assertDontSee("Commencer l'essai gratuit");
        $this->actingAs($proprietaire)->post(route('abonnement.essai', $essai->id))->assertSessionHas('erreur');
        $this->assertSame(1, AbonnementActivation::count());
    }

    public function test_un_paiement_simule_active_la_formule_et_s_inscrit_au_registre(): void
    {
        $formule = $this->formule();
        $proprietaire = $this->superadmin();
        $entreprise = $this->dansUnEspace($proprietaire, avecAbonnement: false);

        $this->actingAs($proprietaire)->post(route('abonnement.paiement.store', $formule->id), ['moyen' => 'mobile_money', 'resultat' => 'refuse'])
            ->assertSessionHas('erreur');
        $this->assertSame(0, AbonnementActivation::count());

        $this->actingAs($proprietaire)->post(route('abonnement.paiement.store', $formule->id), ['moyen' => 'mobile_money', 'resultat' => 'reussi'])
            ->assertRedirect(route('dashboard'));

        $this->assertSame([PaiementAbonnement::REFUSE, PaiementAbonnement::REUSSI], PaiementAbonnement::orderBy('id')->pluck('statut')->all());
        $this->assertSame('simulation', PaiementAbonnement::first()->passerelle);
        $this->assertSame(1, AbonnementActivation::count());
        $this->assertSame('Mensuelle', $entreprise->fresh()->formule_nom);
    }

    public function test_seul_le_proprietaire_choisit_la_formule(): void
    {
        $formule = $this->formule();
        $proprietaire = $this->superadmin();
        $this->dansUnEspace($proprietaire, avecAbonnement: false);

        $employe = User::create(['name' => 'Employé', 'username' => 'employe', 'password' => '1234', 'actif' => true]);
        $employe->assignRole('Caissier');

        $this->actingAs($employe)->get(route('abonnement.mon'))->assertOk()->assertDontSee('Choisir et payer');
        $this->actingAs($employe)->post(route('abonnement.paiement.store', $formule->id), ['moyen' => 'mobile_money'])->assertForbidden();
    }

    // --- Liens d'accès direct ---

    public function test_un_lien_d_acces_connecte_le_proprietaire_une_seule_fois(): void
    {
        $proprietaire = $this->superadmin();
        $entreprise = $this->dansUnEspace($proprietaire, avecAbonnement: false);

        $jeton = basename(app(LienAcces::class)->creer($entreprise, AccesEspace::INSCRIPTION));

        $this->get(route('acces', $jeton))->assertRedirect(route('abonnement.mon'));
        $this->assertAuthenticatedAs($proprietaire);

        auth()->logout();
        $this->get(route('acces', $jeton))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_un_lien_expire_ou_d_un_autre_espace_est_refuse(): void
    {
        $proprietaire = $this->superadmin();
        $entreprise = $this->dansUnEspace($proprietaire);
        $liens = app(LienAcces::class);

        $expire = basename($liens->creer($entreprise, AccesEspace::INSCRIPTION));
        $this->travel(10)->minutes();
        $this->get(route('acces', $expire))->assertRedirect(route('login'));

        $autre = basename($liens->creer($entreprise, AccesEspace::INSCRIPTION));
        $this->contexte(Plateforme::ENTREPRISE, $entreprise->id + 1, 'autre-espace');
        $this->get(route('acces', $autre))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_un_acces_de_maintenance_est_signale_et_trace(): void
    {
        $proprietaire = $this->superadmin();
        $entreprise = $this->dansUnEspace($proprietaire, avecAbonnement: false);

        $jeton = basename(app(LienAcces::class)->creer($entreprise, AccesEspace::MAINTENANCE, 'Alex'));

        // Même sans abonnement en cours, l'éditeur entre dans l'espace.
        $this->get(route('acces', $jeton))->assertRedirect(route('dashboard'))->assertSessionHas(AccesEspaceController::CLE_MAINTENANCE, 'Alex');
        $this->get(route('dashboard'))->assertOk()->assertSee('Accès de maintenance');

        $this->assertDatabaseHas('activity_log', ['description' => "Accès de maintenance de l'éditeur à cet espace."]);
    }
}
