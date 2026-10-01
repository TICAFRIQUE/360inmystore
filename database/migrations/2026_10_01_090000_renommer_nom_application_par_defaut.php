<?php

use App\Models\Parametre;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * L'application s'appelle désormais « 360inmystore ». Seules les
     * installations restées sur l'ancien nom par défaut sont renommées : un
     * nom personnalisé depuis Paramètres (celui du commerce) n'est jamais
     * touché.
     */
    public function up(): void
    {
        DB::table('parametres')
            ->where('nom', 'Gérer Mon Stock')
            ->update(['nom' => '360inmystore']);

        // Paramètres est lu via un cache permanent (Parametre::actuel()) :
        // sans ça, l'ancien nom resterait affiché malgré la mise à jour.
        Parametre::invaliderCache();
    }

    /**
     * Irréversible : rien ne distingue après coup un nom renommé ici d'un
     * « 360inmystore » saisi volontairement dans Paramètres.
     */
    public function down(): void
    {
        //
    }
};
