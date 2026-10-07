<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ReinitialiserCodeTest extends TestCase
{
    use RefreshDatabase;

    private function compte(bool $actif = true): User
    {
        return User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@example.test', 'password' => '1234', 'actif' => $actif,
        ]);
    }

    public function test_le_nouveau_code_remplace_l_ancien_et_l_action_est_tracee(): void
    {
        $compte = $this->compte();

        $this->artisan('app:reinitialiser-code', ['username' => 'admin'])
            ->expectsQuestion('Nouveau code de connexion (4 chiffres ou plus)', '987654')
            ->expectsQuestion('Confirmer le code', '987654')
            ->assertSuccessful();

        $empreinte = $compte->fresh()->password;
        $this->assertTrue(Hash::check('987654', $empreinte));
        $this->assertFalse(Hash::check('1234', $empreinte));

        $trace = Activity::where('subject_type', User::class)->where('subject_id', $compte->id)->latest('id')->first();
        $this->assertStringContainsString('réinitialisé en ligne de commande', $trace->description);
        $this->assertStringNotContainsString('987654', json_encode($trace->toArray()));
    }

    public function test_un_compte_inconnu_est_refuse(): void
    {
        $this->compte();

        $this->artisan('app:reinitialiser-code', ['username' => 'inconnu'])->assertFailed();
    }

    public function test_deux_saisies_differentes_ne_changent_rien(): void
    {
        $compte = $this->compte();

        $this->artisan('app:reinitialiser-code', ['username' => 'admin'])
            ->expectsQuestion('Nouveau code de connexion (4 chiffres ou plus)', '987654')
            ->expectsQuestion('Confirmer le code', '987655')
            ->assertFailed();

        $this->assertTrue(Hash::check('1234', $compte->fresh()->password));
    }

    public function test_un_code_trop_court_est_refuse(): void
    {
        $compte = $this->compte();

        $this->artisan('app:reinitialiser-code', ['username' => 'admin'])
            ->expectsQuestion('Nouveau code de connexion (4 chiffres ou plus)', '12')
            ->expectsQuestion('Confirmer le code', '12')
            ->assertFailed();

        $this->assertTrue(Hash::check('1234', $compte->fresh()->password));
    }

    public function test_un_compte_desactive_est_signale(): void
    {
        $this->compte(actif: false);

        $this->artisan('app:reinitialiser-code', ['username' => 'admin'])
            ->expectsQuestion('Nouveau code de connexion (4 chiffres ou plus)', '987654')
            ->expectsQuestion('Confirmer le code', '987654')
            ->expectsOutputToContain('Ce compte est désactivé')
            ->assertSuccessful();
    }
}
