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
#[Fillable(['nom', 'jours', 'illimite', 'prix', 'description', 'avantages', 'max_magasins', 'max_caisses', 'max_utilisateurs', 'max_produits', 'mise_en_avant', 'actif'])]
class FormuleAbonnement extends Model
{
    use HasFactory, LogsActivity, MetEnFormePhrase;

    /**
     * Ce qu'une formule peut limiter (voir App\Plateforme\LimitesFormule) :
     * ressource => colonne et libellés. Une colonne vide = sans limite.
     */
    public const LIMITES = [
        'magasins' => ['colonne' => 'max_magasins', 'singulier' => 'magasin', 'pluriel' => 'magasins'],
        'caisses' => ['colonne' => 'max_caisses', 'singulier' => 'caisse', 'pluriel' => 'caisses'],
        'utilisateurs' => ['colonne' => 'max_utilisateurs', 'singulier' => 'utilisateur', 'pluriel' => 'utilisateurs'],
        'produits' => ['colonne' => 'max_produits', 'singulier' => 'produit', 'pluriel' => 'produits'],
    ];

    protected function casts(): array
    {
        return [
            'jours' => 'integer',
            'illimite' => 'boolean',
            'prix' => 'integer',
            'max_magasins' => 'integer',
            'max_caisses' => 'integer',
            'max_utilisateurs' => 'integer',
            'max_produits' => 'integer',
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
     * ne sert qu'à l'historique, et garde les conditions (durée, prix,
     * limites) en vigueur au moment de la souscription.
     *
     * @param  array<string, ?int>  $limites  max_magasins, max_caisses… (voir LIMITES)
     */
    public static function copieLocale(string $nom, ?int $jours, bool $illimite, int $prix, array $limites = []): self
    {
        $copie = static::query()->firstOrCreate(
            ['nom' => $nom, 'jours' => $illimite ? null : $jours, 'illimite' => $illimite, 'prix' => $prix],
            ['actif' => false],
        );

        // À chaque souscription, les limites du moment remplacent celles
        // de la souscription précédente à la même formule.
        $copie->update(array_intersect_key($limites, array_flip(array_column(self::LIMITES, 'colonne'))));

        return $copie;
    }

    /**
     * Limites de la formule (colonne => valeur), pour les recopier.
     *
     * @return array<string, ?int>
     */
    public function limites(): array
    {
        return $this->only(array_column(self::LIMITES, 'colonne'));
    }

    /**
     * Les limites en clair (« 2 magasins », « 500 produits »), pour la page
     * des tarifs. Une ressource sans limite n'y figure pas.
     *
     * @return list<string>
     */
    public function limitesLisibles(): array
    {
        $lignes = [];

        foreach (self::LIMITES as $limite) {
            $maximum = $this->{$limite['colonne']};

            if ($maximum !== null) {
                $lignes[] = number_format($maximum, 0, ',', ' ').' '.$limite[$maximum > 1 ? 'pluriel' : 'singulier'];
            }
        }

        return $lignes;
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
