<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Liens d'accès direct à l'espace d'une entreprise, à usage unique et de
     * courte durée : celui qui suit une inscription (le propriétaire arrive
     * connecté dans son espace), et celui que l'éditeur se crée pour entrer
     * dans l'espace d'un client en maintenance. Sert aussi de registre des
     * accès de maintenance : qui est entré où, et quand.
     *
     * Seule l'empreinte du jeton est enregistrée : la table ne permet pas de
     * reconstituer un lien.
     */
    public function up(): void
    {
        Schema::create('acces_espaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises');
            $table->string('empreinte', 64)->unique();
            $table->string('motif', 20); // inscription | maintenance
            // Éditeur à l'origine d'un accès de maintenance (nom affiché).
            $table->string('auteur')->nullable();
            $table->timestamp('expire_a');
            $table->timestamp('utilise_a')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('entreprises', function (Blueprint $table) {
            // Copie du nom de la formule en cours, comme abonnement_fin :
            // pour la console de l'éditeur uniquement.
            $table->string('formule_nom')->nullable()->after('motif_suspension');
        });
    }

    public function down(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            $table->dropColumn('formule_nom');
        });

        Schema::dropIfExists('acces_espaces');
    }
};
