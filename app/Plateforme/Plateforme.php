<?php

namespace App\Plateforme;

use App\Models\Plateforme\Entreprise;

/**
 * Contexte de la requête (ou de la commande) en cours sur la plateforme —
 * voir CLAUDE.md « Plateforme SaaS » et PLATEFORME.md.
 *
 * Renseigné une seule fois, avant le démarrage de l'application
 * (PlateformeServiceProvider), puis seulement lu : tout le reste du code
 * demande « dans quel contexte suis-je ? » à cet objet (helper plateforme()),
 * jamais en relisant le nom d'hôte ou la configuration.
 */
class Plateforme
{
    /** Domaine principal : site vitrine, inscription, espace de l'éditeur. */
    public const CENTRALE = 'centrale';

    /** Espace d'une entreprise cliente, sur sa propre base de données. */
    public const ENTREPRISE = 'entreprise';

    /** Sous-domaine qui ne correspond à aucune entreprise. */
    public const INTROUVABLE = 'introuvable';

    /** Entreprise connue mais dont l'accès est fermé (suspendue, en cours de création). */
    public const INDISPONIBLE = 'indisponible';

    private string $etat = self::CENTRALE;

    private ?int $entrepriseId = null;

    private ?string $sousDomaine = null;

    private ?Entreprise $entreprise = null;

    public function definir(string $etat, ?int $entrepriseId = null, ?string $sousDomaine = null): void
    {
        $this->etat = $etat;
        $this->entrepriseId = $entrepriseId;
        $this->sousDomaine = $sousDomaine;
        $this->entreprise = null;
    }

    public function etat(): string
    {
        return $this->etat;
    }

    public function estCentrale(): bool
    {
        return $this->etat === self::CENTRALE;
    }

    public function estEntreprise(): bool
    {
        return $this->etat === self::ENTREPRISE;
    }

    /** L'espace demandé ne peut pas être servi (inconnu, suspendu, pas encore prêt). */
    public function estBloquee(): bool
    {
        return in_array($this->etat, [self::INTROUVABLE, self::INDISPONIBLE], true);
    }

    public function entrepriseId(): ?int
    {
        return $this->entrepriseId;
    }

    /** Sous-domaine demandé, y compris quand il ne correspond à aucune entreprise. */
    public function sousDomaine(): ?string
    {
        return $this->sousDomaine;
    }

    /**
     * Fiche de l'entreprise courante, lue dans la base de la plateforme —
     * null hors d'un espace d'entreprise.
     */
    public function entreprise(): ?Entreprise
    {
        if ($this->entrepriseId === null) {
            return null;
        }

        return $this->entreprise ??= Entreprise::find($this->entrepriseId);
    }

    /** URL du domaine principal (accueil, inscription, console). */
    public function urlCentrale(string $chemin = '/'): string
    {
        return $this->racine((string) config('plateforme.domaine')).'/'.ltrim($chemin, '/');
    }

    /** URL de l'espace d'une entreprise : {sous-domaine}.{domaine}. */
    public function urlEntreprise(string $sousDomaine, string $chemin = '/'): string
    {
        return $this->racine($sousDomaine.'.'.config('plateforme.domaine')).'/'.ltrim($chemin, '/');
    }

    /**
     * Protocole et port repris de la requête en cours (indispensable en
     * développement, où la plateforme écoute sur un port non standard) ;
     * à défaut — ligne de commande — ceux de APP_URL.
     */
    private function racine(string $hote): string
    {
        $requete = app()->runningInConsole() ? null : request();

        $schema = $requete?->getScheme() ?? (parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https');
        $port = $requete?->getPort() ?? parse_url((string) config('app.url'), PHP_URL_PORT);
        $portStandard = $port === null || ($schema === 'https' && (int) $port === 443) || ($schema === 'http' && (int) $port === 80);

        return $schema.'://'.$hote.($portStandard ? '' : ':'.$port);
    }
}
