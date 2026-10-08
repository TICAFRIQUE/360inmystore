<?php

namespace App\Providers;

use App\Plateforme\Paiement\PasserellePaiement;
use App\Plateforme\Paiement\PasserelleSimulation;
use App\Plateforme\LimitesFormule;
use App\Plateforme\Plateforme;
use Illuminate\Database\QueryException;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Plateforme SaaS (voir CLAUDE.md « Plateforme SaaS ») : identifie l'entreprise
 * de la requête et branche l'application sur SA base de données.
 *
 * Tout se joue dans un rappel « booting », c'est-à-dire après le chargement de
 * la configuration mais AVANT le démarrage de tous les fournisseurs de
 * services : à ce moment, aucune connexion, aucun cache, aucune session n'a
 * encore été créé. Il suffit donc de corriger la configuration — tout ce qui
 * sera créé ensuite (connexion, cache et sessions en base, stockage des
 * images) pointe naturellement vers l'entreprise, sans rien « rebrancher »
 * après coup ni risquer qu'un composant garde une référence vers une autre
 * base.
 */
class PlateformeServiceProvider extends ServiceProvider
{
    /**
     * Variable d'environnement qui désigne l'entreprise d'une commande
     * artisan (voir les commandes entreprises:*), faute de nom d'hôte.
     */
    public const VARIABLE_ENTREPRISE = 'PLATEFORME_ENTREPRISE';

    public function register(): void
    {
        $this->app->singleton(Plateforme::class);

        // Point d'extension lu par le code partagé avec la version installée
        // (voir ImportProduitController) : il ne connaît pas la plateforme, et
        // demande seulement « puis-je en créer N de plus ? ». Réponse : le
        // message de refus, ou null.
        $this->app->bind('limite.creation', fn () => fn (string $ressource, int $nombre): ?string => LimitesFormule::refus($ressource, $nombre));

        $this->app->bind(PasserellePaiement::class, function () {
            return match (config('plateforme.paiement.pilote')) {
                'simulation' => new PasserelleSimulation,
                default => throw new InvalidArgumentException('Pilote de paiement inconnu : '.config('plateforme.paiement.pilote')),
            };
        });

        $this->app->booting(fn () => $this->identifier());
    }

    public function boot(): void
    {
        // Tables de la plateforme (registre des entreprises, paiements) :
        // migrées avec le reste par un simple « php artisan migrate », mais
        // uniquement sur la base de la plateforme — jamais sur celle d'une
        // entreprise. Les tests les chargent toujours, quel que soit le
        // contexte simulé, pour pouvoir exercer la plateforme.
        if (plateforme()->estCentrale() || $this->app->runningUnitTests()) {
            $this->loadMigrationsFrom(database_path('migrations/plateforme'));
        }
    }

    private function identifier(): void
    {
        // Connexion « plateforme » : copie de la connexion par défaut prise
        // AVANT toute bascule, donc toujours la base de la plateforme — voir
        // App\Models\Plateforme\ModelePlateforme.
        config(['database.connections.plateforme' => config('database.connections.'.config('database.default'))]);

        $plateforme = $this->app->make(Plateforme::class);
        $sousDomaine = $this->sousDomaineDemande();

        if ($sousDomaine === null) {
            $plateforme->definir(Plateforme::CENTRALE);

            return;
        }

        try {
            $entreprise = $this->app['db']->connection('plateforme')
                ->table('entreprises')->where('sous_domaine', $sousDomaine)->first();
        } catch (QueryException) {
            // Base de la plateforme pas encore installée (plateforme:installer).
            $entreprise = null;
        }

        // En ligne de commande, une entreprise en cours de création doit
        // rester accessible : c'est précisément la commande qui prépare sa
        // base (entreprises:initialiser).
        $accessible = $entreprise
            && ($entreprise->statut === 'active' || ($this->app->runningInConsole() && $entreprise->statut === 'creation'));

        if (! $accessible) {
            $plateforme->definir($entreprise ? Plateforme::INDISPONIBLE : Plateforme::INTROUVABLE, $entreprise?->id, $sousDomaine);

            // Ceinture et bretelles : la requête est de toute façon arrêtée
            // par BloqueEspaceIndisponible, mais si quoi que ce soit lisait
            // malgré tout la base par défaut, ce ne doit JAMAIS être celle de
            // la plateforme (ses comptes sont ceux de l'éditeur).
            config(['database.connections.'.config('database.default').'.database' => '__espace_indisponible__']);

            return;
        }

        $plateforme->definir(Plateforme::ENTREPRISE, $entreprise->id, $sousDomaine);

        $racine = $this->app->runningInConsole() ? rtrim($plateforme->urlEntreprise($sousDomaine), '/') : request()->getSchemeAndHttpHost();

        config([
            // Base de données de l'entreprise : connexion par défaut, donc
            // aussi le cache et les sessions quand ils sont stockés en base.
            'database.connections.'.config('database.default').'.database' => $entreprise->base,
            // Cache hors base (fichier, Redis…) : clés cloisonnées par entreprise.
            'cache.prefix' => config('cache.prefix').'entreprise-'.$entreprise->id.'-',
            // Images (logo, produits) : un dossier par entreprise — les
            // identifiants de média repartent de 1 dans chaque base, ils se
            // marcheraient dessus dans un dossier commun.
            'filesystems.disks.public.root' => storage_path('app/public/entreprises/'.$entreprise->id),
            'filesystems.disks.public.url' => $racine.'/storage/entreprises/'.$entreprise->id,
            'app.url' => $racine,
        ]);
    }

    /**
     * Sous-domaine demandé, ou null pour le domaine principal. Tout hôte qui
     * n'est pas un sous-domaine direct du domaine de la plateforme (adresse
     * IP, sonde de disponibilité…) est traité comme le domaine principal :
     * il ne donne accès à aucune donnée d'entreprise.
     */
    private function sousDomaineDemande(): ?string
    {
        if ($this->app->runningInConsole()) {
            $sousDomaine = getenv(self::VARIABLE_ENTREPRISE);

            return is_string($sousDomaine) && $sousDomaine !== '' ? $sousDomaine : null;
        }

        $hote = strtolower(request()->getHost());
        $domaine = strtolower((string) config('plateforme.domaine'));
        $suffixe = '.'.$domaine;

        if ($domaine === '' || $hote === $domaine || $hote === 'www'.$suffixe || ! str_ends_with($hote, $suffixe)) {
            return null;
        }

        // Un sous-domaine à plusieurs niveaux (« a.b.domaine ») est renvoyé tel
        // quel : aucune entreprise n'a de point dans son sous-domaine, il sera
        // donc simplement introuvable.
        return substr($hote, 0, -strlen($suffixe));
    }
}
