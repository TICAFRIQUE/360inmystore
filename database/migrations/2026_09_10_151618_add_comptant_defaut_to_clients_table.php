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
        // Singleton (même principe que CompteTresorerie.type=caisse_generale) :
        // un seul client marqué comptant_defaut, seedé une fois, jamais
        // supprimable — sert de compte à créditer pour un retour sur une
        // vente comptant (sans client identifié), voir RetourVenteService.
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('comptant_defaut')->default(false)->after('actif');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('comptant_defaut');
        });
    }
};
