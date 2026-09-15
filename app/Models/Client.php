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
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['nom', 'code', 'type_client_id', 'telephone', 'adresse', 'limite_credit', 'actif'])]
class Client extends Model
{
    use HasFactory, LogsActivity, SoftDeletes, MetEnFormePhrase;

    protected function casts(): array
    {
        return [
            'limite_credit' => 'integer',
            'actif' => 'boolean',
            'comptant_defaut' => 'boolean',
        ];
    }

    protected function nom(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => static::casseEnPhrase($value));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable();
    }

    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class);
    }

    public function typeClient(): BelongsTo
    {
        return $this->belongsTo(TypeClient::class);
    }

    public function ecritures(): HasMany
    {
        return $this->hasMany(EcritureCompteClient::class);
    }

    public function reglements(): HasMany
    {
        return $this->hasMany(ReglementClient::class);
    }

    public function devis(): HasMany
    {
        return $this->hasMany(Devis::class);
    }

    public function retours(): HasMany
    {
        return $this->hasMany(RetourVente::class);
    }

    /**
     * Client singleton "Client comptant" (même principe que
     * CompteTresorerie::type=caisse_generale) — seedé une fois
     * (ClientComptantDefautSeeder), jamais supprimable. Sert de compte à
     * créditer pour un retour sur une vente comptant (sans client
     * identifié) : la vente reste anonyme, seul le retour est rattaché à ce
     * compte générique. Voir RetourVenteService::retourner().
     */
    public static function comptantDefaut(): self
    {
        return static::where('comptant_defaut', true)->firstOrFail();
    }

    /**
     * Solde dérivé, jamais stocké (règle 12) : somme des écritures du
     * compte. Positif = le client doit de l'argent.
     */
    public function solde(): int
    {
        return $this->ecritures()->sum('montant');
    }

    /**
     * Total NET des ventes réalisées avec ce client : total de SES ventes
     * (non annulées) moins les retours déjà enregistrés dessus — un volume
     * de business réel, pas juste la somme brute des factures émises (qui
     * ne dit rien de ce qui a ensuite été retourné). KPI fiche client
     * ("Total ventes net").
     *
     * Ne compte QUE les retours rattachés à une vente DE CE CLIENT
     * (`retours.vente.client_id`), pas tous les retours crédités à son
     * compte (`retours()` au sens large) : un client peut recevoir un avoir
     * sur la vente comptant anonyme de quelqu'un d'autre (voir
     * RetourVenteService), ce qui n'a rien à voir avec SON propre volume
     * d'achat — sans cette distinction, un client sans aucun achat mais
     * ayant reçu un tel avoir affichait un "Total ventes net" négatif.
     * max(0, ...) en filet de sécurité, jamais un chiffre d'affaires négatif.
     */
    public function totalVentes(): int
    {
        $retoursSurSesVentes = $this->retours()
            ->whereHas('vente', fn ($q) => $q->where('client_id', $this->id))
            ->sum('montant_total');

        return max(0, $this->ventes()->sum('total_net') - $retoursSurSesVentes);
    }

    /**
     * Total effectivement encaissé auprès de ce client, toutes voies
     * confondues : paiements à la vente + règlements ultérieurs. Distinct de
     * solde() qui reflète la dette RESTANTE (après retours/annulations).
     */
    public function totalRegle(): int
    {
        $paiements = Paiement::whereHas('vente', fn ($q) => $q->where('client_id', $this->id))->sum('montant');

        return $paiements + $this->reglements()->sum('montant');
    }

    /**
     * Clients actifs avec une dette en cours, pour l'écran de règlement —
     * solde calculé en une seule requête groupée (withSum + having), jamais
     * client par client (évite un N+1 sur potentiellement tout le fichier
     * client).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function actifsAvecDette(): \Illuminate\Database\Eloquent\Collection
    {
        return static::query()
            ->where('actif', true)
            ->withSum('ecritures as solde', 'montant')
            ->having('solde', '>', 0)
            ->orderBy('nom')
            ->get();
    }

    /**
     * Symétrique de actifsAvecDette() : clients avec un avoir disponible
     * (solde négatif, voir CLAUDE.md règle 20) — pour l'écran de
     * remboursement centralisé (RemboursementAvoirClientController::index()),
     * même principe de solde groupé en une seule requête.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function actifsAvecAvoir(): \Illuminate\Database\Eloquent\Collection
    {
        return static::query()
            ->where('actif', true)
            ->withSum('ecritures as solde', 'montant')
            ->having('solde', '<', 0)
            ->orderBy('nom')
            ->get();
    }
}
