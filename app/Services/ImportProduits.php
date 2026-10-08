<?php

namespace App\Services;

use App\Enums\MouvementStockType;
use App\Models\Categorie;
use App\Models\Magasin;
use App\Models\Produit;
use App\Models\Unite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Import d'un catalogue de produits depuis un fichier Excel ou CSV : saisir
 * des centaines de produits un par un est le premier frein au démarrage d'un
 * commerce.
 *
 * Tout ou rien : le fichier est d'abord lu et vérifié en entier ; à la
 * moindre erreur, rien n'est enregistré et chaque ligne fautive est signalée
 * avec son numéro. Les catégories et les unités absentes sont créées. Un
 * produit existant n'est jamais modifié : une référence déjà prise est une
 * erreur.
 *
 * Le stock initial, s'il est renseigné, entre par un mouvement de stock
 * (StockService, règle 1) — jamais par une quantité écrite directement.
 */
class ImportProduits
{
    /** Nombre maximal de lignes d'un fichier. */
    public const LIGNES_MAX = 2000;

    /**
     * Colonnes du fichier : clé interne => intitulé. L'ordre des colonnes du
     * fichier est libre ; elles sont reconnues à leur intitulé, sans tenir
     * compte de la casse ni des accents.
     */
    public const COLONNES = [
        'nom' => 'Nom',
        'libelle' => 'Libellé distinctif',
        'reference' => 'Référence',
        'code_barre' => 'Code-barres',
        'categorie' => 'Catégorie',
        'sous_categorie' => 'Sous-catégorie',
        'unite_base' => 'Unité de base',
        'prix' => 'Prix de vente',
        'seuil' => "Seuil d'alerte",
        'unite_1' => 'Unité de vente 1',
        'quantite_1' => 'Quantité par unité 1',
        'prix_1' => 'Prix unité 1',
        'unite_2' => 'Unité de vente 2',
        'quantite_2' => 'Quantité par unité 2',
        'prix_2' => 'Prix unité 2',
        'stock' => 'Stock initial',
        'prix_achat' => "Prix d'achat",
    ];

    private const OBLIGATOIRES = ['nom', 'categorie', 'prix'];

    public function __construct(private StockService $stockService) {}

    /**
     * Modèle à remplir : une feuille « Produits » aux seuls intitulés, et une
     * feuille « Exemple » qui montre trois lignes remplies. Seule la première
     * feuille est lue à l'import.
     */
    public function modele(): Spreadsheet
    {
        $classeur = new Spreadsheet;
        $intitules = array_values(self::COLONNES);

        $produits = $classeur->getActiveSheet()->setTitle('Produits');
        $produits->fromArray($intitules, null, 'A1');

        $exemple = $classeur->createSheet()->setTitle('Exemple');
        $exemple->fromArray([
            $intitules,
            ['Riz parfumé', 'Sac de 5 kg', '', '', 'Alimentation', 'Épicerie sèche', 'Sac', 4500, 20, 'Carton', 4, 17000, '', '', '', 60, 3200],
            ['Câble électrique 2,5 mm²', '', 'ELE-001', '', 'Électricité', '', 'Mètre', 350, 200, 'Rouleau', 100, 32000, '', '', '', '', ''],
            ['Stylo bille', 'Bleu', '', '6191234567890', 'Papeterie', 'Écriture', 'Pièce', 150, 100, 'Boîte', 50, 6500, 'Carton', 1000, 120000, 500, 90],
        ], null, 'A1');

        foreach ([$produits, $exemple] as $feuille) {
            $feuille->getStyle('A1:Q1')->getFont()->setBold(true);
            foreach (range('A', 'Q') as $colonne) {
                $feuille->getColumnDimension($colonne)->setAutoSize(true);
            }
        }

        return $classeur;
    }

