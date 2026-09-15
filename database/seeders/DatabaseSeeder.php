<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
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

        if (app()->environment('local')) {
            $superadmin = User::firstOrCreate(
                ['username' => 'superadmin'],
                [
                    'name' => 'Superadmin',
                    'email' => 'alexkouamelan96@gmail.com',
                    'password' => env('SUPERADMIN_PASSWORD', '1234'),
                    'actif' => true,
                ]
            );
            $superadmin->assignRole('Superadmin');

            // Référentiel + données transactionnelles de démonstration (au moins
            // 20 enregistrements par module transactionnel), pour tester avec du
            // volume réaliste plutôt que des listes vides.
            $this->call([
                // DemoCatalogueSeeder::class,
                // DemoTransactionsSeeder::class,
                QuincaillerieTestSeeder::class,
            ]);
        }
    }
}
