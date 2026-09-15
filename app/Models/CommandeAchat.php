<?php

namespace App\Models;

use App\Enums\MouvementStockType;
use App\Support\Arrondi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable([
    'numero', 'fournisseur_id', 'statut', 'type', 'date_commande',
    'created_by', 'valide_by', 'valide_at', 'motif_annulation', 'annulee_par',
])]
class CommandeAchat extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return [
            'date_commande' => 'date',
            'valide_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    /**
     * withTrashed() : une commande d'achat est un historique, elle doit
     * rester affichable même si le fournisseur a été supprimé (soft delete)
     * depuis.
     */
    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class)->withTrashed();
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_by');
    }

    public function annulateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annulee_par');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneCommandeAchat::class);
    }

    public function retours(): HasMany
    {
        return $this->hasMany(RetourAchat::class);
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(ReceptionAchat::class);
    }

    /**
     * Vrai si cette commande a un mouvement de stock de type Réception posé
     * directement dessus (ancien modèle, avant les réceptions échelonnées).
     * Distingue une commande "legacy" (tout reçu à la validation) d'une
     * commande récente encore jamais réceptionnée : les deux ont `receptions`
     * vide, seul un vrai MouvementStock permet de trancher — voir
     * AchatService::annuler(), RetourAchatService::retourner(),
     * CommandeAchatController::calculerLignesRetournables().
     */
    public function aDesMouvementsStockDirects(): bool
    {
        return MouvementStock::where('reference_type', self::class)
            ->where('reference_id', $this->id)
            ->where('type', MouvementStockType::Reception)
            ->exists();
    }

    /**
     * Suppose `lignes` chargée (loadMissing en amont, voir
     * CommandeAchatController::show()).
     */
    /**
     * Indicatif, jamais arrondi à l'entier (voir Arrondi::prixAchat() /
     * LigneCommandeAchat::montantHt()) — contrairement à totalTtcReel(), qui
     * bascule sur le montant réellement facturé (entier) dès qu'une
     * réception existe.
     */
    public function totalHt(): float
    {
        return Arrondi::prixAchat((float) $this->lignes->sum(fn (LigneCommandeAchat $ligne) => $ligne->montantHt()));
    }

    public function totalTtc(): float
    {
        return Arrondi::prixAchat((float) $this->lignes->sum(fn (LigneCommandeAchat $ligne) => $ligne->montantTtc()));
    }

    public function totalTaxes(): float
    {
        return Arrondi::prixAchat($this->totalTtc() - $this->totalHt());
    }

    /**
     * Montant réellement dû/facturé, au prix réel des réceptions plutôt que
     * l'indicatif de la commande — bascule automatiquement selon l'époque
     * (aucun flag stocké) : une commande sans aucune réception (créée avant
     * ce système, ou pas encore reçue) retombe sur l'indicatif totalTtc(),
     * qui EST le réel pour ces cas (tout reçu d'un coup à la validation dans
     * l'ancien modèle). Suppose `receptions.lignes.taxe` et `lignes` chargées.
     *
     * Toujours entier, contrairement à totalTtc() : c'est cette valeur (via
     * resteDu()) qui plafonne un vrai règlement fournisseur
     * (ReglementFournisseurService), lequel reste strictement entier XOF —
     * le fallback sur l'indicatif décimal est donc arrondi ici, jamais
     * transmis tel quel au circuit de règlement.
     */
    public function totalTtcReel(): int
    {
        return $this->receptions->isNotEmpty()
            ? $this->receptions->sum(fn (ReceptionAchat $r) => $r->totalTtc())
            : Arrondi::entier($this->totalTtc());
    }

    /**
     * Quantité totale commandée (pièces), toutes lignes confondues. Suppose
     * `lignes` chargée.
     */
    public function quantiteCommandeePieces(): float
    {
        return (float) $this->lignes->sum('quantite_pieces');
    }

    /**
     * Quantité totale reçue (pièces), toutes réceptions confondues. Suppose
     * `lignes.receptions` chargée.
     */
    public function quantiteRecuePieces(): float
    {
        return (float) $this->lignes->flatMap->receptions->sum('quantite_pieces');
    }

    public function quantiteResteARecevoirPieces(): float
    {
        return $this->quantiteCommandeePieces() - $this->quantiteRecuePieces();
    }

    /**
     * Taux de complétion de la réception, en pourcentage entier. Suppose
     * `lignes.receptions` chargée.
     */
    public function tauxCompletion(): int
    {
        $commandee = $this->quantiteCommandeePieces();

        return $commandee > 0 ? (int) round($this->quantiteRecuePieces() / $commandee * 100) : 0;
    }

    /**
     * Bons de commande validés dont la réception n'est pas encore complète
     * (jamais réceptionnés, ou seulement partiellement) — comparaison SQL
     * directe (pas de tauxCompletion() PHP ligne par ligne, qui filtrerait
     * après pagination) entre quantité commandée et quantité reçue.
     */
    public function scopeReceptionIncomplete(Builder $query): Builder
    {
        return $query->where('statut', 'validee')->whereNull('deleted_at')->whereRaw(
            '(select coalesce(sum(lca.quantite_pieces), 0) from ligne_commande_achats lca where lca.commande_achat_id = commande_achats.id)'
            .' > '
            .'(select coalesce(sum(lra.quantite_pieces), 0) from ligne_reception_achats lra'
            .' inner join ligne_commande_achats lca2 on lca2.id = lra.ligne_commande_achat_id'
            .' where lca2.commande_achat_id = commande_achats.id)'
        );
    }

    /**
     * Écart entre le montant réellement facturé (réceptions) et l'indicatif
     * de la commande — positif si le fournisseur a facturé plus cher que
     * prévu. Suppose `lignes`, `receptions.lignes.taxe` chargées.
     */
    public function ecartMontant(): float
    {
        return Arrondi::prixAchat($this->totalTtcReel() - $this->totalTtc());
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(PaiementAchat::class);
    }

    /**
     * Règlements ultérieurs explicitement imputés à cette commande (bouton
     * "Régler" depuis son détail ou depuis une ligne de l'historique du
     * compte fournisseur) — distinct des `paiements`, versés à la
     * validation.
     */
    public function reglementsFournisseur(): HasMany
    {
        return $this->hasMany(ReglementFournisseur::class);
    }

    /**
     * Total réglé au fournisseur pour cette commande : paiements versés à la
     * validation (ancien modèle) ou à chaque réception (nouveau modèle — un
     * paiement de réception garde toujours commande_achat_id renseigné, voir
     * migration add_reception_achat_id_to_paiement_achats_table, donc cette
     * relation couvre déjà les deux époques sans distinction) + règlements
     * ultérieurs imputés à cette commande. Suppose `paiements` et
     * `reglementsFournisseur` chargées.
     */
    public function montantRegle(): int
    {
        return $this->paiements->sum('montant') + $this->reglementsFournisseur->sum('montant');
    }

    /**
     * Reste dû au fournisseur : montant réellement dû (totalTtcReel(), pas
     * l'indicatif) moins le montant réglé, moins les retours déjà
     * enregistrés sur CETTE commande précise (un retour référence toujours
     * une commande précise, il doit réduire ce qui reste dû dessus — jamais
     * < 0, l'éventuel excédent devient un avoir sur le compte fournisseur
     * global, pas une dette négative par document). Suppose `lignes`,
     * `receptions.lignes.taxe`, `paiements`, `reglementsFournisseur` et
     * `retours` chargées.
     */
    public function resteDu(): int
    {
        return max(0, $this->totalTtcReel() - $this->montantRegle() - $this->retours->sum('montant_total'));
    }
}
