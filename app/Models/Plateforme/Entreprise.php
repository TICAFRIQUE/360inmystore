<?php

namespace App\Models\Plateforme;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Entreprise cliente de la plateforme : un espace à son
 * sous-domaine, sur sa propre base de données. Ne contient aucune donnée
 * métier — seulement de quoi retrouver l'espace, joindre son propriétaire et
 * suivre son abonnement depuis la console de l'éditeur.
 */
#[Fillable([
    'nom', 'sous_domaine', 'base', 'proprietaire_nom', 'telephone', 'pays', 'statut', 'motif_suspension',
    'formule_nom', 'abonnement_fin', 'abonnement_illimite', 'ip_inscription',
])]
class Entreprise extends ModelePlateforme
{
    use LogsActivity;

    /** Base en cours de préparation : espace pas encore ouvert. */
    public const CREATION = 'creation';

    public const ACTIVE = 'active';

    /** Accès fermé par l'éditeur ; les données sont conservées. */
    public const SUSPENDUE = 'suspendue';

    protected function casts(): array
    {
        return [
            'abonnement_fin' => 'date',
            'abonnement_illimite' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['nom', 'statut', 'motif_suspension'])->logOnlyDirty();
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(PaiementAbonnement::class);
    }

    public function url(string $chemin = '/'): string
    {
        return plateforme()->urlEntreprise($this->sous_domaine, $chemin);
    }

    public function estActive(): bool
    {
        return $this->statut === self::ACTIVE;
    }

    /** Inscrite, mais son propriétaire n'a pas encore choisi de formule. */
    public function sansFormule(): bool
    {
        return ! $this->abonnement_illimite && $this->abonnement_fin === null;
    }

    /**
     * Met à jour la copie de l'abonnement tenue pour la console (la source
     * de vérité reste le registre des activations, dans la base de
     * l'entreprise). Requête directe, sans passer par les événements du
     * modèle : appelée aussi depuis l'espace de l'entreprise, où le journal
     * d'activité de cette fiche n'a rien à faire.
     */
    public function recopierAbonnement(string $formuleNom, \DateTimeInterface|string|null $dateFin, bool $illimite): void
    {
        static::query()->whereKey($this->id)->update([
            'formule_nom' => $formuleNom,
            'abonnement_fin' => $illimite ? null : $dateFin,
            'abonnement_illimite' => $illimite,
        ]);
    }

    /** État de l'abonnement en une phrase, pour la console et ses exports. */
    public function libelleAbonnement(): string
    {
        return match (true) {
            $this->abonnement_illimite => 'Illimité',
            $this->abonnement_fin === null => 'Formule non choisie',
            $this->abonnementExpire() => 'Expiré le '.$this->abonnement_fin->format('d/m/Y'),
            default => "Jusqu'au ".$this->abonnement_fin->format('d/m/Y'),
        };
    }

    /** Abonnement arrivé à échéance (jamais vrai pour un abonnement illimité). */
    public function abonnementExpire(): bool
    {
        return ! $this->abonnement_illimite && $this->abonnement_fin !== null && $this->abonnement_fin->isPast();
    }
}
