<?php

namespace App\Plateforme\Paiement;

/**
 * Paiement SIMULÉ : aucun prestataire, aucun argent encaissé. Sert à dérouler
 * tout le parcours d'abonnement (choix de la formule, paiement, activation,
 * registre, console de l'éditeur) en attendant un vrai prestataire.
 *
 * Le testeur choisit lui-même l'issue (option « resultat ») pour pouvoir
 * vérifier aussi le cas d'un paiement refusé.
 */
class PasserelleSimulation implements PasserellePaiement
{
    public function nom(): string
    {
        return 'simulation';
    }

    public function estSimulation(): bool
    {
        return true;
    }

    public function moyens(): array
    {
        return [
            'mobile_money' => 'Mobile Money',
            'carte' => 'Carte bancaire',
        ];
    }

    public function encaisser(int $montant, string $moyen, string $reference, array $options = []): void
    {
        if (($options['resultat'] ?? 'reussi') !== 'reussi') {
            throw new PaiementRefuseException("Paiement refusé (simulation) : aucun montant n'a été débité.");
        }
    }
}
