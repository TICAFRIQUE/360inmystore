<?php

namespace Database\Seeders;

use App\Models\MotifMouvement;
use Illuminate\Database\Seeder;

/**
 * Motifs courants d'entrée/sortie de caisse ou de trésorerie d'un commerce,
 * quel que soit son secteur — répertoire de départ, complétable à tout moment en
 * Administration ou à la volée depuis le formulaire de mouvement (bouton
 * "+", voir CLAUDE.md). firstOrCreate() : relancer ce seeder ne duplique
 * jamais la liste (même précaution que QuincaillerieTestSeeder).
 */
class MotifMouvementSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            // Entrées courantes
            'Appoint de caisse',
            'Dépôt personnel',
            'Remboursement divers',
            'Avance sur salaire remboursée',

            // Sorties courantes
            'Paiement fournisseur en espèces',
            'Achat de fournitures de bureau',
            'Frais de transport/livraison',
            'Frais d\'électricité',
            'Frais d\'eau',
            'Frais de téléphone/internet',
            'Paiement de salaire',
            'Avance sur salaire',
            'Frais d\'entretien/réparation',
            'Frais de nettoyage',
            'Loyer',
            'Frais bancaires',
            'Retrait pour dépôt bancaire',
            'Petite dépense diverse',
        ] as $nom) {
            MotifMouvement::firstOrCreate(['nom' => $nom], ['actif' => true]);
        }
    }
}
