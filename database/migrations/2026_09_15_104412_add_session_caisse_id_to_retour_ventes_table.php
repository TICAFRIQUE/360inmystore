<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Nullable : les retours déjà en base n'en ont pas — aucun backfill,
        // deviner à quelle session ils ont "vraiment" été traités serait
        // faux. Sert désormais à rattacher un retour à la caisse où il est
        // réellement traité (pas forcément celle de la vente d'origine),
        // voir RetourVenteService::retourner() et JournalCaisse.
        Schema::table('retour_ventes', function (Blueprint $table) {
            $table->foreignId('session_caisse_id')->nullable()->after('vente_id')
                ->constrained('session_caisses')->restrictOnDelete();
            $table->index('session_caisse_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('retour_ventes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('session_caisse_id');
        });
    }
};
