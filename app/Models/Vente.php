<?php

namespace App\Models;

use App\Enums\VenteType;
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
    'numero', 'type', 'magasin_id', 'session_caisse_id', 'caissier_id', 'client_id', 'sous_total',
    'remise_totale_type', 'remise_totale_valeur', 'remise_totale_montant', 'total_net',
    'montant_recu', 'monnaie_rendue', 'avoir_applique', 'motif_annulation', 'annulee_par',
])]
class Vente extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    const UPDATED_AT = null;

    /**
     * Une vente "supprimée" (deleted_at) représente une annulation, pas une
     * perte de données : le contenu (lignes, montants) n'est jamais modifié,
     * elle reste consultable (withTrashed) et exclue par défaut des
     * agrégats (CA, rapports) — voir VenteController::annuler().
     */
    protected function casts(): array
    {
        return [
            'type' => VenteType::class,
            'sous_total' => 'integer',
            'remise_totale_valeur' => 'integer',
            'remise_totale_montant' => 'integer',
            'total_net' => 'integer',
            'montant_recu' => 'integer',
            'monnaie_rendue' => 'integer',
            'avoir_applique' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable();
    }

    /**
     * withTrashed() : une vente est un historique immuable, elle doit
     * rester affichable (ticket, rapports) même si le magasin a été
     * supprimé (soft delete) depuis.
     */
    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class)->withTrashed();
    }

    public function sessionCaisse(): BelongsTo
    {
        return $this->belongsTo(SessionCaisse::class);
    }

    public function caissier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caissier_id');
    }

    public function annulateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annulee_par');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneVente::class);
    }

    /**
     * Total des taxes des lignes (0 si aucune ligne taxée) — total_net (déjà
     * stocké, voir VenteService::vendre()) inclut déjà ce montant dans le net
     * à payer ; cette méthode ne sert qu'à l'affichage du détail HT/TVA/TTC
     * (ticket, facture). Suppose `lignes.taxe` chargée.
     */
    public function totalTaxes(): int
    {
        return $this->lignes->sum(fn (LigneVente $ligne) => $ligne->montantTtc() - $ligne->total_ligne);
    }

    public function retours(): HasMany
    {
        return $this->hasMany(RetourVente::class);
    }

    public function bonsLivraison(): HasMany
    {
        return $this->hasMany(BonLivraison::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    /**
     * Règlements ultérieurs explicitement imputés à cette vente (bouton
     * "Régler" depuis son détail ou depuis une ligne de l'historique du
     * compte client) — distincts des `paiements`, encaissés à la vente.
     */
    public function reglementsClient(): HasMany
    {
        return $this->hasMany(ReglementClient::class);
    }

    /**
     * Total réglé au titre de cette vente : paiements encaissés à la vente +
     * règlements ultérieurs imputés à cette vente. Suppose `paiements` et
     * `reglementsClient` chargées.
     */
    public function montantRegle(): int
    {
        return $this->paiements->sum('montant') + $this->reglementsClient->sum('montant');
    }

    /**
     * Reste dû par le client : net à payer moins le montant réglé (0 pour
     * une vente comptant). Suppose `paiements` et `reglementsClient` chargées.
     */
    public function soldeDu(): int
    {
        return $this->total_net - $this->montantRegle();
    }

    /**
     * Reste réellement dû : soldeDu() net de la part déjà couverte par un
     * avoir client au moment de la vente (avoir_applique, figé à la
     * création — voir VenteService::vendre()) ET des retours déjà
     * enregistrés sur CETTE vente précise (jamais négatif — l'éventuel
     * excédent devient un avoir sur le compte client global, pas une dette
     * négative par document). Sans ça, une facture intégralement compensée
     * par un avoir préexistant ou par un retour continuait d'afficher un
     * "reste dû" alors que le compte du client était déjà à 0 (l'avoir se
     * déduit automatiquement de la dette posée, règle 12/20) — c'est cette
     * valeur qu'il faut utiliser partout où "reste dû" signifie une vraie
     * dette encore ouverte (ticket, bouton "Régler", export). Suppose
     * `paiements`, `reglementsClient` et `retours` chargées.
     */
    public function soldeDuReel(): int
    {
        return max(0, $this->soldeDu() - $this->avoir_applique - $this->retours->sum('montant_total'));
    }

    /**
     * true pour une vente enregistrée directement comme bon de livraison
     * (voir CLAUDE.md, VenteService::vendre()) — livrée automatiquement et
     * entièrement dès sa création, distincte d'une facture classique qui
     * peut ensuite être livrée séparément via BonLivraisonService.
     */
    public function estBonLivraison(): bool
    {
        return $this->type === VenteType::BonLivraison;
    }

    /**
     * true si au moins un bon de livraison actif a été enregistré sur cette
     * vente — sert à savoir si un statut de livraison doit être affiché du
     * tout : une vente comptoir classique, jamais suivie via un bon de
     * livraison (remise immédiate au comptoir), n'affiche rien plutôt qu'un
     * trompeur "non livrée". Suppose `bonsLivraison` chargée.
     */
    public function livraisonEngagee(): bool
    {
        return $this->bonsLivraison->isNotEmpty();
    }

    /**
     * Quantité totale (en pièces, toutes lignes confondues) déjà couverte
     * par des bons de livraison actifs. Suppose `bonsLivraison.lignes`
     * chargée.
     */
    public function quantiteLivreePieces(): int
    {
        return $this->bonsLivraison->flatMap->lignes->sum('quantite_pieces');
    }

    /**
     * true si la totalité des pièces vendues a été couverte par des bons de
     * livraison actifs. Suppose `lignes` et `bonsLivraison.lignes` chargées.
     */
    public function entierementLivree(): bool
    {
        return $this->quantiteLivreePieces() >= $this->lignes->sum('quantite_pieces');
    }

    /**
     * Vendu > livré (bons de livraison actifs, non annulés) — couvre aussi
     * bien une vente jamais touchée par un bon de livraison (livré = 0)
     * qu'une livraison partielle en cours. Aucune restriction par client/
     * type de client : toute vente non entièrement livrée qualifie (voir
     * BonLivraisonController::index()). Comparaison SQL directe (pas de
     * tauxCompletion() PHP ligne par ligne, qui filtrerait après
     * pagination), même principe que CommandeAchat::scopeReceptionIncomplete().
     */
    public function scopeLivraisonIncomplete(Builder $query): Builder
    {
        return $query->whereNull('ventes.deleted_at')->whereRaw(
            '(select coalesce(sum(lv.quantite_pieces), 0) from ligne_ventes lv where lv.vente_id = ventes.id)'
            .' > '
            .'(select coalesce(sum(lbl.quantite_pieces), 0) from ligne_bon_livraisons lbl'
            .' inner join bon_livraisons bl on bl.id = lbl.bon_livraison_id'
            .' where bl.vente_id = ventes.id and bl.deleted_at is null)'
        );
    }

    /**
     * Vendu > déjà retourné (au moins une ligne encore retournable) ET
     * datée d'aujourd'hui — un retour n'est possible que le jour même de la
     * vente (voir RetourVenteService::retourner()), donc une facture d'un
     * jour précédent ne doit plus apparaître ici même si elle a
     * techniquement des lignes non retournées. Même principe SQL direct que
     * scopeLivraisonIncomplete() ci-dessus, pour l'écran de recherche de
     * facture à retourner (RetourVenteController::rechercher()).
     */
    public function scopeRetournable(Builder $query): Builder
    {
        return $query->whereNull('ventes.deleted_at')
            ->whereDate('ventes.created_at', today())
            ->whereRaw(
                '(select coalesce(sum(lv.quantite_pieces), 0) from ligne_ventes lv where lv.vente_id = ventes.id)'
                .' > '
                .'(select coalesce(sum(lrv.quantite_pieces), 0) from ligne_retour_ventes lrv'
                .' inner join ligne_ventes lv2 on lv2.id = lrv.ligne_vente_id'
                .' where lv2.vente_id = ventes.id)'
            );
    }
}
