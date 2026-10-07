<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Référentiel de départ commun à toute base de l'application : base de la
 * plateforme (voir DatabaseSeeder) et base de chaque nouvelle entreprise
 * (voir entreprises:initialiser).
 * Jamais de données de démonstration ni de compte utilisateur ici.
 */
class ReferentielSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            MoyenPaiementSeeder::class,
            ParametreSeeder::class,
            DeviseSeeder::class,
            UniteSeeder::class,
            TaxeSeeder::class,
            CompteTresorerieSeeder::class,
            MotifMouvementSeeder::class,
            FormuleAbonnementSeeder::class,
            ClientComptantDefautSeeder::class,
        ]);
    }
}
