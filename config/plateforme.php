<?php

/*
|--------------------------------------------------------------------------
| Plateforme SaaS (branche multi-tenant)
|--------------------------------------------------------------------------
|
| Cette branche est TOUJOURS multi-client : chaque entreprise cliente a sa
| propre base de données et son sous-domaine ({espace}.{domaine}) ; le domaine
| nu sert le site vitrine, l'inscription et l'espace de l'éditeur. Il n'y a
| aucun interrupteur « mono / multi » : la version installée (un commerce par
| déploiement) vit sur la branche mono_client.
|
| Rien de ce fichier n'est à renseigner dans .env pour démarrer : le domaine
| se déduit de APP_URL, le reste a une valeur par défaut. Voir PLATEFORME.md.
|
*/

return [

    /*
    | Domaine principal de la plateforme, sans protocole ni port — par défaut
    | celui de APP_URL (« 360inmystore.com », « localhost » en développement).
    */
    'domaine' => env('PLATEFORME_DOMAINE') ?: (parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),

    /*
    | Préfixe des bases de données créées pour les entreprises
    | (ex. « ims_boutique_awa »). Le compte MySQL de l'application doit
    | pouvoir créer et utiliser les bases « {préfixe}% ».
    */
    'prefixe_base' => env('PLATEFORME_PREFIXE_BASE', 'ims_'),

    /*
    | Chemin de l'exécutable PHP en ligne de commande, utilisé pour préparer
    | la base d'une nouvelle entreprise dans un processus séparé. Laisser
    | vide pour une détection automatique.
    */
    'php' => env('PLATEFORME_PHP'),

    /*
    |--------------------------------------------------------------------------
    | Inscription en libre-service
    |--------------------------------------------------------------------------
    */
    'inscription' => [
        'ouverte' => (bool) env('PLATEFORME_INSCRIPTION_OUVERTE', true),

        // Garde-fou global contre une création massive d'espaces (chaque
        // inscription crée une base de données) : au-delà, l'inscription est
        // refusée jusqu'au lendemain, quelle que soit l'adresse IP.
        'max_par_jour' => (int) env('PLATEFORME_INSCRIPTIONS_MAX_PAR_JOUR', 50),

        // Temps minimal (secondes) entre l'affichage du formulaire et son
        // envoi : un robot remplit et envoie en une fraction de seconde.
        'delai_minimal' => 4,

        // Adresses d'espace interdites (réservées à la plateforme).
        'adresses_reservees' => [
            'www', 'app', 'api', 'admin', 'administration', 'plateforme', 'console', 'mail', 'smtp', 'ftp',
            'support', 'aide', 'help', 'demo', 'test', 'dev', 'staging', 'static', 'assets', 'cdn', 'inmystore',
        ],
    ],

    /*
    | Durée de validité (minutes) d'un lien d'accès direct à un espace : celui
    | qui suit une inscription, et celui que l'éditeur se crée pour entrer
    | dans l'espace d'un client en maintenance. Usage unique dans les deux cas.
    */
    'acces_minutes' => 5,

    /*
    |--------------------------------------------------------------------------
    | Paiement de l'abonnement
    |--------------------------------------------------------------------------
    |
    | « simulation » : aucun argent n'est encaissé — le paiement est accepté
    | (ou refusé, au choix du testeur) sans prestataire. Tant que ce pilote
    | est actif, n'importe quelle entreprise peut prolonger son abonnement
    | gratuitement : à remplacer par un vrai prestataire avant toute
    | commercialisation (voir App\Plateforme\Paiement\PasserellePaiement).
    | En attendant, l'éditeur enregistre les paiements reçus en direct depuis
    | sa console (« Activer un abonnement »).
    |
    */
    'paiement' => [
        'pilote' => env('PLATEFORME_PAIEMENT', 'simulation'),
    ],

];
