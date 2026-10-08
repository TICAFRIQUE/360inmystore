<?php

namespace App\Support;

use App\Models\Produit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

/**
 * Image d'un produit : jamais plus de 1 Mo enregistré, quelle que soit la
 * photo envoyée (un téléphone en produit facilement de 4 à 8 Mo). L'image est
 * réduite à l'enregistrement — dimensions, puis qualité — plutôt que refusée :
 * l'utilisateur n'a pas à savoir redimensionner une photo.
 *
 * Une miniature (« vignette ») est produite en plus par la médiathèque (voir
 * Produit::registerMediaConversions) : c'est elle qu'affiche l'écran de caisse.
 */
class ImageProduit
{
    /** Poids maximal de l'image enregistrée, en octets. */
    public const POIDS_MAX = 1024 * 1024;

    /** Poids maximal accepté à l'envoi, en kilo-octets (règle de validation). */
    public const ENVOI_MAX_KO = 8192;

    /** Plus grand côté de l'image enregistrée, en pixels. */
    private const COTES = [1600, 1200, 900];

    private const QUALITES = [82, 70, 58];

    public static function traitementDisponible(): bool
    {
        return extension_loaded('gd') || extension_loaded('imagick');
    }

    /**
     * Remplace l'image du produit par celle envoyée, réduite sous 1 Mo.
     *
     * @throws ValidationException si l'image dépasse 1 Mo et ne peut pas être réduite
     */
    public static function attacher(Produit $produit, UploadedFile $fichier): void
    {
        $reduite = self::reduire($fichier);

        if ($reduite === null && $fichier->getSize() > self::POIDS_MAX) {
            throw ValidationException::withMessages([
                'image' => "Cette image dépasse 1 Mo et n'a pas pu être réduite automatiquement. Choisissez une image plus légère.",
            ]);
        }

        $nom = Str::slug(pathinfo($fichier->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'produit';

        if ($reduite !== null) {
            $produit->addMedia($reduite)->usingName($nom)->usingFileName($nom.'.jpg')->toMediaCollection('image');

            return;
        }

        $produit->addMedia($fichier)->usingName($nom)->toMediaCollection('image');
    }

    /**
     * Chemin d'une copie réduite (JPEG, fond blanc), ou null si le traitement
     * d'image n'est pas disponible sur ce serveur ou échoue — l'appelant
     * garde alors l'original s'il est assez léger.
     */
    private static function reduire(UploadedFile $fichier): ?string
    {
        if (! self::traitementDisponible()) {
            return null;
        }

        $sortie = tempnam(sys_get_temp_dir(), 'img').'.jpg';

        try {
            // Du plus fidèle au plus compressé : on s'arrête au premier
            // résultat sous 1 Mo (presque toujours le tout premier).
            foreach (self::COTES as $cote) {
                foreach (self::QUALITES as $qualite) {
                    Image::load($fichier->getRealPath())
                        ->fit(Fit::Max, $cote, $cote)
                        ->background('#ffffff')
                        ->quality($qualite)
                        ->save($sortie);

                    clearstatcache(true, $sortie);
                    if (filesize($sortie) <= self::POIDS_MAX) {
                        return $sortie;
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        @unlink($sortie);

        return null;
    }
}
