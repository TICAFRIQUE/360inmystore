<?php

use App\Models\Parametre;
use App\Support\Telephone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Colonnes téléphone de l'application : table => colonnes. */
    private const COLONNES = [
        'clients' => ['telephone'],
        'fournisseurs' => ['telephone'],
        'magasins' => ['telephone'],
        'users' => ['telephone'],
        'parametres' => ['numero'],
        'configuration_abonnements' => ['telephone', 'whatsapp'],
    ];

    /**
     * Pays du commerce (indicatif téléphonique par défaut, voir
     * App\Support\Telephone), puis passage des numéros déjà saisis au format
     * international E.164. Un ancien numéro qui n'est pas valide pour ce pays
     * (saisie libre, numéro incomplet…) est laissé exactement tel quel —
     * jamais vidé ni deviné ; il sera corrigé à la prochaine modification de
     * la fiche.
     */
    public function up(): void
    {
        Schema::table('parametres', function (Blueprint $table) {
            $table->string('pays', 2)->default(Telephone::PAYS_DEFAUT)->after('numero');
        });

        foreach (self::COLONNES as $table => $colonnes) {
            foreach ($colonnes as $colonne) {
                DB::table($table)->whereNotNull($colonne)->where($colonne, '!=', '')->orderBy('id')
                    ->each(function (object $ligne) use ($table, $colonne) {
                        $normalise = Telephone::normaliser($ligne->{$colonne}, Telephone::PAYS_DEFAUT);

                        if ($normalise !== null && $normalise !== $ligne->{$colonne}) {
                            DB::table($table)->where('id', $ligne->id)->update([$colonne => $normalise]);
                        }
                    });
            }
        }

        // Paramètres est lu via un cache permanent (Parametre::actuel()).
        Parametre::invaliderCache();
    }

    /**
     * Les numéros restent au format international : c'est une forme valide
     * du même numéro, et la saisie d'origine n'est pas conservée.
     */
    public function down(): void
    {
        Schema::table('parametres', function (Blueprint $table) {
            $table->dropColumn('pays');
        });

        Parametre::invaliderCache();
    }
};