    /**
     * Lit et vérifie le fichier, sans rien enregistrer.
     *
     * @return array{lignes: list<array<string, mixed>>, erreurs: list<string>}
     */
    public function analyser(string $cheminFichier, bool $magasinChoisi): array
    {
        try {
            $tableau = IOFactory::load($cheminFichier)->getSheet(0)->toArray(null, true, false, false);
        } catch (\Throwable) {
            return ['lignes' => [], 'erreurs' => ["Ce fichier n'a pas pu être lu : envoyez un classeur Excel (.xlsx) ou un fichier CSV."]];
        }

        $entete = array_map(fn ($cellule) => self::cle((string) $cellule), array_shift($tableau) ?? []);
        $positions = [];
        foreach (self::COLONNES as $cle => $intitule) {
            $position = array_search(self::cle($intitule), $entete, true);
            if ($position !== false) {
                $positions[$cle] = $position;
            }
        }

        $manquantes = array_diff(self::OBLIGATOIRES, array_keys($positions));
        if ($manquantes) {
            return ['lignes' => [], 'erreurs' => [
                'Colonne(s) introuvable(s) dans la première ligne du fichier : '
                .implode(', ', array_map(fn (string $cle) => '« '.self::COLONNES[$cle].' »', $manquantes))
                .'. Partez du modèle à télécharger.',
            ]];
        }

        $lignes = [];
        $erreurs = [];

        // Ce qui existe déjà, pour refuser les doublons avec la base…
        $referencesPrises = Produit::withTrashed()->pluck('sku')->map(fn ($v) => mb_strtolower((string) $v))->flip();
        $codesPris = Produit::withTrashed()->whereNotNull('code_barre')->pluck('code_barre')->map(fn ($v) => mb_strtolower((string) $v))->flip();
        $nomsPris = [];
        foreach (Produit::query()->get(['nom', 'libelle_distinctif']) as $produit) {
            $nomsPris[mb_strtolower($produit->nom)][mb_strtolower((string) $produit->libelle_distinctif)] = true;
        }

        foreach ($tableau as $index => $cellules) {
            $numero = $index + 2; // la ligne 1 porte les intitulés
            $valeur = fn (string $cle) => isset($positions[$cle]) ? trim((string) ($cellules[$positions[$cle]] ?? '')) : '';

            if (collect(array_keys(self::COLONNES))->every(fn (string $cle) => $valeur($cle) === '')) {
                continue; // ligne vide
            }

            if (count($lignes) >= self::LIGNES_MAX) {
                $erreurs[] = 'Le fichier dépasse '.self::LIGNES_MAX.' produits : découpez-le en plusieurs fichiers.';
                break;
            }

            $probleme = function (string $message) use (&$erreurs, $numero) {
                $erreurs[] = "Ligne {$numero} : {$message}";
            };

            $ligne = [
                'nom' => $valeur('nom'),
                'libelle' => $valeur('libelle') ?: null,
                'reference' => $valeur('reference') ?: null,
                'code_barre' => $valeur('code_barre') ?: null,
                'categorie' => $valeur('categorie'),
                'sous_categorie' => $valeur('sous_categorie') ?: null,
                'unite_base' => $valeur('unite_base') ?: 'Pièce',
                'prix' => self::entier($valeur('prix')),
                'seuil' => $valeur('seuil') === '' ? 0 : self::entier($valeur('seuil')),
                'unites' => [],
                'stock' => $valeur('stock') === '' ? 0.0 : self::nombre($valeur('stock')),
                'prix_achat' => $valeur('prix_achat') === '' ? null : self::entier($valeur('prix_achat')),
            ];

            if ($ligne['nom'] === '') {
                $probleme('le nom est obligatoire.');
            }
            if ($ligne['categorie'] === '') {
                $probleme('la catégorie est obligatoire.');
            }
            if ($ligne['prix'] === null) {
                $probleme('le prix de vente doit être un nombre entier, sans décimale (« '.$valeur('prix').' »).');
            }
            if ($ligne['seuil'] === null) {
                $probleme("le seuil d'alerte doit être un nombre entier (« ".$valeur('seuil').' »).');
            }

            foreach ([1, 2] as $i) {
                $unite = $valeur("unite_{$i}");
                if ($unite === '' && $valeur("quantite_{$i}") === '' && $valeur("prix_{$i}") === '') {
                    continue;
                }

                $facteur = self::entier($valeur("quantite_{$i}"));
                $prixUnite = self::entier($valeur("prix_{$i}"));

                if ($unite === '') {
                    $probleme("l'unité de vente {$i} n'a pas de nom.");
                }
                if ($facteur === null || $facteur < 2) {
                    $probleme("la quantité par unité {$i} doit être un nombre entier d'au moins 2 (« ".$valeur("quantite_{$i}").' »).');
                }
                if ($prixUnite === null) {
                    $probleme("le prix de l'unité {$i} doit être un nombre entier (« ".$valeur("prix_{$i}").' »).');
                }

                $ligne['unites'][] = ['unite' => $unite, 'facteur' => $facteur, 'prix' => $prixUnite];
            }

            if (count($ligne['unites']) === 2 && $ligne['unites'][0]['facteur'] === $ligne['unites'][1]['facteur']
                && self::cle($ligne['unites'][0]['unite']) === self::cle($ligne['unites'][1]['unite'])) {
                $probleme('les deux unités de vente sont identiques.');
            }

            if ($ligne['stock'] === null || $ligne['stock'] < 0) {
                $probleme('le stock initial doit être un nombre positif (« '.$valeur('stock').' »).');
            } elseif ($ligne['stock'] > 0) {
                if (! $magasinChoisi) {
                    $probleme('un stock initial est renseigné : choisissez le magasin ou le dépôt qui le reçoit.');
                }
                if ($ligne['prix_achat'] === null) {
                    $probleme("un stock initial demande un prix d'achat (nombre entier), pour le calcul des marges.");
                }
            }

            // … et les doublons, avec la base comme entre les lignes du fichier.
            if ($ligne['reference'] !== null) {
                $cle = mb_strtolower($ligne['reference']);
                if (isset($referencesPrises[$cle])) {
                    $probleme("la référence « {$ligne['reference']} » est déjà utilisée.");
                }
                $referencesPrises[$cle] = true;
            }
            if ($ligne['code_barre'] !== null) {
                $cle = mb_strtolower($ligne['code_barre']);
                if (isset($codesPris[$cle])) {
                    $probleme("le code-barres « {$ligne['code_barre']} » est déjà utilisé.");
                }
                $codesPris[$cle] = true;
            }
            if ($ligne['nom'] !== '') {
                $nom = mb_strtolower($ligne['nom']);
                $libelle = mb_strtolower((string) $ligne['libelle']);
                if (isset($nomsPris[$nom][$libelle])) {
                    $probleme($libelle === ''
                        ? "un produit « {$ligne['nom']} » existe déjà : ajoutez un libellé distinctif pour les différencier."
                        : "le produit « {$ligne['nom']} — {$ligne['libelle']} » existe déjà.");
                } elseif (isset($nomsPris[$nom]) && $libelle === '') {
                    $probleme("un produit « {$ligne['nom']} » existe déjà : ajoutez un libellé distinctif pour les différencier.");
                }
                $nomsPris[$nom][$libelle] = true;
            }

            $lignes[] = $ligne;
        }

        if (! $lignes && ! $erreurs) {
            $erreurs[] = "Le fichier ne contient aucun produit : remplissez la première feuille, sous la ligne d'intitulés.";
        }

        return ['lignes' => $lignes, 'erreurs' => $erreurs];
    }

