<?php

namespace App\Providers;

use App\Listeners\LogAuthentication;
use App\Models\Parametre;
use App\Models\SessionCaisse;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Le Superadmin contourne toutes les permissions ; il n'en reçoit aucune.
        Gate::before(fn (User $user) => $user->hasRole('Superadmin') ? true : null);

        // Laravel génère une pagination stylée Tailwind par défaut ; l'app utilise
        // Bootstrap, pas Tailwind, d'où un rendu cassé sans cette ligne.
        Paginator::useBootstrapFive();

        Event::listen(Login::class, [LogAuthentication::class, 'handleLogin']);
        Event::listen(Logout::class, [LogAuthentication::class, 'handleLogout']);
        Event::listen(Failed::class, [LogAuthentication::class, 'handleFailed']);

        // Logo, nom, slogan… configurables (voir ParametreController) : partagés
        // avec les layouts qui affichent la marque, plutôt que de repasser
        // Parametre::actuel() depuis chaque contrôleur.
        View::composer(['layouts.app', 'layouts.erreur', 'auth.login'], function ($view) {
            $view->with('parametre', Parametre::actuel());
        });

        // Session de caisse réellement active (ni clôturée ni fermée) du
        // caissier connecté — partagée avec le menu (sidebar) pour que
        // "Vente → Facture" mène directement à cette session (règle 7 : au
        // plus une session ouverte par caissier à la fois) plutôt que de
        // repasser par l'écran de sélection de caisse. Même double
        // condition que VenteController::ticket()/ClientController::show().
        View::composer('layouts.app', function ($view) {
            $view->with(
                'sessionCaissierOuverteMenu',
                auth()->check()
                    ? SessionCaisse::where('caissier_id', auth()->id())
                        ->whereNull('date_fermeture')
                        ->whereNull('date_cloture')
                        ->first()
                    : null
            );
        });
    }
}
