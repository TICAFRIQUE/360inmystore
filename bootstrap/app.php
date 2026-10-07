<?php

use App\Http\Middleware\AssureAbonnementActif;
use App\Http\Middleware\AssureGestionnaireAbonnement;
use App\Http\Middleware\Plateforme\BloqueEspaceIndisponible;
use App\Http\Middleware\Plateforme\ReserveALaPlateforme;
use App\Http\Middleware\Plateforme\ReserveAuxCommerces;
use App\Http\Middleware\Plateforme\VerifieSessionEntreprise;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cookie signal de fin de téléchargement (voir ParametreController::backup()
        // et resources/js/app.js) : doit rester lisible/comparable tel quel côté
        // client (document.cookie === '1'), donc exclu du chiffrement automatique.
        $middleware->encryptCookies(except: ['telechargement_pret']);

        // Plateforme (voir CLAUDE.md « Plateforme SaaS ») : un espace inconnu
        // ou fermé est arrêté avant tout le reste, et une session ne vaut que
        // pour l'espace où elle a été ouverte.
        $middleware->prepend(BloqueEspaceIndisponible::class);
        $middleware->web(append: [VerifieSessionEntreprise::class]);

        $middleware->alias([
            'abonnement.actif' => AssureAbonnementActif::class,
            'abonnement.gestion' => AssureGestionnaireAbonnement::class,
            'plateforme.centrale' => ReserveALaPlateforme::class,
            'espace.commerce' => ReserveAuxCommerces::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
