<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Table singleton (une seule ligne, id=1) : coordonnées de contact affichées
 * sur "Mon abonnement" quand l'abonnement a expiré. Toujours passer par
 * actuel(), jamais un ::find(1) direct — même principe que Parametre::actuel().
 */
#[Fillable(['telephone', 'whatsapp', 'email', 'message'])]
class ConfigurationAbonnement extends Model
{
    use LogsActivity;

    public const CACHE_KEY = 'configuration_abonnement.actuel';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    public static function actuel(): self
    {
        // Depuis l'espace d'une entreprise, le contact à
        // afficher est celui de l'éditeur, saisi sur la plateforme — pas la
        // ligne (vide) de la base de l'entreprise. Lu sans cache : l'espace
        // n'aurait aucun moyen de savoir que la plateforme l'a modifié, et
        // cette page n'est consultée qu'occasionnellement.
        if (plateforme()->estEntreprise()) {
            return static::on('plateforme')->first() ?? new self;
        }

        $attributs = Cache::rememberForever(self::CACHE_KEY, function () {
            return self::query()->firstOrCreate(['id' => 1])->getAttributes();
        });

        return (new self)->newFromBuilder($attributs);
    }

    public static function invaliderCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::invaliderCache());
    }
}
