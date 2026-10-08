<?php

namespace Database\Seeders;

use App\Enums\EcritureCompteTresorerieType;
use App\Models\EcritureCompteTresorerie;
use App\Models\Magasin;
use App\Models\MotifMouvement;
use App\Models\User;
use App\Services\CompteTresorerieService;
use App\Services\DepenseService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Jeu de données de test : 30 dépenses courantes d'un commerce, quel que
 * soit son secteur (loyer, salaires, eau, électricité, transport…),
 * réparties du début du mois à aujourd'hui. De quoi essayer l'écran
 * « Dépenses » (récapitulatif par motif, filtres, pagination, exports) et la
 * carte « Résultat » du tableau de bord avec du volume.
 *
 *   php artisan db:seed --class=DepenseTestSeeder
 *
 * Chaque dépense passe par DepenseService, comme à l'écran : elle sort
 * réellement de la Caisse Générale. Pour que celle-ci puisse les payer, un
 * apport du même total y est d'abord déposé — son solde est donc le même
 * avant et après. Cet apport sert aussi de témoin : relancé, le seeder ne
 * crée rien de plus.
 *
 * Refusé en production : ce sont de fausses écritures, et une écriture de
 * trésorerie ne se supprime pas.
 */
class DepenseTestSeeder extends Seeder
{
    public const MOTIF_APPORT = 'Apport pour le jeu d\'essai des dépenses';

    /**
     * [motif de départ (MotifMouvementSeeder), montant, bénéficiaire, pour un magasin ?]
     * Dans l'ordre où elles sont payées, de la plus ancienne à la plus récente.
     *
     * @var list<array{0: string, 1: int, 2: ?string, 3: bool}>
     */
    private const DEPENSES = [
        ['Loyer', 150000, 'Propriétaire du local', true],
        ['Frais de transport/livraison', 6000, 'Livreur', true],
        ['Achat de fournitures de bureau', 9500, 'Papeterie du quartier', false],
        ['Avance sur salaire', 20000, 'Vendeur', true],
        ['Petite dépense diverse', 2000, null, true],
        ['Frais de nettoyage', 5000, 'Agent d\'entretien', true],
        ['Frais de téléphone/internet', 15000, 'Opérateur internet', false],
        ['Frais de transport/livraison', 3500, 'Taxi', true],
        ['Frais d\'entretien/réparation', 25000, 'Électricien', true],
        ['Frais bancaires', 2500, 'Banque', false],
        ['Frais d\'électricité', 42500, 'Compagnie d\'électricité', true],
        ['Petite dépense diverse', 1500, null, true],
        ['Frais de transport/livraison', 8000, 'Transporteur', false],
        ['Achat de fournitures de bureau', 4000, 'Papeterie du quartier', true],
        ['Frais d\'eau', 12500, 'Compagnie des eaux', true],
        ['Avance sur salaire', 15000, 'Caissière', true],
        ['Frais d\'entretien/réparation', 8500, 'Plombier', true],
        ['Frais de téléphone/internet', 10000, 'Opérateur téléphonique', false],
        ['Frais de transport/livraison', 4500, 'Livreur', true],
        ['Frais de nettoyage', 5000, 'Agent d\'entretien', true],
        ['Frais d\'électricité', 18000, 'Compagnie d\'électricité', true],
        ['Achat de fournitures de bureau', 12000, 'Imprimeur', false],
        ['Petite dépense diverse', 3500, null, true],
        ['Frais d\'entretien/réparation', 14000, 'Réparateur', true],
        ['Frais de transport/livraison', 2500, 'Taxi', true],
        ['Frais bancaires', 1500, 'Banque', false],
        ['Frais de téléphone/internet', 5000, 'Recharge téléphonique', true],
        ['Paiement de salaire', 120000, 'Vendeur', true],
        ['Paiement de salaire', 90000, 'Caissière', true],
        ['Paiement de salaire', 75000, 'Magasinier', false],
    ];

    public function run(DepenseService $depenses, CompteTresorerieService $tresorerie): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Jeu d\'essai des dépenses refusé en production.');

            return;
        }

        if (EcritureCompteTresorerie::where('motif', self::MOTIF_APPORT)->exists()) {
            $this->command?->info('Jeu d\'essai des dépenses déjà en place : rien à ajouter.');

            return;
        }

        // whereHas plutôt que le raccourci role() de Spatie, qui lève une
        // exception quand le rôle n'existe pas encore (base toute neuve).
        $auteur = User::whereHas('roles', fn ($q) => $q->where('name', 'Superadmin'))->first() ?? User::first();

        if ($auteur === null) {
            $this->command?->warn('Aucun utilisateur : créez d\'abord un compte, les dépenses ont besoin d\'un auteur.');

            return;
        }

        // Les motifs de départ, s'ils manquent ; ceux que le commerce a
        // décochés depuis ne sont pas recochés — leurs dépenses sont sautées.
        $this->call(MotifMouvementSeeder::class);
        $motifs = MotifMouvement::where('est_depense', true)->where('actif', true)->get()->keyBy('nom');
        $prevues = collect(self::DEPENSES)->filter(fn (array $depense) => $motifs->has($depense[0]))->values();

        if ($prevues->isEmpty()) {
            $this->command?->warn('Aucun motif de dépense actif : cochez « C\'est une dépense » sur au moins un motif.');

            return;
        }

        $caisseGenerale = $tresorerie->caisseGenerale();
        $magasins = Magasin::where('type', 'magasin')->where('actif', true)->orderBy('id')->get();

        // Du début du mois à maintenant, à intervalles réguliers : toutes
        // visibles sur la période par défaut de l'écran, jamais dans le futur.
        $fin = Carbon::now();
        $debut = $fin->copy()->startOfMonth()->addHours(8)->min($fin);
        $pas = $debut->diffInSeconds($fin) / $prevues->count();

        try {
            Carbon::setTestNow($debut);
            $tresorerie->crediter($caisseGenerale, $prevues->sum(fn (array $depense) => $depense[1]), EcritureCompteTresorerieType::EntreeManuelle, $auteur, motif: self::MOTIF_APPORT);

            foreach ($prevues as $rang => [$motif, $montant, $beneficiaire, $pourUnMagasin]) {
                Carbon::setTestNow($debut->copy()->addSeconds((int) round($pas * ($rang + 1))));

                $magasin = $pourUnMagasin && $magasins->isNotEmpty() ? $magasins[$rang % $magasins->count()] : null;
                $depenses->payerDepuisCompte($caisseGenerale, $motifs[$motif], $montant, $auteur, $beneficiaire, $magasin);
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->command?->info($prevues->count().' dépenses de test enregistrées, payées depuis la Caisse Générale.');
    }
}
