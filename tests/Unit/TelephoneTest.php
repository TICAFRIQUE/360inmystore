<?php

namespace Tests\Unit;

use App\Support\Telephone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TelephoneTest extends TestCase
{
    #[DataProvider('numerosValides')]
    public function test_un_numero_valide_est_enregistre_au_format_international(string $saisie, string $pays, string $attendu): void
    {
        $this->assertSame($attendu, Telephone::normaliser($saisie, $pays));
    }

    public static function numerosValides(): array
    {
        return [
            'Côte d\'Ivoire, 10 chiffres' => ['0700000001', 'CI', '+2250700000001'],
            'Côte d\'Ivoire, saisie avec espaces' => ['07 00 00 00 01', 'CI', '+2250700000001'],
            'Sénégal' => ['77 123 45 67', 'SN', '+221771234567'],
            'Nigeria, le 0 de tête national disparaît' => ['08031234567', 'NG', '+2348031234567'],
            'numéro complet avec + : le pays choisi est ignoré' => ['+33612345678', 'CI', '+33612345678'],
        ];
    }

    #[DataProvider('numerosInvalides')]
    public function test_un_numero_invalide_pour_le_pays_est_refuse(?string $saisie, string $pays): void
    {
        $this->assertNull(Telephone::normaliser($saisie, $pays));
    }

    public static function numerosInvalides(): array
    {
        return [
            'trop court' => ['0700', 'CI'],
            'ancien format ivoirien à 8 chiffres' => ['07000001', 'CI'],
            'numéro ivoirien avec le Sénégal choisi' => ['0700000001', 'SN'],
            'texte libre' => ['voir le gérant', 'CI'],
            'vide' => ['', 'CI'],
            'null' => [null, 'CI'],
        ];
    }

    public function test_seule_la_forme_internationale_est_acceptee_a_l_enregistrement(): void
    {
        $this->assertTrue(Telephone::estE164Valide('+2250700000001'));
        $this->assertFalse(Telephone::estE164Valide('0700000001'));
        $this->assertFalse(Telephone::estE164Valide('+225 07 00 00 00 01'));
        $this->assertFalse(Telephone::estE164Valide(null));
    }

    public function test_affichage_au_format_international(): void
    {
        $this->assertSame('+225 07 00 00 00 01', Telephone::formater('+2250700000001'));
        $this->assertSame('+221 77 123 45 67', Telephone::formater('+221771234567'));
        $this->assertSame('', Telephone::formater(null));
    }

    public function test_une_ancienne_saisie_libre_est_affichee_telle_quelle(): void
    {
        $this->assertSame('voir le gérant', Telephone::formater('voir le gérant'));
    }

    public function test_decomposition_pour_le_champ_de_saisie(): void
    {
        $this->assertSame(['pays' => 'CI', 'numero' => '07 00 00 00 01'], Telephone::decomposer('+2250700000001'));
        $this->assertSame(['pays' => 'SN', 'numero' => '77 123 45 67'], Telephone::decomposer('+221771234567'));
        // Pays absent du sélecteur : le numéro reste entier, avec son « + ».
        $this->assertSame('+33 6 12 34 56 78', Telephone::decomposer('+33612345678')['numero']);
    }

    public function test_tous_les_pays_proposes_ont_un_indicatif_et_un_exemple(): void
    {
        $options = Telephone::options();

        $this->assertCount(54, $options);
        foreach ($options as $option) {
            $this->assertMatchesRegularExpression('/^\+\d{1,3}$/', $option['indicatif'], $option['nom']);
            $this->assertNotSame('', $option['exemple'], $option['nom']);
        }
    }

    public function test_recherche_ramenee_aux_chiffres(): void
    {
        $this->assertSame('070000', Telephone::pourRecherche('07 00-00'));
        $this->assertSame('', Telephone::pourRecherche('Kouamé'));
    }
}
