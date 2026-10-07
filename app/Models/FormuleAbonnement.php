<?php

namespace App\Models;

use App\Models\Concerns\MetEnFormePhrase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Référentiel des offres d'abonnement, géré depuis l'écran Gestion abonnement
 * (réservé Superadmin/développeur, voir User::estGestionnaireAbonnement()).
 * Soit un nombre de jours, soit illimité (jours alors nul) — voir
 * Abonnement::activer().
 */
#[Fillable(['nom', 'jours', 'illimite', 'prix', 'description', 'avantages', 'mise_en_avant', 'actif'])]
class FormuleAbonnement extends Model
{
    use HasFactory, LogsActivity, MetEnFormePhrase;

    protected function casts(): array
    {
        return [
            'jours' => 'integer',
            'illimite' => 'boolean',
            'prix' => 'integer',
            'mise_en_avant' => 'boolean',
            'actif' => 'boolean',
        ];
    }

    /**
     * Formules proposées à la souscription : toujours le catalogue de la
     * PLATEFORME (géré par l'éditeur). Depuis l'espace d'une entreprise, ce
     * n'est donc pas sa table locale, qui ne garde que la copie des formules
     * déjà souscrites, pour son historique (voir copieLocale()).
     */
    public static function catalogue(): Builder
    {
        return plateforme()->estEntreprise() ? static::on('plateforme') : static::query();
    }

    /**
     * Copie, dans la base de l'entreprise courante, d'une formule du
     * catalogue : une activation référence une formule locale (clé
     * étrangère), jamais directement celle de la plateforme. Inactive — elle
     * ne sert qu'à l'historique, et garde les conditions (durée, prix) en
     * vigueur au moment de la souscription.
     */
    public static function copieLocale(string $nom, ?int $jours, bool $illimite, int $prix): self
    {
        return static::query()->firstOrCreate(
            ['nom' => $nom, 'jours' => $illimite ? null : $jours, 'illimite' => $illimite, 'prix' => $prix],
            ['actif' => false],
        );
    }

    /** Formule à 0 F : l'essai gratuit, proposé une seule fois au démarrage d'un espace. */
    public function estGratuite(): bool
    {
        return $this->prix === 0;
    }

    /**
     * Ce que la formule inclut, tel que saisi par l'éditeur : une ligne par
     * élément.
     *
     * @return array<int, string>
     */
    public function listeAvantages(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->avantages))));
    }

    protected function nom(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => static::casseEnPhrase($value));
    }

    public function activations(): HasMany
    {
        return $this->hasMany(AbonnementActivation::class, 'formule_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
