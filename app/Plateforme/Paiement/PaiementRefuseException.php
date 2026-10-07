<?php

namespace App\Plateforme\Paiement;

use RuntimeException;

/**
 * Paiement non abouti (refus du prestataire, solde insuffisant, abandon…) :
 * le message est destiné au client.
 */
class PaiementRefuseException extends RuntimeException {}
