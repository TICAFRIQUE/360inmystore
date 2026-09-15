<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parametres', function (Blueprint $table) {
            $table->string('dossier_sauvegarde')->nullable()->after('duree_validite_devis_jours');
        });
    }

    public function down(): void
    {
        Schema::table('parametres', function (Blueprint $table) {
            $table->dropColumn('dossier_sauvegarde');
        });
    }
};
