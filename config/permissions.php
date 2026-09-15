<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catalogue des permissions
    |--------------------------------------------------------------------------
    |
    | Convention de nommage : module.action. Source de vérité utilisée par
    | RolePermissionSeeder pour créer les permissions spatie/laravel-permission.
    |
    */
    'catalogue' => [
        'produit.voir',
        'produit.creer',
        'produit.modifier',
        'produit.supprimer',
        'categorie.gerer',
        'stock.voir',
        'stock.ajuster',
        'stock.transferer',
        'inventaire.voir',
        'inventaire.realiser',
        'inventaire.valider',
        'achat.voir',
        'achat.creer',
        'achat.valider',
        'achat.receptionner',
        'achat.annuler',
        'achat.retour',
        'fournisseur.voir',
        'fournisseur.gerer',
        'fournisseur.reglement',
        'taxe.gerer',
        'devise.gerer',
        'vente.voir',
        'vente.creer',
        'vente.remise',
        'vente.credit',
        'vente.signaler',
        'vente.annuler',
        'vente.retour',
        'vente.livrer',
        'vente.bon_livraison',
        'ventenattente.gerer',
        'client.voir',
        'client.gerer',
        'client.reglement',
        'client.depasser_limite',
        'typeclient.gerer',
        'devis.voir',
        'devis.gerer',
        'devis.transformer',
        'caisse.ouvrir',
        'caisse.cloturer',
        'caisse.fermer',
        'caisse.gerer',
        'caisse.mouvement',
        'tresorerie.voir',
        'tresorerie.gerer',
        'motif.gerer',
        'rapport.voir',
        'utilisateur.gerer',
        'role.gerer',
        'parametre.gerer',
        'administration.gerer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Noyau de permissions protégé
    |--------------------------------------------------------------------------
    |
    | Jamais proposées à l'attribution sur les rôles créés à la volée, quel que
    | soit qui édite le rôle (même Superadmin) — voir RoleController. Décision
    | volontairement code-owned : la protection elle-même ne doit pas être
    | modifiable via une simple UPDATE en base.
    |
    */
    'protected' => [
        'parametre.gerer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Réservé à Superadmin
    |--------------------------------------------------------------------------
    |
    | Attribuables à un rôle créé à la volée, mais uniquement visibles/
    | cochables quand c'est Superadmin qui crée/modifie le rôle — un Gérant
    | (qui a accès à cet écran via role.gerer) ne les voit pas. Risque de
    | délégation en cascade sinon : un rôle avec role.gerer pourrait créer
    | d'autres rôles avec n'importe quelle permission, utilisateur.gerer
    | pourrait créer de nouveaux comptes admin.
    |
    */
    'superadmin_only' => [
        'role.gerer',
        'utilisateur.gerer',
    ],

];
