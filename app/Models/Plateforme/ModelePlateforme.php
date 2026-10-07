<?php

namespace App\Models\Plateforme;

use Illuminate\Database\Eloquent\Model;

/**
 * Base des modèles stockés dans la base de la PLATEFORME (registre des
 * entreprises, paiements d'abonnement) et non dans celle d'une entreprise.
 *
 * Depuis l'espace d'une entreprise, la connexion par défaut est la base de
 * cette entreprise : ces modèles passent alors explicitement par la connexion
 * « plateforme ». Partout ailleurs (domaine principal, ligne de commande de
 * la plateforme, tests), la connexion par défaut EST la base de la
 * plateforme — on la garde telle quelle, pour rester dans la même
 * transaction que le reste de la requête.
 */
abstract class ModelePlateforme extends Model
{
    public function getConnectionName()
    {
        return plateforme()->estEntreprise() ? 'plateforme' : parent::getConnectionName();
    }
}
