<?php

namespace App\Plateforme\Paiement;

/**
 * Prestataire qui encaisse le paiement d'un abonnement. Le reste de
 * l'application (écran de paiement, registre des paiements, activation de
 * l'abonnement — voir AbonnementEnLigne) ne connaît que cette interface :
 * brancher un vrai prestataire (Mobile Money, carte) revient à écrire une
 * nouvelle classe et à la déclarer dans PlateformeServiceProvider, sans
 * toucher au reste.
 */
interface PasserellePaiement
{
    /** Identifiant enregistré avec chaque paiement (« simulation », puis le nom du prestataire). */
    public function nom(): string;

    /** true tant qu'aucun argent réel n'est encaissé. */
    public function estSimulation(): bool;

    /**
     * Moyens de paiement proposés au client.
     *
     * @return array<string, string> code => libellé
     */
    public function moyens(): array;

    /**
     * Tente d'encaisser le montant.
     *
     * @param  array<string, mixed>  $options  données propres au prestataire (numéro du payeur…)
     *
     * @throws PaiementRefuseException si le paiement n'aboutit pas
     */
    public function encaisser(int $montant, string $moyen, string $reference, array $options = []): void;
}
