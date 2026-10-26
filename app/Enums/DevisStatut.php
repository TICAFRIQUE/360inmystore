<?php

namespace App\Enums;

/**
 * Cycle de vie volontairement réduit pour le moment : pas d'étape
 * « envoyé »/« accepté » séparée — un devis passe directement de brouillon à
 * transformé (la transformation en vente vaut acceptation), ou est refusé/
 * annulé. Pourra être enrichi plus tard si besoin.
 */
enum DevisStatut: string
{
    case Brouillon = 'brouillon';
    case Refuse = 'refuse';
    case Transforme = 'transforme';
    // Jamais stocké : dérivé à la volée (date de validité dépassée), voir
    // Devis::statutEffectif() — même logique que le stock, jamais une valeur
    // figée qui pourrait diverger du calcul réel.
    case Expire = 'expire';

    public function libelle(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Refuse => 'Refusé',
            self::Transforme => 'Transformé en vente',
            self::Expire => 'Expiré',
        };
    }

    /**
     * La couleur dit le sens : en cours (cyan), transformé (vert), refusé
     * (rouge), expiré — il faut en refaire un (ambre). Badge « doux », voir
     * .badge-doux-* dans resources/sass/app.scss.
     */
    public function classeBadge(): string
    {
        return match ($this) {
            self::Brouillon => 'badge-doux-info',
            self::Refuse => 'badge-doux-danger',
            self::Transforme => 'badge-doux-success',
            self::Expire => 'badge-doux-warning',
        };
    }
}
