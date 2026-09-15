<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * prix_achat (ligne_commande_achats) devient décimal(14,2) : le prix d'achat
 * HT indicatif saisi sur un bon de commande n'est plus arrondi à l'entier
 * XOF ni à la création ni dans les totaux HT/TTC qui en découlent (voir
 * CLAUDE.md, « Achat à crédit et comptes fournisseurs ») — exception
 * volontaire et scopée au bon de commande : le prix RÉELLEMENT facturé,
 * capturé à la réception (ligne_reception_achats.prix_achat_reel), reste lui
 * entier comme le reste de l'application (CMP, stock, vente).
 *
 * ->change() indisponible (pas de doctrine/dbal, voir
 * 2026_09_02_130000_convertir_quantites_en_decimal.php) : ALTER TABLE en SQL
 * brut. UNSIGNED abandonné sur la colonne decimal, comme pour les quantités
 * (déprécié par MySQL depuis 8.0.17).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ligne_commande_achats MODIFY COLUMN prix_achat DECIMAL(14,2) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ligne_commande_achats MODIFY COLUMN prix_achat INT UNSIGNED NOT NULL');
    }
};
