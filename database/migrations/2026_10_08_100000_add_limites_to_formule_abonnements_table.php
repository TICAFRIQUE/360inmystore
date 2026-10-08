<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Limites d'une formule d'abonnement, réglées par l'éditeur : nombre maximal
 * de magasins, de caisses, d'utilisateurs et de produits. Vide = sans limite
 * (voir App\Plateforme\LimitesFormule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formule_abonnements', function (Blueprint $table) {
            $table->unsignedInteger('max_magasins')->nullable()->after('avantages');
            $table->unsignedInteger('max_caisses')->nullable()->after('max_magasins');
            $table->unsignedInteger('max_utilisateurs')->nullable()->after('max_caisses');
            $table->unsignedInteger('max_produits')->nullable()->after('max_utilisateurs');
        });
    }

    public function down(): void
    {
        Schema::table('formule_abonnements', function (Blueprint $table) {
            $table->dropColumn(['max_magasins', 'max_caisses', 'max_utilisateurs', 'max_produits']);
        });
    }
};
