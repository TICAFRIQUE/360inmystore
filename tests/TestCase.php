<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Les vues se rendent sans les assets compilés (absents en
        // intégration continue, où « npm run build » n'est pas lancé).
        $this->withoutVite();
    }
}
