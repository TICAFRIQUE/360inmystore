<?php

namespace Tests\Feature;

use App\Enums\EcritureCompteTresorerieType;
use App\Models\CompteTresorerie;
use App\Models\Depense;
use App\Models\EcritureCompteTresorerie;
use App\Models\MotifMouvement;
use App\Models\MouvementCaisse;
use App\Models\SessionCaisse;
use App\Models\User;
use App\Services\CaisseMouvementService;
use App\Services\CompteTresorerieService;
use App\Support\ImageProduit;
use Database\Seeders\DepenseTestSeeder;
use Database\Seeders\MotifMouvementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dépenses : une sortie d'argent faite sous un motif marqué « dépense » en
 * laisse une, rattachée au mouvement qui a réellement sorti l'argent.
 */
class DepenseTest extends TestCase
{
    use RefreshDatabase;

    private User $proprietaire;

    private User $gerant;

    private User $caissier;

    private SessionCaisse $session;

    private CompteTresorerie $caisseGenerale;

    private MotifMouvement $loyer;

    private MotifMouvement $retrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $dates = ['created_at' => now(), 'updated_at' => now()];
        DB::table('magasins')->insert(['id' => 1, 'nom' => 'Magasin principal', 'type' => 'magasin', 'actif' => true] + $dates);
        DB::table('magasins')->insert(['id' => 2, 'nom' => 'Magasin Riviera', 'type' => 'magasin', 'actif' => true] + $dates);
        DB::table('caisses')->insert(['id' => 1, 'magasin_id' => 1, 'nom' => 'Caisse 1', 'actif' => true] + $dates);

        $this->proprietaire = $this->utilisateur('Superadmin');
        $this->gerant = $this->utilisateur('Gérant', 1);
        $this->caissier = $this->utilisateur('Caissier', 1);

        // Session ouverte du caissier : 50 000 dans le tiroir (fond de caisse).
        DB::table('session_caisses')->insert(['id' => 1, 'caisse_id' => 1, 'caissier_id' => $this->caissier->id, 'fond_de_caisse' => 50000, 'date_ouverture' => now()] + $dates);
        $this->session = SessionCaisse::findOrFail(1);

        // Caisse Générale : 200 000.
        DB::table('compte_tresoreries')->delete();
        DB::table('compte_tresoreries')->insert(['id' => 1, 'nom' => 'Caisse générale', 'type' => 'caisse_generale', 'actif' => true] + $dates);
        $this->caisseGenerale = CompteTresorerie::findOrFail(1);
        app(CompteTresorerieService::class)->crediter($this->caisseGenerale, 200000, EcritureCompteTresorerieType::EntreeManuelle, $this->proprietaire, motif: 'Apport');

        $this->loyer = MotifMouvement::create(['nom' => 'Loyer', 'est_depense' => true, 'actif' => true]);
        $this->retrait = MotifMouvement::create(['nom' => 'Retrait du propriétaire', 'est_depense' => false, 'actif' => true]);
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

    private function sortieDeCaisse(User $utilisateur, string $motif, int $montant, array $plus = [])
    {
        return $this->actingAs($utilisateur)->post(route('sessions.mouvements.store', $this->session), ['type' => 'sortie', 'montant' => $montant, 'motif' => $motif] + $plus);
    }

    private function tiroir(): int
    {
        return app(CaisseMouvementService::class)->soldeTheorique($this->session->fresh());
    }

    private function coffre(): int
    {
        return app(CompteTresorerieService::class)->solde($this->caisseGenerale);
    }

    public function test_une_sortie_de_caisse_sous_un_motif_depense_enregistre_une_depense(): void
    {
        $this->sortieDeCaisse($this->gerant, 'Loyer', 15000, ['beneficiaire' => 'M. Konan'])
            ->assertRedirect(route('sessions.show', $this->session))
            ->assertSessionHas('succes', 'Dépense enregistrée.');

        $depense = Depense::sole();
        $this->assertSame(['Loyer', 15000, 'M. Konan', 1, 1, null], [$depense->motif, $depense->montant, $depense->beneficiaire, $depense->magasin_id, $depense->session_caisse_id, $depense->compte_tresorerie_id]);
        $this->assertSame($this->gerant->id, $depense->created_by);

        // L'argent n'est sorti qu'une fois : un seul mouvement de caisse, dont la dépense est la référence.
        $mouvement = MouvementCaisse::sole();
        $this->assertSame(['sortie', 15000, 'Loyer'], [$mouvement->type->value, $mouvement->montant, $mouvement->motif]);
        $this->assertTrue($mouvement->reference->is($depense));
        $this->assertSame(35000, $this->tiroir());
    }

