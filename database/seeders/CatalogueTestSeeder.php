<?php

namespace Database\Seeders;

use App\Enums\MouvementStockType;
use App\Models\Categorie;
use App\Models\Magasin;
use App\Models\Produit;
use App\Models\Unite;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Jeu de données de test : un catalogue de 120 produits répartis sur 10
 * familles et 30 catégories, volontairement de plusieurs secteurs
 * (alimentation, papeterie, électricité, bâtiment…), avec leurs unités de
 * vente (carton, sac, rouleau, palette…) et plusieurs unités de base (pièce,
 * kilo, litre, mètre, sac). De quoi essayer l'écran de caisse, la recherche,
 * les catégories et les alertes de stock avec du volume.
 *
 *   php artisan db:seed --class=CatalogueTestSeeder
 *
 * Peut être relancé sans risque : un produit déjà présent (même nom et même
 * libellé distinctif) n'est ni recréé ni réapprovisionné.
 *
 * Stock initial : si un magasin et un utilisateur existent, chaque nouveau
 * produit reçoit un stock dans chaque magasin et dépôt actif — toujours par
 * un mouvement (StockService, règle 1), jamais en écrivant une quantité. Un
 * produit sur onze est laissé en rupture et un sur sept sous son seuil, pour
 * avoir aussi ces cas à l'écran.
 */
class CatalogueTestSeeder extends Seeder
{
    /** @var array<string, Unite> */
    private array $unites = [];

    private int $compteur = 0;

    public function run(StockService $stockService): void
    {
        $this->creerUnites();

        $magasins = Magasin::where('actif', true)->orderBy('id')->get();
        // whereHas plutôt que le raccourci role() de Spatie, qui lève une
        // exception quand le rôle n'existe pas encore (base toute neuve).
        $auteur = User::whereHas('roles', fn ($q) => $q->where('name', 'Superadmin'))->first() ?? User::first();
        $crees = 0;

        foreach ($this->catalogue() as $prefixe => [$famille, $categories]) {
            $parent = Categorie::firstOrCreate(['nom' => $famille], ['actif' => true]);

            foreach ($categories as $nomCategorie => $produits) {
                $categorie = Categorie::firstOrCreate(['nom' => $nomCategorie], ['parent_id' => $parent->id, 'actif' => true]);

                foreach ($produits as [$nom, $libelle, $uniteBase, $prix, $seuil, $unitesVente]) {
                    $this->compteur++;

                    $produit = Produit::firstOrCreate(
                        ['nom' => $nom, 'libelle_distinctif' => $libelle],
                        [
                            'sku' => $this->reference($prefixe),
                            'code_barre' => $this->codeBarres(),
                            'categorie_id' => $categorie->id,
                            'prix_piece' => $prix,
                            'unite_base_id' => $this->unites[$uniteBase]->id,
                            'seuil_alerte' => $seuil,
                            'actif' => true,
                        ]
                    );

                    if (! $produit->wasRecentlyCreated) {
                        continue;
                    }

                    $crees++;

                    foreach ($unitesVente as [$unite, $facteur, $prixUnite]) {
                        $produit->uniteVentes()->create([
                            'unite_id' => $this->unites[$unite]->id,
                            'facteur' => $facteur,
                            'prix' => $prixUnite,
                            'actif' => true,
                        ]);
                    }

                    if ($auteur) {
                        foreach ($magasins as $magasin) {
                            $quantite = $this->quantiteInitiale($seuil, $magasin->estDepot());

                            if ($quantite > 0) {
                                $stockService->enregistrerMouvement(
                                    $produit, $magasin, $quantite, MouvementStockType::Reception, $auteur,
                                    motif: 'Stock initial (jeu de test)',
                                    // Coût d'achat fictif : 70 % du prix de vente.
                                    prixAchat: (int) round($prix * 0.7),
                                );
                            }
                        }
                    }
                }
            }
        }

        // Le catalogue de vente est mis en cache sans limite de durée : à
        // vider même si ce seeder est appelé sans les événements des modèles.
        Cache::forget(Produit::CACHE_CATALOGUE_VENTE);

        $this->command?->info("{$crees} produit(s) créé(s) sur {$this->compteur}.");

        if ($crees > 0 && (! $auteur || $magasins->isEmpty())) {
            $this->command?->warn("Aucun stock posé : il faut au moins un magasin et un utilisateur. Créez-les, puis entrez le stock par un achat ou un ajustement.");
        }
    }

