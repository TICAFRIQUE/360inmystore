<?php

namespace App\Plateforme;

use App\Models\Plateforme\AccesEspace;
use App\Models\Plateforme\Entreprise;
use Illuminate\Support\Str;

/**
 * Liens d'accès direct à l'espace d'une entreprise : la plateforme (domaine
 * principal) en crée un, l'espace (son sous-domaine) le consomme et ouvre la
 * session. C'est le seul pont entre les deux — chaque sous-domaine a ses
 * propres sessions, une connexion ne se transmet pas de l'un à l'autre.
 *
 * Usage unique, courte durée (config plateforme.acces_minutes), et seule
 * l'empreinte du jeton est enregistrée.
 */
class LienAcces
{
    /**
     * @param  string  $motif  AccesEspace::INSCRIPTION ou AccesEspace::MAINTENANCE
     * @param  string|null  $auteur  éditeur à l'origine d'un accès de maintenance
     * @return string adresse à ouvrir, dans l'espace de l'entreprise
     */
    public function creer(Entreprise $entreprise, string $motif, ?string $auteur = null): string
    {
        $jeton = Str::random(64);

        AccesEspace::create([
            'entreprise_id' => $entreprise->id,
            'empreinte' => hash('sha256', $jeton),
            'motif' => $motif,
            'auteur' => $auteur,
            'expire_a' => now()->addMinutes((int) config('plateforme.acces_minutes')),
        ]);

        return $entreprise->url('acces/'.$jeton);
    }

    /**
     * À appeler depuis l'espace d'une entreprise : valide le jeton pour CET
     * espace et le marque utilisé, en une seule requête — deux ouvertures
     * simultanées du même lien ne peuvent pas réussir toutes les deux.
     */
    public function consommer(string $jeton): ?AccesEspace
    {
        $empreinte = hash('sha256', $jeton);

        $consomme = AccesEspace::query()
            ->where('empreinte', $empreinte)
            ->where('entreprise_id', plateforme()->entrepriseId())
            ->whereNull('utilise_a')
            ->where('expire_a', '>', now())
            ->update(['utilise_a' => now()]);

        return $consomme === 1 ? AccesEspace::where('empreinte', $empreinte)->first() : null;
    }
}
