<?php

namespace App\Enums;

enum VenteType: string
{
    case Facture = 'facture';
    case BonLivraison = 'bon_livraison';

    public function libelle(): string
    {
        return match ($this) {
            self::Facture => 'Facture',
            self::BonLivraison => 'Bon de livraison',
        };
    }

    public function classeBadge(): string
    {
        return match ($this) {
            self::Facture => 'text-bg-primary-subtle text-primary-emphasis',
            self::BonLivraison => 'text-bg-info-subtle text-info-emphasis',
        };
    }
}
