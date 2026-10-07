<?php

namespace Tests;

use App\Models\Abonnement;
use App\Models\Plateforme\Entreprise;
use App\Models\User;
use App\Plateforme\Plateforme;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Les vues se rendent sans les assets compilés (absents en
        // intégration continue, où « npm run build » n'est pas lancé).
        $this->withoutVite();

        // La base de test sert à la fois de base de plateforme et de base
        // d'entreprise : les deux connexions doivent partager la même
        // session MySQL, sinon l'une ne voit pas ce que l'autre a écrit dans
        // la transaction du test.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::connection('plateforme')->setPdo(DB::getPdo())->setReadPdo(DB::getPdo());
        }
    }

    /**
     * Place le test dans l'espace d'une entreprise — le contexte par défaut
     * est le domaine principal de la plateforme, où les écrans de gestion
     * d'un commerce sont fermés. L'espace reçoit un abonnement sans limite
     * de durée (sinon il se réduirait à la page des tarifs), sauf demande
     * contraire.
     */
    protected function dansUnEspace(User $proprietaire, bool $avecAbonnement = true): Entreprise
    {
        $entreprise = Entreprise::create([
            'nom' => 'Commerce de test', 'sous_domaine' => 'commerce-test', 'base' => 'ims_commerce_test',
            'proprietaire_nom' => $proprietaire->name, 'telephone' => '+2250700000099', 'pays' => 'CI',
            'statut' => Entreprise::ACTIVE,
        ]);

        app(Plateforme::class)->definir(Plateforme::ENTREPRISE, $entreprise->id, $entreprise->sous_domaine);

        if ($avecAbonnement) {
            Abonnement::activer(formule: null, montant: 0, jours: null, illimite: true, note: 'Test', auteur: $proprietaire);
        }

        return $entreprise;
    }
}