    public function test_une_sortie_sous_un_motif_ordinaire_reste_une_simple_sortie(): void
    {
        $this->sortieDeCaisse($this->gerant, 'Retrait du propriétaire', 10000)->assertSessionHas('succes', 'Mouvement de caisse enregistré.');

        $this->assertSame(0, Depense::count());
        $this->assertNull(MouvementCaisse::sole()->reference_type);
        $this->assertSame(40000, $this->tiroir());

        // Une entrée ne passe jamais par les dépenses, même sous un motif « dépense ».
        $this->actingAs($this->gerant)->post(route('sessions.mouvements.store', $this->session), ['type' => 'entree', 'montant' => 5000, 'motif' => 'Loyer'])
            ->assertSessionHas('succes', 'Mouvement de caisse enregistré.');
        $this->assertSame(0, Depense::count());
        $this->assertSame(45000, $this->tiroir());
    }

    public function test_une_sortie_exige_un_motif_de_la_liste(): void
    {
        $this->sortieDeCaisse($this->gerant, 'Un texte tapé à la main', 5000)->assertSessionHas('erreur');

        $this->assertSame(0, MouvementCaisse::count());
        $this->assertSame(50000, $this->tiroir());
    }

    public function test_le_caissier_n_enregistre_une_depense_que_s_il_y_est_autorise(): void
    {
        // Sans la permission : le motif « dépense » ne lui est pas proposé, et l'envoi est refusé.
        $this->actingAs($this->caissier)->get(route('sessions.show', $this->session))
            ->assertOk()
            ->assertSee('Retrait du propriétaire')
            ->assertDontSee('Loyer (dépense)');
        $this->sortieDeCaisse($this->caissier, 'Loyer', 5000)->assertForbidden();
        $this->assertSame(0, Depense::count());

        // Une sortie ordinaire lui reste permise, comme avant.
        $this->sortieDeCaisse($this->caissier, 'Retrait du propriétaire', 2000)->assertSessionHas('succes');

        $this->caissier->givePermissionTo('depense.creer');

        $this->actingAs($this->caissier)->get(route('sessions.show', $this->session))->assertSee('Loyer (dépense)');
        $this->sortieDeCaisse($this->caissier, 'Loyer', 5000)->assertSessionHas('succes', 'Dépense enregistrée.');
        $this->assertSame(1, Depense::count());
    }

    public function test_une_depense_ne_sort_jamais_plus_que_ce_que_contient_le_tiroir(): void
    {
        $this->sortieDeCaisse($this->gerant, 'Loyer', 60000)->assertSessionHas('erreur');

        // Tout ou rien : ni dépense, ni mouvement.
        $this->assertSame(0, Depense::count());
        $this->assertSame(0, MouvementCaisse::count());
        $this->assertSame(50000, $this->tiroir());
    }

    public function test_une_depense_payee_depuis_la_tresorerie(): void
    {
        $this->actingAs($this->proprietaire)->post(route('comptabilite.mouvements.store', $this->caisseGenerale), ['type' => 'sortie', 'montant' => 80000, 'motif' => 'Loyer', 'beneficiaire' => 'Agence'])
            ->assertSessionHas('succes', 'Dépense enregistrée.');

        $depense = Depense::sole();
        $this->assertSame([80000, 'Agence', null, null, 1], [$depense->montant, $depense->beneficiaire, $depense->magasin_id, $depense->session_caisse_id, $depense->compte_tresorerie_id]);

        $ecriture = EcritureCompteTresorerie::where('type', EcritureCompteTresorerieType::SortieManuelle->value)->sole();
        $this->assertSame(-80000, (int) $ecriture->montant);
        $this->assertSame($depense->getMorphClass(), $ecriture->reference_type);
        $this->assertSame(120000, $this->coffre());

        // Plus que le solde : refusé, rien n'est écrit.
        $this->actingAs($this->proprietaire)->post(route('comptabilite.mouvements.store', $this->caisseGenerale), ['type' => 'sortie', 'montant' => 500000, 'motif' => 'Loyer'])
            ->assertSessionHas('erreur');
        $this->assertSame(1, Depense::count());
        $this->assertSame(120000, $this->coffre());
    }

