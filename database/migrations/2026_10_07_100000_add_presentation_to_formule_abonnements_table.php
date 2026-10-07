<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Présentation d'une formule sur la page des tarifs : une phrase
     * d'accroche, la liste de ce qu'elle inclut (une ligne par élément) et
     * la mise en avant de l'offre recommandée.
     */
    public function up(): void
    {
        Schema::table('formule_abonnements', function (Blueprint $table) {
            $table->string('description')->nullable()->after('prix');
            $table->text('avantages')->nullable()->after('description');
            $table->boolean('mise_en_avant')->default(false)->after('avantages');
        });
    }

    public function down(): void
    {
        Schema::table('formule_abonnements', function (Blueprint $table) {
            $table->dropColumn(['description', 'avantages', 'mise_en_avant']);
        });
    }
};
