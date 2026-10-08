<?php

namespace Database\Seeders;

use App\Models\MotifMouvement;
use Illuminate\Database\Seeder;

/**
 * Motifs courants d'entrée/sortie de caisse ou de trésorerie d'un commerce,
 * quel que soit son secteur — répertoire de départ, complétable à tout moment en
 * Administration ou à la volée depuis le formulaire de mouvement (bouton
 * "+", voir CLAUDE.md). firstOrCreate() : relancer ce seeder ne duplique
 * jamais la liste (même précaution que QuincaillerieTestSeeder) et ne
 * revient jamais sur un réglage fait depuis par le commerce.
 */
class MotifMouvementSeeder extends Seeder
{
    /**
     * Motif => est-ce une dépense ? (voir CLAUDE.md — « Dépenses »). Une
     * charge du commerce est cochée d'office ; ce qui ne fait que déplacer
     * de l'argent ne l'est pas : un appoint, un dépôt en banque, ou le
     * paiement d'un fournisseur — sa marchandise est déjà comptée dans le
     * coût des ventes, la compter en dépense la retirerait deux fois du
     * résultat.
     */
    public const MOTIFS = [
        // Entrées courantes
        'Appoint de caisse' => false,
        'Dépôt personnel' => false,
        'Remboursement divers' => false,
        'Avance sur salaire remboursée' => false,

        // Sorties courantes
        'Paiement fournisseur en espèces' => false,
        'Achat de fournitures de bureau' => true,
        'Frais de transport/livraison' => true,
        'Frais d\'électricité' => true,
        'Frais d\'eau' => true,
        'Frais de téléphone/internet' => true,
        'Paiement de salaire' => true,
        'Avance sur salaire' => true,
        'Frais d\'entretien/réparation' => true,
        'Frais de nettoyage' => true,
        'Loyer' => true,
        'Frais bancaires' => true,
        'Retrait pour dépôt bancaire' => false,
        'Petite dépense diverse' => true,
    ];

    public function run(): void
    {
        foreach (self::MOTIFS as $nom => $estDepense) {
            MotifMouvement::firstOrCreate(['nom' => $nom], ['actif' => true, 'est_depense' => $estDepense]);
        }
    }
}
