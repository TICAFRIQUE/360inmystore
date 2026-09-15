<?php

namespace App\Support;

/**
 * Règle d'arrondi unique (entier le plus proche, half up), appliquée partout où
 * un montant ou un coût XOF doit être résolu en entier (pas de sous-unité).
 */
class Arrondi
{
    public static function entier(float $valeur): int
    {
        return (int) floor($valeur + 0.5);
    }

    /**
     * Arrondi une quantité à 3 décimales (précision des colonnes
     * decimal(12,3), voir migration ..._convertir_quantites_en_decimal) —
     * évite qu'une imprécision flottante intermédiaire (ex. quantite ×
     * facteur) ne fausse une comparaison de stock disponible avant que la
     * valeur n'atteigne la colonne DECIMAL, qui arrondirait de toute façon
     * mais seulement à l'insertion.
     */
    public static function quantite(float $valeur): float
    {
        return round($valeur, 3);
    }

    /**
     * Exception scopée au prix d'achat INDICATIF d'un bon de commande
     * (ligne_commande_achats.prix_achat, decimal(14,2)) : contrairement au
     * reste de l'application, il n'est jamais arrondi à l'entier — seulement
     * ramené à 2 décimales pour rester reproductible en base. Le prix
     * RÉELLEMENT facturé à la réception (prix_achat_reel) reste lui entier,
     * via entier() comme partout ailleurs.
     */
    public static function prixAchat(float $valeur): float
    {
        return round($valeur, 2);
    }
}