    public function test_l_ecran_nouvelle_depense_propose_les_sources_selon_l_utilisateur(): void
    {
        // Le propriétaire gère la trésorerie : il paie depuis la Caisse Générale, pour un magasin précis, reçu joint.
        Storage::fake('public');
        $recu = ImageProduit::traitementDisponible() ? UploadedFile::fake()->image('recu.jpg', 1200, 900) : null;

        $this->actingAs($this->proprietaire)->get(route('depenses.create'))->assertOk()->assertSee('Caisse générale')->assertDontSee('Ma caisse');
        $this->actingAs($this->proprietaire)->post(route('depenses.store'), array_filter([
            'source' => 'compte:1', 'motif_mouvement_id' => $this->loyer->id, 'montant' => 30000, 'beneficiaire' => 'Agence', 'magasin_id' => 2, 'recu' => $recu,
        ]))->assertRedirect(route('depenses.index'))->assertSessionHas('succes');

        $depense = Depense::sole();
        $this->assertSame([2, 1, 30000], [$depense->magasin_id, $depense->compte_tresorerie_id, $depense->montant]);
        $this->assertSame(170000, $this->coffre());
        if ($recu) {
            $this->assertNotNull($depense->getFirstMedia('recu'));
            $this->assertLessThanOrEqual(ImageProduit::POIDS_MAX, $depense->getFirstMedia('recu')->size);
        }

        // Le caissier autorisé ne voit que sa propre caisse ouverte.
        $this->caissier->givePermissionTo('depense.creer');
        $this->actingAs($this->caissier)->get(route('depenses.create'))->assertOk()->assertSee('Ma caisse — Caisse 1')->assertDontSee('Caisse générale');
        $this->actingAs($this->caissier)->post(route('depenses.store'), ['source' => 'compte:1', 'motif_mouvement_id' => $this->loyer->id, 'montant' => 1000])
            ->assertSessionHasErrors('source');
        $this->actingAs($this->caissier)->post(route('depenses.store'), ['source' => 'session', 'motif_mouvement_id' => $this->loyer->id, 'montant' => 4000])
            ->assertSessionHas('succes');
        $this->assertSame(46000, $this->tiroir());

        // Un motif qui n'est pas une dépense est refusé ici.
        $this->actingAs($this->caissier)->post(route('depenses.store'), ['source' => 'session', 'motif_mouvement_id' => $this->retrait->id, 'montant' => 1000])
            ->assertSessionHas('erreur');
        $this->assertSame(2, Depense::count());

        // Sans permission : aucun accès à la saisie.
        $this->actingAs($this->utilisateur('Caissier', 1))->get(route('depenses.create'))->assertForbidden();
    }

    public function test_annuler_une_depense_remet_l_argent_la_ou_il_peut_revenir(): void
    {
        // Session encore ouverte : l'argent revient dans le tiroir.
        $this->sortieDeCaisse($this->gerant, 'Loyer', 15000);
        $depense = Depense::sole();

        $this->actingAs($this->gerant)->post(route('depenses.annuler', $depense), ['raison' => ''])->assertSessionHasErrors('raison');
        $this->actingAs($this->gerant)->post(route('depenses.annuler', $depense), ['raison' => 'Erreur de montant'])->assertSessionHas('succes');

        $depense->refresh();
        $this->assertTrue($depense->estAnnulee());
        $this->assertSame(['Erreur de montant', $this->gerant->id], [$depense->motif_annulation, $depense->annulee_par]);
        $this->assertSame(50000, $this->tiroir());
        $this->assertSame(['sortie', 'entree'], MouvementCaisse::orderBy('id')->pluck('type')->map->value->all());
        $this->assertSame(0, Depense::effectives()->count());

        // Une dépense ne s'annule qu'une fois.
        $this->actingAs($this->gerant)->post(route('depenses.annuler', $depense), ['raison' => 'Encore'])->assertSessionHas('erreur');
        $this->assertSame(50000, $this->tiroir());

        // Session clôturée depuis : le tiroir a été vidé à la Caisse Générale, c'est elle qui reçoit le retour.
        $this->sortieDeCaisse($this->gerant, 'Loyer', 8000);
        $seconde = Depense::effectives()->sole();
        DB::table('session_caisses')->where('id', 1)->update(['date_cloture' => now(), 'date_fermeture' => now()]);

        $this->actingAs($this->gerant)->post(route('depenses.annuler', $seconde), ['raison' => 'Saisie en double'])->assertSessionHas('succes');

        $this->assertSame(208000, $this->coffre());
        $this->assertSame(3, MouvementCaisse::count());

        // Le caissier, sans la permission, ne peut rien annuler.
        $this->actingAs($this->caissier)->post(route('depenses.annuler', $seconde), ['raison' => 'x'])->assertForbidden();
    }