    /**
     * Enregistre des lignes déjà vérifiées par analyser(), dans une seule
     * transaction.
     *
     * @param  list<array<string, mixed>>  $lignes
     * @return int nombre de produits créés
     */
    public function importer(array $lignes, ?Magasin $magasin, User $auteur): int
    {
        return DB::transaction(function () use ($lignes, $magasin, $auteur) {
            $categories = [];
            $unites = [];

            $unite = function (string $nom) use (&$unites) {
                return $unites[self::cle($nom)] ??= Unite::firstOrCreate(['nom' => $nom], ['actif' => true]);
            };

            foreach ($lignes as $ligne) {
                $parent = $categories[self::cle($ligne['categorie'])] ??= Categorie::firstOrCreate(['nom' => $ligne['categorie']], ['actif' => true]);
                $categorie = $ligne['sous_categorie'] === null
                    ? $parent
                    : ($categories[self::cle($ligne['categorie'].'>'.$ligne['sous_categorie'])]
                        ??= Categorie::firstOrCreate(['nom' => $ligne['sous_categorie'], 'parent_id' => $parent->id], ['actif' => true]));

                $produit = Produit::create([
                    'sku' => $ligne['reference'] ?? $this->referenceLibre(),
                    'nom' => $ligne['nom'],
                    'libelle_distinctif' => $ligne['libelle'],
                    'code_barre' => $ligne['code_barre'],
                    'categorie_id' => $categorie->id,
                    'prix_piece' => $ligne['prix'],
                    'unite_base_id' => $unite($ligne['unite_base'])->id,
                    'seuil_alerte' => $ligne['seuil'],
                    'actif' => true,
                ]);

                foreach ($ligne['unites'] as $uniteVente) {
                    $produit->uniteVentes()->create([
                        'unite_id' => $unite($uniteVente['unite'])->id,
                        'facteur' => $uniteVente['facteur'],
                        'prix' => $uniteVente['prix'],
                        'actif' => true,
                    ]);
                }

                if ($ligne['stock'] > 0 && $magasin) {
                    $this->stockService->enregistrerMouvement(
                        $produit, $magasin, $ligne['stock'], MouvementStockType::Reception, $auteur,
                        motif: 'Stock initial (import du catalogue)',
                        prixAchat: $ligne['prix_achat'],
                    );
                }
            }

            return count($lignes);
        });
    }

    private function referenceLibre(): string
    {
        do {
            $reference = 'PRD-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (Produit::withTrashed()->where('sku', $reference)->exists());

        return $reference;
    }

    /** « Unité de Base » → « unite de base » : comparaison sans casse ni accents. */
    private static function cle(string $texte): string
    {
        return Str::of($texte)->ascii()->lower()->squish()->toString();
    }

    /** « 4 500 », « 4500,00 », 4500.0 → 4500 ; null si ce n'est pas un entier positif. */
    private static function entier(string $valeur): ?int
    {
        $nombre = self::nombre($valeur);

        return $nombre !== null && $nombre >= 0 && floor($nombre) === $nombre ? (int) $nombre : null;
    }

    /** Nombre tel que saisi dans un tableur français ou anglais ; null s'il n'en est pas un. */
    private static function nombre(string $valeur): ?float
    {
        $valeur = str_replace(["\u{00A0}", "\u{202F}", ' '], '', trim($valeur));
        $valeur = str_replace(',', '.', $valeur);

        return is_numeric($valeur) ? (float) $valeur : null;
    }
}
