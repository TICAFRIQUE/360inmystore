<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Magasin;
use App\Models\SessionCaisse;
use App\Models\Vente;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'email', 'telephone', 'password', 'magasin_id', 'actif'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles, LogsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'actif' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'username', 'email', 'telephone', 'magasin_id', 'actif'])->logOnlyDirty();
    }

    /**
     * withTrashed() : évite un crash d'affichage (badge magasin du
     * topbar, etc.) si le magasin de rattachement a été supprimé
     * (soft delete) alors que l'utilisateur y reste encore attaché.
     */
    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class)->withTrashed();
    }

    public function sessionCaisses(): HasMany
    {
        return $this->hasMany(SessionCaisse::class, 'caissier_id');
    }

    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class, 'caissier_id');
    }

    /**
     * Superadmin du client, ou compte « développeur » (éditeur) fixé côté
     * .env — jamais un rôle Spatie : ce serait auto-attribuable par le
     * Superadmin lui-même via l'écran Rôles, ce qui viderait le
     * verrouillage de l'abonnement de son sens (voir
     * AssureAbonnementActif/AssureGestionnaireAbonnement).
     */
    public function estGestionnaireAbonnement(): bool
    {
        // Dans l'espace d'une entreprise, personne ne
        // gère l'abonnement — pas même son Superadmin, qui est le client
        // lui-même. L'abonnement s'y renouvelle par paiement (voir
        // PaiementAbonnementController), les formules se gèrent depuis la
        // console de l'éditeur.
        if (plateforme()->estEntreprise()) {
            return false;
        }

        return $this->hasRole('Superadmin')
            || (config('abonnement.developpeur_username') !== null
                && $this->username === config('abonnement.developpeur_username'));
    }

    /**
     * Destinataires des alertes caisse (écart, session restée ouverte…) :
     * les gérants du magasin concerné, plus tous les superadmins.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public static function gerantsEtSuperadmins(int $magasinId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('actif', true)
            ->where(function ($q) use ($magasinId) {
                $q->whereHas('roles', fn ($r) => $r->where('name', 'Superadmin'))
                    ->orWhere(function ($q2) use ($magasinId) {
                        $q2->whereHas('roles', fn ($r) => $r->where('name', 'Gérant'))
                            ->where('magasin_id', $magasinId);
                    });
            })
            ->get();
    }
}
