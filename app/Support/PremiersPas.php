<?php

namespace App\Support;

use App\Models\Caisse;
use App\Models\Categorie;
use App\Models\Magasin;
use App\Models\Produit;
use App\Models\Stock;
use App\Models\User;
use App\Models\Vente;
use Illuminate\Support\Facades\Cache;

/**
 * Mise en route d'un commerce : les étapes sans lesquelles on ne peut pas
 * vendre (un magasin, une caisse, des produits, du stock), jusqu'à la
 * première vente. Affichées sur le tableau de bord tant qu'il en reste —
 * un commerce qui démarre arrive sur une application vide, et rien d'autre
 * ne lui dit par où commencer.
 *
 * Chaque étape se déduit des données (jamais d'une case cochée à la main) et
 * porte l'action qui la fait avancer, selon les permissions de l'utilisateur.
 */
class PremiersPas
{
    private const CACHE_TERMINE = 'premiers-pas:termine';

    /**
     * null une fois la mise en route terminée — mémorisé sans limite de
     * durée : passé ce cap, le tableau de bord ne refait plus ces
     * vérifications à chaque affichage.
     *
     * @return list<array{titre: string, texte: string, fait: bool, action: ?array{libelle: string, url: string}}>|null
     */
    public static function pour(User $utilisateur): ?array
    {
        if (Cache::get(self::CACHE_TERMINE)) {
            return null;
        }

        $aUneCategorie = Categorie::exists();
        $peut = fn (string $permission) => $utilisateur->can($permission);
        $action = fn (bool $autorise, string $libelle, string $url) => $autorise ? ['libelle' => $libelle, 'url' => $url] : null;

        $etapes = [
            [
                'titre' => 'Créez votre magasin',
                'texte' => 'Le point de vente où vous encaissez. Vous pourrez en ajouter d\'autres, ainsi que des dépôts.',
                'fait' => Magasin::magasins()->exists(),
                'action' => $action($peut('administration.gerer'), 'Créer un magasin', route('magasins.create')),
            ],
            [
                'titre' => 'Ajoutez une caisse',
                'texte' => 'Chaque caisse est rattachée à un magasin ; c\'est sur elle que l\'on ouvre une session pour vendre.',
                'fait' => Caisse::exists(),
                'action' => $action($peut('administration.gerer'), 'Créer une caisse', route('caisses.create')),
            ],
            [
                'titre' => 'Enregistrez vos produits',
                'texte' => 'Classés par catégorie, avec leur prix et leurs unités de vente (pièce, carton, sac…). Vous pouvez aussi les importer depuis Excel (Catalogue → Produits → Importer).',
                'fait' => Produit::exists(),
                // Un produit a toujours une catégorie : la créer d'abord.
                'action' => $aUneCategorie
                    ? $action($peut('produit.creer'), 'Ajouter un produit', route('produits.create'))
                    : $action($peut('categorie.gerer'), 'Créer une catégorie', route('categories.create')),
            ],
            [
                'titre' => 'Entrez votre stock',
                'texte' => 'Par un achat chez un fournisseur, ou par un ajustement si la marchandise est déjà en rayon.',
                'fait' => Stock::where('quantite', '>', 0)->exists(),
                'action' => $action($peut('achat.creer'), 'Enregistrer un achat', route('commande-achats.create'))
                    ?? $action($peut('stock.ajuster'), 'Ajuster le stock', route('stock.mouvements.create')),
            ],
            [
                'titre' => 'Faites votre première vente',
                'texte' => 'Ouvrez une session sur une caisse libre, puis encaissez.',
                'fait' => Vente::exists(),
                'action' => $action($peut('caisse.ouvrir'), 'Ouvrir une caisse', route('sessions.index')),
            ],
        ];

        if (collect($etapes)->every('fait')) {
            Cache::forever(self::CACHE_TERMINE, true);

            return null;
        }

        return $etapes;
    }
}
