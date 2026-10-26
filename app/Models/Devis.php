<?php

namespace App\Models;

use App\Enums\DevisStatut;
use App\Support\Arrondi;
use App\Support\Remise;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Document commercial pré-vente : ne mouvemente ni le stock ni la caisse
 * (règle 15). Montants toujours indicatifs (jamais figés), transformable en
 * Vente tant qu'il n'est pas expiré/refusé/déjà transformé.
 */
#[Fillable(['numero', 'client_id', 'created_by', 'statut', 'date_validite', 'vente_id', 'transforme_at'])]
class Devis extends Model
{
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'statut' => DevisStatut::class,
            'date_validite' => 'date',
            'transforme_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneDevis::class);
    }

    /**
     * L'expiration n'est jamais stockée : un devis encore brouillon dont la
     * date de validité est dépassée est expiré, calculé à la volée (règle
     * 15, comme le stock jamais figé).
     */
    public function statutEffectif(): DevisStatut
    {
        if ($this->statut === DevisStatut::Brouillon && $this->date_validite->isPast()) {
            return DevisStatut::Expire;
        }

        return $this->statut;
    }

    public function peutEtreModifie(): bool
    {
        return $this->statut === DevisStatut::Brouillon;
    }

    /**
     * Pas d'étape « envoyé »/« accepté » séparée pour le moment : un devis
     * brouillon non expiré se transforme directement en vente, ce qui vaut
     * acceptation (voir CLAUDE.md).
     */
    public function peutEtreTransforme(): bool
    {
        return $this->statutEffectif() === DevisStatut::Brouillon;
    }

    /**
     * Un devis encore en brouillon (expiré compris) peut être refusé ; un
     * devis transformé reste intouchable, et un devis déjà refusé n'a plus
     * rien à refuser.
     */
    public function peutEtreAnnule(): bool
    {
        return $this->statut === DevisStatut::Brouillon;
    }

    /**
     * Montants indicatifs, recalculés à partir du prix courant du catalogue
     * (jamais figés, règle 15) — suppose lignes.produit, lignes.uniteVente et
     * lignes.taxe chargées. sous_total reste HT (comme total_ligne côté
     * vente) ; total_taxes/total_net ajoutent la taxe choisie par ligne (elle
     * n'est, elle, jamais recalculée au catalogue courant — voir
     * LigneDevis::taxe()). Pas de remise sur le total pour un devis
     * (contrairement à une vente), donc total_net = sous_total + total_taxes.
     *
     * @return array{sous_total:int, total_taxes:int, total_net:int}
     */
    public function calculerMontants(): array
    {
        $sousTotal = 0;
        $totalTaxes = 0;

        foreach ($this->lignes as $ligne) {
            $prixUnitaire = $ligne->uniteVente?->prix ?? $ligne->produit->prix_piece;
            $sousTotalLigne = Arrondi::entier($prixUnitaire * (float) $ligne->quantite);
            $remiseLigne = Remise::resoudre($ligne->remise_type, $ligne->remise_valeur, $sousTotalLigne);
            $totalLigne = $sousTotalLigne - $remiseLigne;
            $sousTotal += $totalLigne;
            $totalTaxes += Arrondi::entier($totalLigne * ($ligne->taxe?->taux ?? 0) / 100);
        }

        return ['sous_total' => $sousTotal, 'total_taxes' => $totalTaxes, 'total_net' => $sousTotal + $totalTaxes];
    }

    /**
     * Un devis ne réserve jamais de stock (règle 15) et n'est plus rattaché
     * à un magasin : le stock est donc totalisé toutes localisations
     * confondues (magasins + dépôts), pour avertir en amont si un produit
     * est insuffisant partout, plutôt que de laisser échouer la vente après
     * coup — suppose lignes.produit et lignes.uniteVente chargées.
     *
     * @return Collection<int, array{ligne: LigneDevis, demande: float, disponible: float}>
     */
    public function lignesEnRuptureDeStock(): Collection
    {
        $stocksParProduit = Stock::query()
            ->whereIn('produit_id', $this->lignes->pluck('produit_id'))
            ->selectRaw('produit_id, SUM(quantite) as total')
            ->groupBy('produit_id')
            ->pluck('total', 'produit_id');

        return $this->lignes
            ->map(function (LigneDevis $ligne) use ($stocksParProduit) {
                $demande = Arrondi::quantite((float) $ligne->quantite * ($ligne->uniteVente->facteur ?? 1));
                $disponible = (float) ($stocksParProduit[$ligne->produit_id] ?? 0);

                return ['ligne' => $ligne, 'demande' => $demande, 'disponible' => $disponible];
            })
            ->filter(fn (array $etat) => $etat['demande'] > $etat['disponible'])
            ->values();
    }
}
