<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adresse e-mail du support, affichée avec le téléphone et WhatsApp partout
 * où l'on indique qui contacter (page de connexion — accès perdu —,
 * abonnement expiré, site vitrine).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuration_abonnements', function (Blueprint $table) {
            $table->string('email')->nullable()->after('whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('configuration_abonnements', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
