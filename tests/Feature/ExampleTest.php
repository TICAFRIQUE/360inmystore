<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sur le domaine principal, la racine est le site vitrine, ouvert à
     * tous ; l'application elle-même exige une session authentifiée.
     */
    public function test_un_visiteur_voit_le_site_vitrine_mais_pas_l_application(): void
    {
        $this->get('/')->assertOk()->assertSee('Créer un compte');
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