    private function creerUnites(): void
    {
        foreach ([
            'Pièce' => 'pc', 'Kilogramme' => 'kg', 'Litre' => 'L', 'Mètre' => 'm',
            'Carton' => null, 'Sac' => null, 'Bidon' => null, 'Rouleau' => null, 'Boîte' => null,
            'Lot' => null, 'Paquet' => null, 'Pack' => null, 'Barre' => null, 'Palette' => null,
        ] as $nom => $abreviation) {
            $this->unites[$nom] = Unite::firstOrCreate(['nom' => $nom], ['abbreviation' => $abreviation, 'actif' => true]);
        }
    }

    /**
     * Référence lisible et stable (ALI-00001, BOI-00013…), pour essayer la
     * recherche par référence — jamais celle d'un produit existant.
     */
    private function reference(string $prefixe): string
    {
        $numero = $this->compteur;

        do {
            $reference = $prefixe.'-'.str_pad((string) $numero++, 5, '0', STR_PAD_LEFT);
        } while (Produit::withTrashed()->where('sku', $reference)->exists());

        return $reference;
    }

    /**
     * Code-barres EAN-13 valide (clé de contrôle comprise), pour essayer la
     * douchette. Préfixe 200 : plage réservée à l'usage interne d'un magasin,
     * qui ne correspond à aucun produit du commerce. null si le code est
     * déjà pris.
     */
    private function codeBarres(): ?string
    {
        $base = '200'.str_pad((string) $this->compteur, 9, '0', STR_PAD_LEFT);

        $somme = 0;
        foreach (str_split($base) as $position => $chiffre) {
            $somme += (int) $chiffre * ($position % 2 === 0 ? 1 : 3);
        }
        $code = $base.((10 - $somme % 10) % 10);

        return Produit::withTrashed()->where('code_barre', $code)->exists() ? null : $code;
    }

    /**
     * Quantité de départ, la même à chaque exécution : rupture pour un
     * produit sur onze, sous le seuil pour un sur sept, sinon de 3 à 9 fois
     * le seuil. Un dépôt reçoit le triple d'un magasin.
     */
    private function quantiteInitiale(int $seuil, bool $depot): int
    {
        $quantite = match (true) {
            $this->compteur % 11 === 0 => 0,
            $this->compteur % 7 === 0 => max(1, intdiv($seuil, 2)),
            default => $seuil * (3 + $this->compteur % 7),
        };

        return $depot ? $quantite * 3 : $quantite;
    }