    public function test_la_nature_d_une_depense_est_figee_a_la_saisie(): void
    {
        $this->sortieDeCaisse($this->gerant, 'Loyer', 15000);

        // Le motif n'est plus une dépense, puis il est renommé.
        $this->actingAs($this->proprietaire)->put(route('motifs-mouvement.update', $this->loyer), ['nom' => 'Location', 'est_depense' => 0, 'actif' => 1])->assertSessionHasNoErrors();

        // La dépense passée reste une dépense, sous son libellé d'origine.
        $depense = Depense::sole();
        $this->assertSame('Loyer', $depense->motif);
        $this->assertFalse($depense->estAnnulee());

        // Une nouvelle sortie sous ce motif n'en est plus une.
        $this->sortieDeCaisse($this->gerant, 'Location', 5000)->assertSessionHas('succes', 'Mouvement de caisse enregistré.');
        $this->assertSame(1, Depense::count());
    }

    public function test_un_motif_se_marque_depense_a_sa_creation_y_compris_a_la_volee(): void
    {
        $this->actingAs($this->proprietaire)->post(route('motifs-mouvement.store'), ['nom' => 'Électricité', 'est_depense' => 1, 'actif' => 1])->assertSessionHasNoErrors();
        $this->assertTrue(MotifMouvement::firstWhere('nom', 'Électricité')->est_depense);

        $this->actingAs($this->gerant)->postJson(route('motifs-mouvement.rapide'), ['nom' => 'Transport', 'est_depense' => true])
            ->assertCreated()->assertJsonPath('est_depense', true);
        $this->actingAs($this->gerant)->postJson(route('motifs-mouvement.rapide'), ['nom' => 'Appoint'])
            ->assertCreated()->assertJsonPath('est_depense', false);

        // Un motif qui existe déjà garde sa nature.
        $this->actingAs($this->gerant)->postJson(route('motifs-mouvement.rapide'), ['nom' => 'Retrait du propriétaire', 'est_depense' => true])
            ->assertJsonPath('est_depense', false);

        $this->actingAs($this->proprietaire)->get(route('motifs-mouvement.index'))->assertOk()->assertSee('Dépense');
    }

    public function test_les_motifs_de_depart_qui_sont_des_charges_sont_coches_d_office(): void
    {
        $this->seed(MotifMouvementSeeder::class);

        foreach (['Loyer', 'Paiement de salaire', "Frais d'électricité", 'Petite dépense diverse'] as $charge) {
            $this->assertTrue(MotifMouvement::firstWhere('nom', $charge)->est_depense, $charge);
        }

        // Déplacer de l'argent n'est pas le dépenser ; et la marchandise d'un
        // fournisseur est déjà comptée dans le coût des ventes.
        foreach (['Appoint de caisse', 'Retrait pour dépôt bancaire', 'Paiement fournisseur en espèces'] as $autre) {
            $this->assertFalse(MotifMouvement::firstWhere('nom', $autre)->est_depense, $autre);
        }

        // Relancer le seeder ne revient pas sur le réglage du commerce.
        MotifMouvement::firstWhere('nom', 'Loyer')->update(['est_depense' => false]);
        $this->seed(MotifMouvementSeeder::class);
        $this->assertFalse(MotifMouvement::firstWhere('nom', 'Loyer')->est_depense);
        // « Loyer » existait déjà : seul « Retrait du propriétaire » s'ajoute à la liste de départ.
        $this->assertSame(count(MotifMouvementSeeder::MOTIFS) + 1, MotifMouvement::count());
    }

    public function test_le_recapitulatif_par_motif_replie_la_fin_du_classement(): void
    {
        $service = app(\App\Services\DepenseService::class);
        $payer = function (int $nombre) use ($service) {
            foreach (range(1, $nombre) as $rang) {
                $motif = MotifMouvement::firstOrCreate(['nom' => "Poste {$rang}"], ['est_depense' => true, 'actif' => true]);
                if (! Depense::where('motif', $motif->nom)->exists()) {
                    $service->payerDepuisCompte($this->caisseGenerale, $motif, 10000 - $rang * 1000, $this->proprietaire);
                }
            }
        };

        // 7 motifs : un seul dépasse la limite de 6, il est montré tel quel.
        $payer(7);
        $this->actingAs($this->proprietaire)->get(route('depenses.index'))->assertOk()
            ->assertSee('Poste 7')->assertDontSee('autres motifs');

        // 9 motifs : les trois derniers sont résumés, et restent dépliables.
        $payer(9);
        $this->actingAs($this->proprietaire)->get(route('depenses.index'))->assertOk()
            ->assertSee('3 autres motifs')->assertSee(montant(6000))
            ->assertSee('Voir les 9 motifs')->assertSee('Poste 9');
    }

