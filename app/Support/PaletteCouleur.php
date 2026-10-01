<?php

namespace App\Support;

/**
 * Nuances dérivées d'une couleur primaire arbitraire, équivalent PHP des
 * fonctions Sass mix()/tint-color()/shade-color() utilisées par Bootstrap et
 * resources/sass/app.scss pour calculer les variantes de $primary (survol,
 * actif, focus, subtle...). Seul endroit qui calcule ces nuances — jamais
 * dupliqué dans une vue (voir CLAUDE.md, palette).
 *
 * Déviation assumée par rapport au CSS Bootstrap compilé : selon la couleur,
 * le calcul de contraste automatique de Bootstrap (color-contrast()) choisit
 * un texte noir ou blanc, ce qui bascule le survol/actif de .btn-primary
 * entre tint-color (éclairci) et shade-color (foncé). Reproduire cet
 * algorithme de contraste pour une couleur admin arbitraire serait fragile
 * (et incohérent avec le texte blanc déjà forcé sur .btn-primary, voir
 * app.scss) — on fonce donc toujours au survol/actif ici, quelle que soit la
 * couleur choisie.
 *
 * Limitations acceptées (non couvertes par cette palette, restent sur la
 * couleur primaire compilée) : .formule-pricing-card (abonnement/mon.blade.php) et
 * #factureImprimable (ticket imprimable, volontairement autonome de
 * Bootstrap) — voir app.scss.
 */
class PaletteCouleur
{
    /** Doit rester identique à $primary (resources/sass/app.scss). */
    public const DEFAUT = '#2563eb';

    public static function estValide(?string $hex): bool
    {
        return $hex !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1;
    }

    /**
     * @return array<string,string> nuances prêtes à injecter en CSS
     */
    public static function nuances(string $hex): array
    {
        return [
            'primaire' => $hex,
            'primaire_rgb' => self::rgbString($hex),
            'btn_hover_bg' => self::ombre($hex, 15),
            'btn_hover_border' => self::ombre($hex, 20),
            'btn_active_bg' => self::ombre($hex, 20),
            'btn_active_border' => self::ombre($hex, 25),
            'lien_survol' => self::ombre($hex, 20),
            'lien_survol_rgb' => self::rgbString(self::ombre($hex, 20)),
            'texte_emphase' => self::ombre($hex, 60),
            'fond_attenue' => self::teinte($hex, 80),
            'bordure_attenuee' => self::teinte($hex, 60),
            'bordure_champ_focus' => self::teinte($hex, 50),
        ];
    }

    /** Éclaircit $hex en le mélangeant à du blanc (équiv. Sass tint-color). */
    public static function teinte(string $hex, float $poidsBlanc): string
    {
        return self::melanger($hex, '#ffffff', $poidsBlanc);
    }

    /** Fonce $hex en le mélangeant à du noir (équiv. Sass shade-color). */
    public static function ombre(string $hex, float $poidsNoir): string
    {
        return self::melanger($hex, '#000000', $poidsNoir);
    }

    private static function melanger(string $hexBase, string $hexAutre, float $poidsAutre): string
    {
        [$r1, $g1, $b1] = self::versRgb($hexBase);
        [$r2, $g2, $b2] = self::versRgb($hexAutre);
        $poids = max(0, min(100, $poidsAutre)) / 100;

        return sprintf(
            '#%02x%02x%02x',
            (int) round($r1 * (1 - $poids) + $r2 * $poids),
            (int) round($g1 * (1 - $poids) + $g2 * $poids),
            (int) round($b1 * (1 - $poids) + $b2 * $poids),
        );
    }

    private static function versRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function rgbString(string $hex): string
    {
        return implode(', ', self::versRgb($hex));
    }
}
