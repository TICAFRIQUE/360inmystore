<?php

namespace App\Models;

use App\Models\Concerns\MetEnFormePhrase;
use App\Support\Arrondi;
use App\Support\ImageProduit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable([
    'sku', 'nom', 'libelle_distinctif', 'code_barre', 'categorie_id',
    'prix_piece', 'unite_base_id', 'seuil_alerte', 'actif',
])]
class Produit extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, LogsActivity, InteractsWithMedia, MetEnFormePhrase;

    /**
     * Clé de cache du catalogue de vente (voir catalogueVente()) — publique
     * pour que UniteVente puisse invalider la même entrée sans dupliquer la
     * clé (le catalogue en cache embarque les unités de vente).
     */
    // « v2 » : le catalogue porte désormais catégorie, code-barres et image
    // (vignettes de l'écran de caisse) — changer de clé évite de servir une
    // ancienne entrée, mise en cache sans limite de durée, qui ne les a pas.
    public const CACHE_CATALOGUE_VENTE = 'produits:catalogue-vente:v2';

    protected function casts(): array
    {
        return [
            'prix_piece' => 'integer',
            'seuil_alerte' => 'integer',
            'actif' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    protected function nom(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => static::casseEnPhrase($value));
    }

    protected function libelleDistinctif(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => static::casseEnPhrase($value));
    }

    /**
     * Libellé unique réutilisé partout où un produit est affiché (listes,
     * select, ticket…) : "Nom" seul, ou "Nom — Libellé distinctif" quand ce
     * dernier est renseigné. Évite de dupliquer la logique d'affichage.
     */
    protected function libelleAffichage(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->libelle_distinctif
                ? "{$this->nom} — {$this->libelle_distinctif}"
                : $this->nom,
        );
    }

    /**
     * Idem, avec le prix pièce accolé (utilisé dans les select produit).
     */
    protected function libelleAvecPrix(): Attribute
    {
        return Attribute::make(
            get: fn () => "{$this->libelle_affichage} — ".montant($this->prix_piece),
        );
    }

    /**
     * Compatibilité : historiquement une colonne texte libre, désormais
     * dérivée du référentiel Unite (voir migration 2026_08_06_130001).
     * Garder ce nom d'accesseur évite de retoucher tous les écrans qui
     * l'affichent déjà (vente, devis, ticket…).
     */
    protected function uniteBaseLibelle(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->uniteBase
                ? ($this->uniteBase->abbreviation ?: $this->uniteBase->nom)
                : 'pièce',
        );
    }

    /**
     * Nom complet + abréviation (ex. « Kilogramme (kg) ») — même valeur que
     * catalogueVente() calculait en dur, désormais réutilisable partout
     * (état de stock…) sans dupliquer l'expression.
     */
    protected function uniteBaseLibelleComplet(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->uniteBase?->nom_avec_abbreviation ?? 'pièce',
        );
    }

    /**
     * Répartit une quantité en pièces selon la plus grande unité de vente
     * active du produit qui y "rentre" au moins une fois — ex. 25 pièces
     * avec un « Carton de 10 » devient 2 cartons + 5 pièces. Purement un
     * confort d'affichage (état de stock) : ne touche jamais au stock
     * lui-même, qui reste compté en pièces (règle 5). Retourne null si le
     * produit n'a aucune unité de vente active, ou si la quantité est
     * inférieure au facteur de la plus petite d'entre elles (rien d'utile à
     * afficher). Suppose la relation uniteVentes déjà chargée.
     *
     * @return array{unite: UniteVente, nombre: int, reste: float}|null
     */
    public function repartirQuantite(int|float $quantitePieces): ?array
    {
        $quantitePieces = (float) $quantitePieces;

        $uniteDominante = $this->uniteVentes
            ->where('actif', true)
            ->sortByDesc('facteur')
            ->first(fn (UniteVente $u) => $quantitePieces >= $u->facteur);

        if (! $uniteDominante) {
            return null;
        }

        return [
            'unite' => $uniteDominante,
            'nombre' => (int) floor($quantitePieces / $uniteDominante->facteur),
            'reste' => Arrondi::quantite(fmod($quantitePieces, $uniteDominante->facteur)),
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile();
    }

    /**
     * Miniature affichée par l'écran de caisse (des dizaines de vignettes à
     * la fois : jamais l'image d'origine). Produite tout de suite, à
     * l'enregistrement — et seulement si le serveur sait traiter une image,
     * sinon l'envoi lui-même échouerait.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        if (! ImageProduit::traitementDisponible()) {
            return;
        }

        $this->addMediaConversion('vignette')
            ->fit(Fit::Crop, 320, 240)
            ->performOnCollections('image')
            ->nonQueued();
    }

    /**
     * withTrashed() : évite un crash d'affichage si la catégorie a été
     * supprimée (soft delete) alors que des produits la référencent encore.
     */
    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class)->withTrashed();
    }

    public function uniteBase(): BelongsTo
    {
        return $this->belongsTo(Unite::class, 'unite_base_id');
    }

    public function uniteVentes(): HasMany
    {
        return $this->hasMany(UniteVente::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * Détail du stock de ce produit pour chaque magasin/dépôt donné (0 si
     * aucune ligne Stock à cette destination) — alimente la colonne "Stock"
     * de la liste produits et de l'état de stock (calculé une seule fois ici,
     * jamais dupliqué par vue/export, voir CLAUDE.md). Suppose la relation
     * stocks() déjà chargée (eager loading), sinon déclenche une requête par
     * produit (N+1).
     *
     * @param  iterable<Magasin>  $magasins
     * @return array<int, array{magasin: Magasin, quantite: float, sous_seuil: bool, cout_moyen_pondere: int}>
     */
    public function stockParMagasin(iterable $magasins): array
    {
        $stocksParMagasin = $this->stocks->keyBy('magasin_id');

        return collect($magasins)->map(function (Magasin $magasin) use ($stocksParMagasin) {
            $stock = $stocksParMagasin->get($magasin->id);
            $quantite = (float) ($stock?->quantite ?? 0);

            return [
                'magasin' => $magasin,
                'quantite' => $quantite,
                'sous_seuil' => $quantite <= $this->seuil_alerte,
                'cout_moyen_pondere' => (int) ($stock?->cout_moyen_pondere ?? 0),
            ];
        })->all();
    }

    /**
     * Stock total, tous magasins/dépôts de $magasins confondus (état de
     * stock : une seule quantité globale, pas de détail par destination) —
     * même base que stockParMagasin(), simplement sommée.
     */
    public function totalStock(iterable $magasins): array
    {
        $total = array_sum(array_column($this->stockParMagasin($magasins), 'quantite'));

        return [
            'quantite' => $total,
            'sous_seuil' => $total <= $this->seuil_alerte,
        ];
    }

    /**
     * Coût moyen pondéré agrégé de ce produit, uniquement sur les magasins de
     * vente — jamais les dépôts (lieux de stockage sans notion de "coût
     * courant à la vente" pertinente ici, voir CLAUDE.md section Dépôts).
     * Moyenne pondérée par quantité, 0 si aucun stock en magasin. Suppose les
     * relations stocks et stocks.magasin déjà chargées (eager loading),
     * sinon déclenche un N+1.
     */
    public function coutMoyenPondere(): int
    {
        $stocksMagasins = $this->stocks->filter(fn (Stock $s) => $s->magasin && ! $s->magasin->estDepot());
        $totalQuantite = $stocksMagasins->sum('quantite');

        return $totalQuantite > 0
            ? Arrondi::entier($stocksMagasins->sum(fn (Stock $s) => $s->quantite * $s->cout_moyen_pondere) / $totalQuantite)
            : 0;
    }

    public function mouvementStocks(): HasMany
    {
        return $this->hasMany(MouvementStock::class);
    }

    public function ligneVentes(): HasMany
    {
        return $this->hasMany(LigneVente::class);
    }

    /**
     * Référentiel produits pour l'écran de vente : donnée de référence peu
     * volatile (change en admin, jamais à la vente), mise en cache et
     * invalidée à chaque écriture (voir booted()). Ne contient jamais le
     * stock — dérivé des mouvements, donc toujours recalculé en direct par
     * l'appelant, jamais mis en cache avec le catalogue.
     *
     * Mis en cache sous forme de tableau brut (jamais d'objet
     * Collection/Model) car le driver cache "database" interdit
     * l'unserialize d'objets PHP (`config('cache.serializable_classes')` =
     * false, durcissement contre l'injection d'objets) : un objet caché
     * reviendrait `__PHP_Incomplete_Class`.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public static function catalogueVente(): \Illuminate\Support\Collection
    {
        $catalogue = Cache::rememberForever(self::CACHE_CATALOGUE_VENTE, function () {
            return static::where('actif', true)
                ->with([
                    'categorie',
                    'media',
                    'uniteBase',
                    'uniteVentes' => fn ($q) => $q->where('actif', true)->orderBy('facteur'),
                    'uniteVentes.unite',
                ])
                ->orderBy('nom')
                ->get()
                ->map(fn (self $p) => [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'nom' => $p->nom,
                    'libelle_distinctif' => $p->libelle_distinctif,
                    'libelle_affichage' => $p->libelle_affichage,
                    // Pour l'écran de caisse : filtre par catégorie, recherche
                    // par code-barres (douchette) et vignette du produit.
                    'categorie' => $p->categorie?->nom,
                    'code_barre' => $p->code_barre,
                    // La miniature si elle existe, sinon l'image d'origine
                    // (produit enregistré avant les miniatures).
                    'image' => $p->getFirstMedia('image')?->getAvailableUrl(['vignette']),
                    'prix_piece' => $p->prix_piece,
                    'unite_base_libelle' => $p->unite_base_libelle,
                    // Nom complet + abréviation (ex. « Kilogramme (kg) ») pour
                    // le select d'unité à la vente/au devis — unite_base_libelle
                    // reste l'abréviation seule, utilisée ailleurs pour un
                    // affichage compact (ticket, quantités, prix/unité…).
                    'unite_base_libelle_complet' => $p->unite_base_libelle_complet,
                    'unites' => $p->uniteVentes->map(fn (UniteVente $u) => [
                        'id' => $u->id,
                        'libelle' => $u->libelle,
                        'facteur' => $u->facteur,
                        'prix' => $u->prix,
                    ])->values()->all(),
                ])
                ->values()
                ->all();
        });

        return collect($catalogue)->map(fn (array $p) => [
            ...$p,
            'unites' => collect($p['unites']),
        ]);
    }

    protected static function booted(): void
    {
        $invalider = fn () => Cache::forget(self::CACHE_CATALOGUE_VENTE);

        static::saved($invalider);
        static::deleted($invalider);
        static::restored($invalider);
    }
}
