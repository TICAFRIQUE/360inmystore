<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Table singleton : une seule ligne (id=1) porte la configuration globale de
 * l'application. Toujours passer par actuel(), jamais par un ::find(1) direct,
 * pour bénéficier du cache (lu sur presque chaque page : sidebar, connexion,
 * tickets, e-mails).
 */
#[Fillable(['nom', 'slogan', 'couleur_primaire', 'numero', 'adresse', 'duree_validite_devis_jours', 'devise_id', 'dossier_sauvegarde'])]
class Parametre extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity;

    public const CACHE_KEY = 'parametre.actuel';

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    /**
     * Ne met en cache que les colonnes brutes (tableau), jamais l'instance de
     * modèle complète : le driver de cache "database" sérialise via
     * serialize()/unserialize(), et l'état interne de InteractsWithMedia (HasMedia)
     * ne survit pas fidèlement à ce round-trip (__PHP_Incomplete_Class au
     * réveil). newFromBuilder() réhydrate ensuite un modèle normal, avec sa
     * relation media chargée à la demande (non mise en cache, requête légère).
     */
    public static function actuel(): self
    {
        $attributs = Cache::rememberForever(self::CACHE_KEY, function () {
            return self::query()->firstOrCreate(['id' => 1], [
                'nom' => '360inmystore',
            ])->getAttributes();
        });

        return (new self)->newFromBuilder($attributs);
    }

    public static function invaliderCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Logo par défaut (SVG généré, voir public/images/logo-defaut.svg) tant
     * qu'aucun logo personnalisé n'est configuré — jamais de lien mort.
     */
    public function logoUrl(): string
    {
        return $this->getFirstMediaUrl('logo') ?: asset('images/logo-defaut.svg');
    }

    /**
     * Logo encodé en data URI pour les documents imprimables (facture de
     * vente/devis/bon de livraison/bon de commande) : dompdf ne charge une
     * image distante que si enable_remote est activé côté serveur, d'où
     * l'encodage direct plutôt qu'une URL. Utilisé par tous les contrôleurs
     * de facture — un seul endroit à faire évoluer.
     *
     * En noir et blanc par défaut sur ces documents (dompdf ne supporte pas
     * le filtre CSS grayscale(), la désaturation se fait donc côté serveur
     * via GD, driver image déjà utilisé par medialibrary — voir
     * config/media-library.php). Le fichier original stocké n'est jamais
     * modifié, uniquement cette copie encodée ; ré-encodée en PNG (préserve
     * la transparence) quel que soit le format d'origine.
     */
    public function logoDataUri(bool $noirEtBlanc = true): ?string
    {
        $logo = $this->getFirstMedia('logo');
        if (! $logo || ! is_file($logo->getPath())) {
            return null;
        }

        $bytes = file_get_contents($logo->getPath());
        $mime = $logo->mime_type;

        if ($noirEtBlanc) {
            [$bytes, $mime] = $this->logoEnNoirEtBlanc($bytes, $mime);
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /**
     * @return array{0: string, 1: string} contenu de l'image + type mime —
     *   image/couleur d'origine inchangée si GD ne sait pas la décoder.
     */
    private function logoEnNoirEtBlanc(string $bytes, string $mimeOriginal): array
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return [$bytes, $mimeOriginal];
        }

        imagefilter($image, IMG_FILTER_GRAYSCALE);
        imagesavealpha($image, true);

        ob_start();
        imagepng($image);
        $pngBytes = ob_get_clean();
        imagedestroy($image);

        return [$pngBytes, 'image/png'];
    }

    public function devise(): BelongsTo
    {
        return $this->belongsTo(Devise::class);
    }

    protected static function booted(): void
    {
        static::saved(function () {
            self::invaliderCache();
            // La devise affichée partout (fonction montant()) dépend de ce
            // singleton — sans ça, changer la devise active dans Paramètres
            // resterait sans effet jusqu'à l'expiration (jamais, en pratique,
            // Cache::rememberForever) du cache de Devise::abreviationActuelle().
            Devise::invaliderCache();
        });
        static::deleted(fn () => self::invaliderCache());
    }
}
