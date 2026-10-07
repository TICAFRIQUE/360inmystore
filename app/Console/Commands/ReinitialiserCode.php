<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Code de connexion oublié : le compte Superadmin ne se gère pas depuis
 * l'écran des utilisateurs (voir UtilisateurController), et personne d'autre
 * ne peut lui régénérer un code. Cette commande est donc le seul recours, pour
 * qui a accès au serveur. Elle sert aussi pour n'importe quel autre compte.
 *
 *   php artisan app:reinitialiser-code superadmin
 */
#[Signature("app:reinitialiser-code {username : Nom d'utilisateur du compte}")]
#[Description("Définit un nouveau code de connexion pour un compte existant")]
class ReinitialiserCode extends Command
{
    public function handle(): int
    {
        $utilisateur = User::where('username', $this->argument('username'))->first();

        if (! $utilisateur) {
            $this->error("Aucun compte avec le nom d'utilisateur « {$this->argument('username')} ».");

            return self::FAILURE;
        }

        $code = $this->secret('Nouveau code de connexion (4 chiffres ou plus)');
        $confirmation = $this->secret('Confirmer le code');

        $validateur = Validator::make(compact('code'), ['code' => ['required', 'string', 'min:4']]);

        if ($validateur->fails()) {
            foreach ($validateur->errors()->all() as $erreur) {
                $this->error($erreur);
            }

            return self::FAILURE;
        }

        if ($code !== $confirmation) {
            $this->error('Les deux codes ne correspondent pas.');

            return self::FAILURE;
        }

        $utilisateur->update(['password' => $code]);

        // Le code n'est jamais journalisé par le modèle (voir
        // User::getActivitylogOptions()) : trace explicite, sans auteur
        // puisque personne n'est connecté en ligne de commande.
        activity()
            ->performedOn($utilisateur)
            ->log("Code de connexion de « {$utilisateur->username} » réinitialisé en ligne de commande");

        $this->info("Code de connexion de « {$utilisateur->username} » modifié.");

        if (! $utilisateur->actif) {
            $this->warn('Ce compte est désactivé : il ne pourra pas se connecter tant qu\'il n\'est pas réactivé.');
        }

        return self::SUCCESS;
    }
}
