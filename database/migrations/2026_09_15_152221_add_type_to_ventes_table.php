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
        // 'facture' par défaut pour tout l'historique existant : seule la
        // vente choisit désormais explicitement 'bon_livraison' à sa création
        // (voir VenteService::vendre()), auquel cas elle est automatiquement
        // et entièrement livrée dans la même transaction (BonLivraisonService).
        Schema::table('ventes', function (Blueprint $table) {
            $table->string('type')->default('facture')->after('numero');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
