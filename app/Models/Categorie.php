<?php

namespace App\Models;

use App\Models\Concerns\MetEnFormePhrase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

#[Fillable(['nom', 'parent_id', 'actif'])]
class Categorie extends Model
{
    use HasFactory, SoftDeletes, MetEnFormePhrase;

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }

    protected function nom(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => static::casseEnPhrase($value));
    }

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function enfants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Le catalogue de vente mis en cache porte le nom de la catégorie de
     * chaque produit (filtre de l'écran de caisse) : la renommer ou la
     * supprimer doit donc l'invalider, comme une modification de produit.
     */
    protected static function booted(): void
    {
        $invalider = fn () => Cache::forget(Produit::CACHE_CATALOGUE_VENTE);

        static::saved($invalider);
        static::deleted($invalider);
        static::restored($invalider);
    }
}
