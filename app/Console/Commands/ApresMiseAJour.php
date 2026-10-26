<?php

namespace App\Console\Commands;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ce qu'il faut faire après chaque mise à jour du code, une fois les
 * migrations passées — lancée par le déploiement (.github/workflows/deploy.yml)
 * et, sur la plateforme, pour chaque entreprise (voir PLATEFORME.md). Sans
 * risque à relancer : chaque étape ne fait que ce qui manque.
 *
 * 1. Permissions : toute permission ajoutée à config/permissions.php est
 *    créée (RolePermissionSeeder ne touche jamais aux réglages des rôles
 *    existants — donner une nouvelle permission à un rôle par défaut déjà
 *    installé reste le travail d'une migration, voir celle des dépenses).
 * 2. Cache des permissions vidé, pour qu'elles s'appliquent tout de suite.
 * 3. Miniatures des photos produits manquantes générées (photos enregistrées
 *    avant les miniatures, ou dont la génération a échoué). Une erreur ici
 *    n'arrête pas la mise à jour : le catalogue retombe sur l'image d'origine.
 */
#[Signature('app:apres-mise-a-jour')]
#[Description('Crée les permissions manquantes, vide leur cache et complète les miniatures des photos')]
class ApresMiseAJour extends Command
{
    public function handle(PermissionRegistrar $permissions): int
    {
        $this->callSilently('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);
        $permissions->forgetCachedPermissions();
        $this->info('Permissions à jour.');

        try {
            $code = $this->callSilently('media-library:regenerate', ['--only-missing' => true, '--force' => true]);
            $code === self::SUCCESS
                ? $this->info('Miniatures des photos à jour.')
                : $this->warn('Miniatures : la génération ne s\'est pas terminée normalement (voir les journaux).');
        } catch (\Throwable $e) {
            report($e);
            $this->warn('Miniatures non générées : '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