    public function test_la_liste_des_depenses_totalise_par_motif_et_respecte_le_magasin_de_l_utilisateur(): void
    {
        $electricite = MotifMouvement::create(['nom' => 'Électricité', 'est_depense' => true, 'actif' => true]);
        $this->sortieDeCaisse($this->gerant, 'Loyer', 15000);
        $this->sortieDeCaisse($this->gerant, 'Électricité', 5000);
        // Dépense du magasin 2 et dépense de toute l'entreprise, payées depuis la trésorerie.
        $this->actingAs($this->proprietaire)->post(route('depenses.store'), ['source' => 'compte:1', 'motif_mouvement_id' => $electricite->id, 'montant' => 7000, 'magasin_id' => 2]);
        $this->actingAs($this->proprietaire)->post(route('depenses.store'), ['source' => 'compte:1', 'motif_mouvement_id' => $this->loyer->id, 'montant' => 40000]);
        // Une dépense annulée ne compte pas.
        $this->sortieDeCaisse($this->gerant, 'Loyer', 1000);
        $this->actingAs($this->gerant)->post(route('depenses.annuler', Depense::latest('id')->first()), ['raison' => 'Erreur']);

        $this->actingAs($this->proprietaire)->get(route('depenses.index'))
            ->assertOk()
            ->assertViewHas('total', 67000)
            ->assertViewHas('nombre', 4)
            ->assertViewHas('parMotif', fn ($lignes) => $lignes->pluck('total', 'motif')->map(fn ($t) => (int) $t)->all() === ['Loyer' => 55000, 'Électricité' => 12000])
            ->assertSee('Annulée')
            ->assertSee("Toute l'entreprise");

        // Le gérant du magasin 1 ne voit que ses dépenses.
        $this->actingAs($this->gerant)->get(route('depenses.index'))->assertOk()->assertViewHas('total', 20000)->assertViewHas('nombre', 2);

        // Filtres et exports.
        $this->actingAs($this->proprietaire)->get(route('depenses.index', ['motif' => 'Électricité', 'magasin_id' => 2]))->assertViewHas('total', 7000);
        $this->actingAs($this->proprietaire)->get(route('depenses.index', ['du' => now()->subYear()->toDateString(), 'au' => now()->subMonths(2)->toDateString()]))->assertViewHas('total', 0);
        $this->actingAs($this->proprietaire)->get(route('depenses.pdf'))->assertOk();
        $this->actingAs($this->proprietaire)->get(route('depenses.excel'))->assertOk();
        $this->actingAs($this->proprietaire)->get(route('depenses.excel', ['format' => 'csv']))->assertOk();

        // Sans la permission de les voir : refusé.
        $this->actingAs($this->caissier)->get(route('depenses.index'))->assertForbidden();
    }

