<?php

namespace Database\Seeders;

use App\Models\Parametre;
use Illuminate\Database\Seeder;

class ParametreSeeder extends Seeder
{
    /**
     * Aucun logo n'est attaché ici : tant que rien n'est configuré, l'app
     * retombe sur le logo par défaut généré (voir Parametre::logoUrl() et
     * public/images/logo-defaut.svg) — un logo personnalisé se dépose depuis
     * Paramètres, jamais en dur dans le seeder.
     */
    public function run(): void
    {
        Parametre::query()->firstOrCreate(['id' => 1], [
            'nom' => '360inmystore',
        ]);

        Parametre::invaliderCache();
    }
}
