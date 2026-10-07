<?php

namespace App\Console\Commands;

use App\Models\Parametre;
use App\Models\User;
use App\Plateforme\CreationEntreprise;
use Database\Seeders\ReferentielSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prépare la base d'une entreprise qui vient d'être créée : tables,
 * référentiel de départ, identité du commerce et compte du propriétaire. Lancée par App\Plateforme\CreationEntreprise dans un
 * processus dédié à cette entreprise — jamais à la main.
 */
#[Signature('entreprises:initialiser')]
#[Description("Prépare la base d'une nouvelle entreprise (usage interne à la plateforme)")]
class InitialiserEntreprise extends Command
{
    protected $hidden = true;

    public function handle(): int
    {
        if (! plateforme()->estEntreprise()) {
            $this->error("Aucune entreprise désignée : cette commande est lancée par la plateforme, pas à la main.");

            return self::FAILURE;
        }

        $donnees = json_decode((string) getenv(CreationEntreprise::VARIABLE_DONNEES), true);

        if (! is_array($donnees)) {
            $this->error("Données d'initialisation absentes.");

            return self::FAILURE;
        }

        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--class' => ReferentielSeeder::class, '--force' => true]);

        DB::transaction(function () use ($donnees) {
            Parametre::actuel()->update([
                'nom' => $donnees['nom'],
                'pays' => $donnees['pays'],
                'numero' => $donnees['telephone'],
            ]);

            // Rôle Superadmin : le propriétaire a tous les droits sur SON
            // espace. Il ne gère pas pour autant son abonnement — voir
            // User::estGestionnaireAbonnement(), toujours faux dans l'espace
            // d'une entreprise.
            $proprietaire = User::create([
                'name' => $donnees['proprietaire_nom'],
                'username' => $donnees['username'],
                'telephone' => $donnees['telephone'],
                'password' => $donnees['mot_de_passe'],
                'actif' => true,
            ]);
            $proprietaire->assignRole('Superadmin');
        });

        return self::SUCCESS;
    }
}
