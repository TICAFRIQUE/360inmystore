<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gestion des dépenses (voir CLAUDE.md — « Dépenses ») : un motif de
 * mouvement peut être marqué « dépense », et chaque sortie d'argent faite
 * sous un tel motif laisse une dépense, rattachée au mouvement de caisse ou
 * de trésorerie qui a réellement sorti l'argent.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['depense.voir', 'depense.creer', 'depense.annuler'];

    /**
     * Motifs de départ (MotifMouvementSeeder) qui sont des dépenses, tels
     * qu'ils existaient à la date de cette migration — liste figée ici : une
     * migration ne doit pas changer de sens si le seeder évolue.
     */
    private const MOTIFS_DE_DEPENSE = [
        'Achat de fournitures de bureau',
        'Frais de transport/livraison',
        'Frais d\'électricité',
        'Frais d\'eau',
        'Frais de téléphone/internet',
        'Paiement de salaire',
        'Avance sur salaire',
        'Frais d\'entretien/réparation',
        'Frais de nettoyage',
        'Loyer',
        'Frais bancaires',
        'Petite dépense diverse',
    ];

    public function up(): void
    {
        Schema::table('motif_mouvements', function (Blueprint $table) {
            $table->boolean('est_depense')->default(false)->after('nom');
        });

        // Un commerce déjà installé a reçu les motifs de départ avant que la
        // case existe : ceux qui sont des charges sont cochés ici, une fois,
        // pour qu'il n'ait pas à le faire à la main. Ses propres motifs
        // restent décochés — lui seul sait ce qu'ils recouvrent.
        DB::table('motif_mouvements')->whereIn('nom', self::MOTIFS_DE_DEPENSE)->update(['est_depense' => true]);

        Schema::create('depenses', function (Blueprint $table) {
            $table->id();
            // Libellé du motif, figé à la saisie : renommer ou supprimer le
            // motif plus tard ne réécrit jamais une dépense passée.
            $table->string('motif');
            $table->foreignId('motif_mouvement_id')->nullable()->constrained('motif_mouvements')->nullOnDelete();
            $table->unsignedInteger('montant');
            $table->string('beneficiaire')->nullable();
            // Magasin concerné ; vide = toute l'entreprise.
            $table->foreignId('magasin_id')->nullable()->constrained('magasins')->restrictOnDelete();
            // D'où l'argent est sorti : le tiroir d'une session, ou un compte
            // de trésorerie — l'un ou l'autre, jamais les deux.
            $table->foreignId('session_caisse_id')->nullable()->constrained('session_caisses')->restrictOnDelete();
            $table->foreignId('compte_tresorerie_id')->nullable()->constrained('compte_tresoreries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable()->index();
            // Annulation : la dépense reste en base, l'argent est rendu par
            // un mouvement inverse (jamais de suppression).
            $table->timestamp('annulee_at')->nullable();
            $table->foreignId('annulee_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('motif_annulation')->nullable();
        });

        // Les rôles existants gardent leurs réglages (voir
        // RolePermissionSeeder) : les nouvelles permissions sont donc créées
        // ici, et données au Gérant par défaut s'il existe — il avait reçu
        // toutes les autres à sa création. Le Caissier ne les reçoit pas :
        // c'est au responsable de les lui accorder.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::where('name', 'Gérant')->first()?->givePermissionTo(self::PERMISSIONS);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', self::PERMISSIONS)->delete();

        Schema::dropIfExists('depenses');

        Schema::table('motif_mouvements', function (Blueprint $table) {
            $table->dropColumn('est_depense');
        });
    }
};