    public function test_le_tableau_de_bord_donne_le_resultat_marge_moins_depenses_moins_pertes(): void
    {
        $dates = ['created_at' => now(), 'updated_at' => now()];
        DB::table('categories')->insert(['id' => 1, 'nom' => 'Divers', 'actif' => true] + $dates);
        DB::table('produits')->insert(['id' => 1, 'sku' => 'PRD-1', 'nom' => 'Riz', 'categorie_id' => 1, 'prix_piece' => 4000, 'seuil_alerte' => 1, 'actif' => true] + $dates);
        DB::table('stocks')->insert(['produit_id' => 1, 'magasin_id' => 1, 'quantite' => 50, 'cout_moyen_pondere' => 3000] + $dates);
        // Une vente : 3 pièces à 4 000, qui en ont coûté 3 000 → marge brute 3 000.
        DB::table('ventes')->insert(['id' => 1, 'numero' => 'M1-C1-000001', 'magasin_id' => 1, 'session_caisse_id' => 1, 'caissier_id' => $this->caissier->id, 'sous_total' => 12000, 'total_net' => 12000, 'created_at' => now()]);
        DB::table('ligne_ventes')->insert(['vente_id' => 1, 'produit_id' => 1, 'quantite' => 3, 'quantite_pieces' => 3, 'prix_unitaire_applique' => 4000, 'cout_applique' => 3000, 'sous_total_ligne' => 12000, 'total_ligne' => 12000, 'created_at' => now()]);
        // Casse : 2 pièces, soit 6 000 au coût moyen.
        DB::table('mouvement_stocks')->insert(['produit_id' => 1, 'magasin_id' => 1, 'type' => 'casse', 'quantite' => -2, 'created_by' => $this->gerant->id, 'created_at' => now()]);
        // Dépenses : 1 000 payée, 500 annulée.
        $this->sortieDeCaisse($this->gerant, 'Loyer', 1000);
        $this->sortieDeCaisse($this->gerant, 'Loyer', 500);
        $this->actingAs($this->gerant)->post(route('depenses.annuler', Depense::latest('id')->first()), ['raison' => 'Erreur']);

        $this->actingAs($this->gerant)->get('/dashboard')
            ->assertOk()
            ->assertViewHas('margePeriode', 3000)
            ->assertViewHas('depensesPeriode', 1000)
            ->assertViewHas('pertesStockPeriode', 6000)
            ->assertViewHas('resultatPeriode', -4000)
            ->assertSee('Résultat')
            ->assertSee('Perte');

        // Sans la casse, la période est bénéficiaire ; le propriétaire voit le même résultat, consolidé.
        DB::table('mouvement_stocks')->delete();
        $this->actingAs($this->proprietaire)->get('/dashboard')->assertViewHas('resultatPeriode', 2000)->assertSee('Bénéfice');

        // Le mois dernier : rien.
        $this->actingAs($this->proprietaire)->get('/dashboard?periode=mois_dernier')->assertViewHas('resultatPeriode', 0);
    }

    public function test_le_journal_de_caisse_nomme_la_depense(): void
    {
        $this->sortieDeCaisse($this->gerant, 'Loyer', 15000);
        $this->sortieDeCaisse($this->gerant, 'Retrait du propriétaire', 2000);

        $this->actingAs($this->gerant)->get(route('sessions.show', $this->session))->assertOk()->assertSee('Dépense')->assertSee('Loyer');
        $this->actingAs($this->proprietaire)->get(route('rapports.mouvements-caisse'))->assertOk()->assertSee('Dépense');
    }

    public function test_le_jeu_d_essai_enregistre_30_depenses_sans_changer_le_solde_du_coffre(): void
    {
        $avant = $this->coffre();
        $this->seed(DepenseTestSeeder::class);

        // 30 dépenses, toutes payées par la Caisse Générale, chacune avec sa sortie.
        $sorties = EcritureCompteTresorerie::where('reference_type', (new Depense)->getMorphClass())->where('montant', '<', 0);
        $this->assertSame(30, Depense::count());
        $this->assertSame(30, Depense::effectives()->where('compte_tresorerie_id', $this->caisseGenerale->id)->count());
        $this->assertSame(30, (clone $sorties)->count());
        $this->assertSame((int) Depense::sum('montant'), -(int) (clone $sorties)->sum('montant'));

        // L'apport qui les finance est du même total : le coffre n'a ni gagné ni perdu.
        $this->assertSame($avant, $this->coffre());
        $this->assertSame((int) Depense::sum('montant'), (int) EcritureCompteTresorerie::where('motif', DepenseTestSeeder::MOTIF_APPORT)->value('montant'));

        // Du début du mois à maintenant, jamais dans le futur ; plusieurs motifs, avec et sans magasin.
        $this->assertSame(30, Depense::whereBetween('created_at', [now()->startOfMonth(), now()->addMinute()])->count());
        $this->assertGreaterThanOrEqual(10, Depense::distinct()->count('motif'));
        $this->assertGreaterThan(0, Depense::whereNull('magasin_id')->count());
        $this->assertGreaterThan(0, Depense::whereNotNull('magasin_id')->count());
        $this->assertFalse(Carbon::hasTestNow(), "L'horloge est remise à l'heure après le jeu d'essai.");

        // L'écran les montre : récapitulatif replié, et 25 lignes sur la première page.
        $this->actingAs($this->proprietaire)->get(route('depenses.index'))->assertOk()
            ->assertSee('30 dépenses payées')->assertSee('autres motifs')
            ->assertViewHas('depenses', fn ($page) => $page->count() === 25 && $page->total() === 30);

        // Relancé, il n'ajoute rien.
        $this->seed(DepenseTestSeeder::class);
        $this->assertSame(30, Depense::count());
        $this->assertSame($avant, $this->coffre());
    }
}