    /**
     * préfixe de référence => [famille, [catégorie => [produits]]], un produit
     * étant [nom, libellé distinctif, unité de base, prix de l'unité de base,
     * seuil d'alerte, [[unité de vente, facteur, prix total], …]].
     *
     * Deux produits peuvent porter le même nom (règle 4 : seule la référence
     * identifie un produit) : c'est le libellé distinctif qui les sépare.
     *
     * @return array<string, array{0: string, 1: array<string, list<array{0: string, 1: ?string, 2: string, 3: int, 4: int, 5: list<array{0: string, 1: int, 2: int}>}>>}>
     */
    private function catalogue(): array
    {
        return [
            'ALI' => ['Alimentation', [
                'Épicerie sèche' => [
                    ['Riz parfumé', 'Sac de 5 kg', 'Sac', 4500, 20, [['Carton', 4, 17000], ['Palette', 80, 320000]]],
                    ['Riz brisé', null, 'Kilogramme', 600, 50, [['Sac', 25, 14000], ['Sac', 50, 27000]]],
                    ['Sucre en poudre', null, 'Kilogramme', 900, 40, [['Sac', 25, 21500], ['Sac', 50, 42000]]],
                    ['Pâtes alimentaires 500 g', null, 'Paquet', 600, 40, [['Carton', 20, 11000]]],
                ],
                'Conserves et sauces' => [
                    ['Concentré de tomate 70 g', null, 'Pièce', 150, 100, [['Carton', 50, 6800]]],
                    ['Sardines à l\'huile 125 g', null, 'Boîte', 650, 60, [['Carton', 50, 30000]]],
                    ['Huile végétale', 'Bouteille de 1 litre', 'Pièce', 1500, 30, [['Carton', 12, 17000]]],
                    ['Huile végétale', 'Bidon de 5 litres', 'Bidon', 7000, 10, [['Carton', 4, 27000]]],
                ],
                'Petit-déjeuner' => [
                    ['Lait en poudre 400 g', null, 'Boîte', 3200, 20, [['Carton', 12, 37000]]],
                    ['Lait concentré sucré 397 g', null, 'Boîte', 800, 48, [['Carton', 48, 36000]]],
                    ['Café soluble 50 g', null, 'Pièce', 1300, 24, [['Carton', 24, 29500]]],
                    ['Chocolat en poudre 400 g', null, 'Boîte', 2500, 15, []],
                ],
            ]],
            'BOI' => ['Boissons', [
                'Eaux' => [
                    ['Eau minérale 1,5 litre', null, 'Pièce', 500, 60, [['Pack', 6, 2700], ['Palette', 504, 210000]]],
                    ['Eau minérale 50 cl', null, 'Pièce', 250, 96, [['Pack', 12, 2600]]],
                    ['Eau en sachet 50 cl', null, 'Pièce', 25, 300, [['Sac', 30, 500]]],
                    ['Eau gazeuse 1 litre', null, 'Pièce', 900, 24, [['Pack', 6, 5000]]],
                ],
                'Jus et sodas' => [
                    ['Jus d\'orange 1 litre', null, 'Pièce', 1200, 24, [['Carton', 12, 13500]]],
                    ['Jus de bissap 33 cl', null, 'Pièce', 400, 48, [['Carton', 24, 9000]]],
                    ['Soda cola 33 cl', null, 'Pièce', 400, 72, [['Pack', 6, 2300], ['Carton', 24, 9000]]],
                    ['Boisson énergisante 25 cl', null, 'Pièce', 700, 48, [['Carton', 24, 15600]]],
                ],
                'Boissons chaudes' => [
                    ['Thé vert 100 g', null, 'Boîte', 1500, 20, [['Carton', 20, 28000]]],
                    ['Thé en sachets', 'Boîte de 25', 'Boîte', 1000, 24, [['Carton', 24, 22000]]],
                    ['Infusion kinkéliba 50 g', null, 'Paquet', 500, 30, []],
                    ['Café moulu 250 g', null, 'Paquet', 2800, 12, [['Carton', 12, 32000]]],
                ],
            ]],
            'ENT' => ['Entretien de la maison', [
                'Lessive et vaisselle' => [
                    ['Lessive en poudre 1 kg', null, 'Paquet', 2200, 20, [['Carton', 10, 20500]]],
                    ['Lessive en poudre 30 g', 'Dose', 'Pièce', 50, 300, [['Carton', 200, 9000]]],
                    ['Savon de ménage 250 g', null, 'Pièce', 500, 60, [['Carton', 24, 11000]]],
                    ['Liquide vaisselle 750 ml', null, 'Pièce', 1100, 24, [['Carton', 12, 12500]]],
                ],
                'Nettoyage' => [
                    ['Eau de javel 1 litre', null, 'Pièce', 800, 30, [['Carton', 12, 9000]]],
                    ['Nettoyant sols 1 litre', null, 'Pièce', 1500, 20, [['Carton', 12, 17000]]],
                    ['Désodorisant 300 ml', null, 'Pièce', 1800, 12, []],
                    ['Insecticide aérosol 300 ml', null, 'Pièce', 2000, 24, [['Carton', 12, 22500]]],
                ],
                'Accessoires de ménage' => [
                    ['Éponge double face', null, 'Pièce', 250, 50, [['Lot', 5, 1100]]],
                    ['Balai coco', null, 'Pièce', 1500, 15, []],
                    ['Serpillière', null, 'Pièce', 800, 20, [['Lot', 3, 2200]]],
                    ['Sac poubelle 50 litres', 'Rouleau de 20', 'Rouleau', 1200, 20, [['Carton', 25, 28000]]],
                ],
            ]],
            'HYG' => ['Hygiène et beauté', [
                'Soins du corps' => [
                    ['Savon de toilette 150 g', null, 'Pièce', 600, 48, [['Carton', 48, 27000]]],
                    ['Gel douche 500 ml', null, 'Pièce', 2200, 15, []],
                    ['Lait corporel 400 ml', null, 'Pièce', 3000, 12, [['Carton', 12, 34000]]],
                    ['Beurre de karité 250 g', null, 'Pièce', 2500, 10, []],
                ],
                'Cheveux' => [
                    ['Shampoing 400 ml', null, 'Pièce', 2500, 12, [['Carton', 12, 28500]]],
                    ['Après-shampoing 400 ml', null, 'Pièce', 2700, 12, []],
                    ['Huile capillaire 200 ml', null, 'Pièce', 2000, 12, []],
                    ['Mèches synthétiques', 'Noir', 'Paquet', 1500, 30, [['Lot', 10, 14000]]],
                ],
                'Hygiène' => [
                    ['Dentifrice 100 ml', null, 'Pièce', 900, 36, [['Carton', 36, 31000]]],
                    ['Brosse à dents', null, 'Pièce', 500, 40, [['Lot', 12, 5500]]],
                    ['Papier hygiénique', 'Rouleau', 'Pièce', 300, 96, [['Pack', 10, 2800], ['Carton', 100, 27000]]],
                    ['Couches bébé taille 3', 'Paquet de 30', 'Paquet', 5500, 10, [['Carton', 4, 21000]]],
                ],
            ]],
            'PAP' => ['Papeterie et bureau', [
                'Écriture' => [
                    ['Stylo bille', 'Bleu', 'Pièce', 150, 200, [['Boîte', 50, 6500]]],
                    ['Stylo bille', 'Noir', 'Pièce', 150, 200, [['Boîte', 50, 6500]]],
                    ['Crayon à papier hb', null, 'Pièce', 100, 150, [['Boîte', 12, 1100]]],
                    ['Marqueur permanent', 'Noir', 'Pièce', 600, 30, [['Boîte', 12, 6500]]],
                ],
                'Cahiers et papier' => [
                    ['Cahier 100 pages', null, 'Pièce', 600, 100, [['Paquet', 10, 5500]]],
                    ['Cahier 200 pages', null, 'Pièce', 1200, 80, [['Paquet', 10, 11000], ['Carton', 100, 105000]]],
                    ['Ramette de papier a4', '80 g, 500 feuilles', 'Pièce', 3500, 20, [['Carton', 5, 16500]]],
                    ['Bloc-notes a5', null, 'Pièce', 800, 30, []],
                ],
                'Classement' => [
                    ['Chemise cartonnée', null, 'Pièce', 150, 150, [['Paquet', 100, 12000]]],
                    ['Classeur à levier', 'Dos de 8 cm', 'Pièce', 1800, 20, [['Carton', 20, 34000]]],
                    ['Agrafeuse de bureau', null, 'Pièce', 2500, 8, []],
                    ['Agrafes 24/6', 'Boîte de 1000', 'Boîte', 300, 40, [['Carton', 20, 5500]]],
                ],
            ]],
            'ELE' => ['Électricité', [
                'Câbles et fils' => [
                    ['Câble électrique 1,5 mm²', 'Rigide', 'Mètre', 280, 200, [['Rouleau', 100, 26000]]],
                    ['Câble électrique 2,5 mm²', 'Rigide', 'Mètre', 350, 200, [['Rouleau', 100, 32000]]],
                    ['Câble souple 2 x 1,5 mm²', null, 'Mètre', 600, 100, [['Rouleau', 50, 28000]]],
                    ['Gaine annelée 16 mm', null, 'Mètre', 150, 200, [['Rouleau', 100, 13000]]],
                ],
                'Éclairage' => [
                    ['Ampoule led 9 watts', 'Culot à vis', 'Pièce', 1000, 40, [['Carton', 10, 9000]]],
                    ['Ampoule led 15 watts', 'Culot à vis', 'Pièce', 1500, 30, [['Carton', 10, 13500]]],
                    ['Réglette led 60 cm', null, 'Pièce', 4500, 10, []],
                    ['Lampe torche rechargeable', null, 'Pièce', 3500, 10, []],
                ],
                'Appareillage' => [
                    ['Interrupteur simple', null, 'Pièce', 1200, 20, [['Boîte', 10, 11000]]],
                    ['Prise de courant', 'Avec terre', 'Pièce', 1500, 20, [['Boîte', 10, 14000]]],
                    ['Multiprise 4 prises', null, 'Pièce', 4500, 10, []],
                    ['Disjoncteur 16 ampères', null, 'Pièce', 3800, 10, []],
                ],
            ]],
            'QUI' => ['Quincaillerie', [
                'Visserie' => [
                    ['Vis à bois 4 x 40 mm', null, 'Pièce', 25, 500, [['Boîte', 100, 2000], ['Boîte', 500, 9000]]],
                    ['Boulon m8 x 60 mm', null, 'Pièce', 150, 100, [['Boîte', 50, 6500]]],
                    ['Clou acier 70 mm', null, 'Kilogramme', 800, 20, [['Sac', 5, 3800], ['Carton', 25, 18000]]],
                    ['Cheville plastique 8 mm', null, 'Pièce', 15, 500, [['Boîte', 100, 1200]]],
                ],
                'Outillage' => [
                    ['Marteau de menuisier 500 g', null, 'Pièce', 3500, 8, []],
                    ['Tournevis cruciforme', null, 'Pièce', 1200, 15, [['Lot', 6, 6500]]],
                    ['Mètre ruban 5 m', null, 'Pièce', 1500, 15, []],
                    ['Pince multiprise', null, 'Pièce', 4200, 8, []],
                ],
                'Plomberie' => [
                    ['Tuyau pvc 32 mm', 'Alimentation', 'Mètre', 450, 60, [['Barre', 4, 1700]]],
                    ['Tuyau pvc 100 mm', 'Évacuation', 'Mètre', 1200, 40, [['Barre', 4, 4500]]],
                    ['Coude pvc 100 mm', null, 'Pièce', 800, 20, []],
                    ['Robinet d\'arrêt', null, 'Pièce', 2500, 10, []],
                ],
            ]],
            'TEL' => ['Téléphonie et accessoires', [
                'Chargeurs et câbles' => [
                    ['Chargeur secteur usb', null, 'Pièce', 2500, 20, [['Carton', 20, 46000]]],
                    ['Câble de charge usb-c', '1 mètre', 'Pièce', 1500, 30, [['Lot', 10, 13500]]],
                    ['Câble de charge micro-usb', '1 mètre', 'Pièce', 1000, 30, [['Lot', 10, 9000]]],
                    ['Batterie externe 10000 mah', null, 'Pièce', 9500, 8, []],
                ],
                'Audio' => [
                    ['Écouteurs filaires', null, 'Pièce', 1500, 25, [['Lot', 10, 13500]]],
                    ['Écouteurs sans fil', null, 'Pièce', 8500, 10, []],
                    ['Enceinte portable', null, 'Pièce', 15000, 5, []],
                    ['Kit mains libres', null, 'Pièce', 2000, 15, []],
                ],
                'Protection' => [
                    ['Coque de protection', 'Transparente', 'Pièce', 1500, 30, [['Lot', 10, 12500]]],
                    ['Verre trempé', null, 'Pièce', 1000, 40, [['Lot', 10, 8500]]],
                    ['Étui portefeuille', 'Noir', 'Pièce', 3000, 15, []],
                    ['Support de téléphone', 'Pour voiture', 'Pièce', 3500, 10, []],
                ],
            ]],
            'TEX' => ['Textile et maison', [
                'Linge de maison' => [
                    ['Drap plat', '2 places', 'Pièce', 6500, 10, []],
                    ['Serviette de bain', null, 'Pièce', 3500, 15, [['Lot', 3, 9500]]],
                    ['Torchon de cuisine', null, 'Pièce', 700, 30, [['Lot', 6, 3800]]],
                    ['Tissu pagne', null, 'Mètre', 1500, 60, [['Rouleau', 6, 8500], ['Rouleau', 12, 16000]]],
                ],
                'Vêtements' => [
                    ['Tee-shirt coton', 'Blanc', 'Pièce', 2500, 20, [['Lot', 3, 6900]]],
                    ['Tee-shirt coton', 'Noir', 'Pièce', 2500, 20, [['Lot', 3, 6900]]],
                    ['Chaussettes', 'Lot de 3 paires', 'Lot', 1500, 20, [['Carton', 20, 27000]]],
                    ['Casquette', null, 'Pièce', 2000, 15, []],
                ],
                'Chaussures' => [
                    ['Sandales plastique', 'Pointure 40', 'Pièce', 1500, 20, [['Carton', 24, 33000]]],
                    ['Sandales plastique', 'Pointure 42', 'Pièce', 1500, 20, [['Carton', 24, 33000]]],
                    ['Baskets toile', 'Pointure 41', 'Pièce', 9500, 6, []],
                    ['Tongs', 'Pointure 38', 'Pièce', 1000, 24, [['Carton', 24, 21500]]],
                ],
            ]],
            'BAT' => ['Bâtiment', [
                'Ciment et liants' => [
                    ['Ciment', 'Sac de 50 kg', 'Sac', 5500, 40, [['Palette', 40, 212000]]],
                    ['Chaux hydraulique', 'Sac de 25 kg', 'Sac', 4200, 15, []],
                    ['Plâtre', null, 'Kilogramme', 250, 100, [['Sac', 25, 5800], ['Sac', 40, 9000]]],
                    ['Colle à carrelage', 'Sac de 25 kg', 'Sac', 4800, 15, [['Palette', 48, 220000]]],
                ],
                'Fers et métaux' => [
                    ['Fer à béton 8 mm', null, 'Mètre', 450, 120, [['Barre', 12, 5200]]],
                    ['Fer à béton 10 mm', null, 'Mètre', 650, 120, [['Barre', 12, 7500]]],
                    ['Fil de fer recuit', null, 'Kilogramme', 1100, 20, [['Rouleau', 25, 26000]]],
                    ['Tôle ondulée', '2 mètres', 'Pièce', 4500, 20, [['Lot', 10, 43000]]],
                ],
                'Peinture' => [
                    ['Peinture acrylique', 'Blanc', 'Litre', 3200, 20, [['Bidon', 5, 15000], ['Bidon', 20, 56000]]],
                    ['Peinture acrylique', 'Gris', 'Litre', 3300, 20, [['Bidon', 5, 15500]]],
                    ['Peinture à l\'huile', 'Blanc', 'Litre', 3500, 20, [['Bidon', 5, 16000]]],
                    ['Rouleau à peinture 18 cm', null, 'Pièce', 1500, 15, []],
                ],
            ]],
        ];
    }
}
