<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un prix personnalisé peut désormais aussi bien majorer que réduire le prix
 * catalogue d'une ligne (voir ventes/create.blade.php, app.js
 * prixPersonnaliseClamp()) — il reste résolu comme une remise "montant"
 * classique (VenteService::resoudreLignes()), mais qui doit alors pouvoir
 * être négative pour augmenter total_ligne au lieu de le réduire
 * (ValideRemises::remiseValeurMin() l'autorise désormais uniquement pour une
 * ligne prix_personnalise, jamais pour une remise classique).
 *
 * ->change() indisponible (pas de doctrine/dbal installé, voir
 * 2026_09_02_130000_convertir_quantites_en_decimal.php) : ALTER TABLE en SQL
 * brut.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ligne_ventes MODIFY COLUMN remise_ligne_valeur INT NULL');
        DB::statement('ALTER TABLE ligne_ventes MODIFY COLUMN remise_ligne_montant INT NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ligne_ventes MODIFY COLUMN remise_ligne_valeur INT UNSIGNED NULL');
        DB::statement('ALTER TABLE ligne_ventes MODIFY COLUMN remise_ligne_montant INT UNSIGNED NOT NULL DEFAULT 0');
    }
};
