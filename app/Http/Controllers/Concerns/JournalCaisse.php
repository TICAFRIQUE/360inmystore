<?php

namespace App\Http\Controllers\Concerns;

use App\Models\RemboursementAvoirClient;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Une vente (encaissement espèces) n'est jamais un MouvementCaisse — ce n'est
 * pas un mouvement manuel/généré (règle 19), elle est déjà comptée dans le
 * solde théorique via ses propres Paiement (voir
 * CaisseSessionService::calculerTheorique()). Mais elle reste, du point de
 * vue du tiroir, une entrée d'argent : ce trait ne fait qu'assembler pour
 * l'affichage (jamais pour l'accounting, qui reste inchangé) un « journal »
 * en lecture seule mêlant les deux sources, via UNION ALL — colonnes
 * alignées entre les deux branches, mêmes jointures (session_caisse →
 * caisse → magasin) des deux côtés.
 */
trait JournalCaisse
{
    private function requeteJournalCaisse(
        Carbon $debut,
        Carbon $fin,
        ?int $sessionCaisseId = null,
        ?int $magasinId = null,
        ?int $caisseId = null,
        ?int $caissierId = null,
        ?string $type = null,
    ): Builder {
        $mouvements = DB::table('mouvement_caisses')
            ->join('session_caisses', 'session_caisses.id', '=', 'mouvement_caisses.session_caisse_id')
            ->join('caisses', 'caisses.id', '=', 'session_caisses.caisse_id')
            ->join('magasins', 'magasins.id', '=', 'caisses.magasin_id')
            ->join('users', 'users.id', '=', 'mouvement_caisses.created_by')
            ->when($sessionCaisseId, fn ($q) => $q->where('mouvement_caisses.session_caisse_id', $sessionCaisseId))
            ->when($magasinId, fn ($q) => $q->where('caisses.magasin_id', $magasinId))
            ->when($caisseId, fn ($q) => $q->where('session_caisses.caisse_id', $caisseId))
            ->when($caissierId, fn ($q) => $q->where('mouvement_caisses.created_by', $caissierId))
            ->whereBetween('mouvement_caisses.created_at', [$debut, $fin])
            ->select([
                DB::raw("'mouvement' as source"),
                'mouvement_caisses.id',
                'mouvement_caisses.created_at',
                'mouvement_caisses.type',
                'mouvement_caisses.montant',
                'mouvement_caisses.motif',
                // reference_type/id : distingue un remboursement d'avoir
                // client (sortie générée automatiquement, voir
                // RemboursementAvoirClientService) d'une sortie manuelle
                // quelconque, voir decorerLigneJournal().
                'mouvement_caisses.reference_type',
                'mouvement_caisses.reference_id',
                'session_caisses.caisse_id',
                'caisses.nom as caisse_nom',
                'caisses.magasin_id',
                'magasins.nom as magasin_nom',
                'mouvement_caisses.created_by as auteur_id',
                'users.name as auteur_nom',
            ]);

        // Type 'vente' impossible côté mouvement_caisses (entree|sortie
        // seulement) : filtrer ici revient à exclure toute la branche quand
        // l'utilisateur cherche spécifiquement les ventes.
        if ($type) {
            $mouvements->where('mouvement_caisses.type', $type);
        }

        // Seule la part encaissée en espèces d'une vente entre dans le
        // tiroir (règle 10) — jointure sur moyen_paiements.est_espece,
        // agrégée par vente (HAVING > 0 exclut les ventes 100% non-espèces).
        // Ventes annulées (soft-deleted) exclues, comme dans
        // CaisseSessionService::calculerTheorique().
        $ventes = DB::table('ventes')
            ->join('paiements', 'paiements.vente_id', '=', 'ventes.id')
            ->join('moyen_paiements', function ($join) {
                $join->on('moyen_paiements.id', '=', 'paiements.moyen_paiement_id')
                    ->where('moyen_paiements.est_espece', true);
            })
            ->join('session_caisses', 'session_caisses.id', '=', 'ventes.session_caisse_id')
            ->join('caisses', 'caisses.id', '=', 'session_caisses.caisse_id')
            ->join('magasins', 'magasins.id', '=', 'caisses.magasin_id')
            ->join('users', 'users.id', '=', 'ventes.caissier_id')
            ->whereNull('ventes.deleted_at')
            ->when($sessionCaisseId, fn ($q) => $q->where('ventes.session_caisse_id', $sessionCaisseId))
            ->when($magasinId, fn ($q) => $q->where('caisses.magasin_id', $magasinId))
            ->when($caisseId, fn ($q) => $q->where('session_caisses.caisse_id', $caisseId))
            ->when($caissierId, fn ($q) => $q->where('ventes.caissier_id', $caissierId))
            ->whereBetween('ventes.created_at', [$debut, $fin])
            ->groupBy(
                'ventes.id', 'ventes.created_at', 'ventes.numero',
                'session_caisses.caisse_id', 'caisses.nom', 'caisses.magasin_id', 'magasins.nom',
                'ventes.caissier_id', 'users.name',
            )
            ->havingRaw('SUM(paiements.montant) > 0')
            ->select([
                DB::raw("'vente' as source"),
                'ventes.id',
                'ventes.created_at',
                DB::raw("'vente' as type"),
                DB::raw('SUM(paiements.montant) as montant'),
                DB::raw("CONCAT('Vente ', ventes.numero) as motif"),
                DB::raw('NULL as reference_type'),
                DB::raw('NULL as reference_id'),
                'session_caisses.caisse_id',
                'caisses.nom as caisse_nom',
                'caisses.magasin_id',
                'magasins.nom as magasin_nom',
                'ventes.caissier_id as auteur_id',
                'users.name as auteur_nom',
            ]);

        // Seule la part encaissée en espèces d'un règlement client entre
        // dans le tiroir (règle 10) — même principe que la branche ventes
        // ci-dessus. reglement_clients.caissier_id est l'auteur (celui qui a
        // encaissé), jamais le client. Le motif reprend le nom du client
        // (+ la facture visée si le règlement cible une vente précise,
        // jamais son solde global — vente_id nullable, voir ReglementClient).
        $reglements = DB::table('reglement_clients')
            ->join('reglement_paiements', 'reglement_paiements.reglement_client_id', '=', 'reglement_clients.id')
            ->join('moyen_paiements', function ($join) {
                $join->on('moyen_paiements.id', '=', 'reglement_paiements.moyen_paiement_id')
                    ->where('moyen_paiements.est_espece', true);
            })
            ->join('session_caisses', 'session_caisses.id', '=', 'reglement_clients.session_caisse_id')
            ->join('caisses', 'caisses.id', '=', 'session_caisses.caisse_id')
            ->join('magasins', 'magasins.id', '=', 'caisses.magasin_id')
            ->join('users', 'users.id', '=', 'reglement_clients.caissier_id')
            ->join('clients', 'clients.id', '=', 'reglement_clients.client_id')
            ->leftJoin('ventes', 'ventes.id', '=', 'reglement_clients.vente_id')
            ->when($sessionCaisseId, fn ($q) => $q->where('reglement_clients.session_caisse_id', $sessionCaisseId))
            ->when($magasinId, fn ($q) => $q->where('caisses.magasin_id', $magasinId))
            ->when($caisseId, fn ($q) => $q->where('session_caisses.caisse_id', $caisseId))
            ->when($caissierId, fn ($q) => $q->where('reglement_clients.caissier_id', $caissierId))
            ->whereBetween('reglement_clients.created_at', [$debut, $fin])
            ->groupBy(
                'reglement_clients.id', 'reglement_clients.created_at', 'clients.nom', 'ventes.numero',
                'session_caisses.caisse_id', 'caisses.nom', 'caisses.magasin_id', 'magasins.nom',
                'reglement_clients.caissier_id', 'users.name',
            )
            ->havingRaw('SUM(reglement_paiements.montant) > 0')
            ->select([
                DB::raw("'reglement' as source"),
                'reglement_clients.id',
                'reglement_clients.created_at',
                DB::raw("'reglement' as type"),
                DB::raw('SUM(reglement_paiements.montant) as montant'),
                DB::raw("CONCAT('Règlement ', clients.nom, IF(ventes.numero IS NOT NULL, CONCAT(' — ', ventes.numero), '')) as motif"),
                DB::raw('NULL as reference_type'),
                DB::raw('NULL as reference_id'),
                'session_caisses.caisse_id',
                'caisses.nom as caisse_nom',
                'caisses.magasin_id',
                'magasins.nom as magasin_nom',
                'reglement_clients.caissier_id as auteur_id',
                'users.name as auteur_nom',
            ]);

        // Un retour ne mouvemente jamais le tiroir (règle 18) : ligne
        // purement informative (jamais sommée dans les KPI entrées/sorties/
        // théorique), affichée sans signe +/− (voir decorerLigneJournal()).
        // Rattaché à la caisse où il est réellement TRAITÉ
        // (retour_ventes.session_caisse_id), pas forcément celle de la vente
        // d'origine — un retour sans session (créé avant que cette colonne
        // n'existe) disparaît donc de ce journal, jamais rattaché à tort à
        // la caisse de la vente. Un avoir remboursé (RemboursementAvoirClient)
        // n'apparaît lui jamais ici : il génère désormais un vrai
        // MouvementCaisse (voir branche mouvements ci-dessus), pas une ligne
        // à part.
        $retours = DB::table('retour_ventes')
            ->join('ventes', 'ventes.id', '=', 'retour_ventes.vente_id')
            ->join('session_caisses', 'session_caisses.id', '=', 'retour_ventes.session_caisse_id')
            ->join('caisses', 'caisses.id', '=', 'session_caisses.caisse_id')
            ->join('magasins', 'magasins.id', '=', 'caisses.magasin_id')
            ->join('users', 'users.id', '=', 'retour_ventes.created_by')
            ->when($sessionCaisseId, fn ($q) => $q->where('retour_ventes.session_caisse_id', $sessionCaisseId))
            ->when($magasinId, fn ($q) => $q->where('caisses.magasin_id', $magasinId))
            ->when($caisseId, fn ($q) => $q->where('session_caisses.caisse_id', $caisseId))
            ->when($caissierId, fn ($q) => $q->where('retour_ventes.created_by', $caissierId))
            ->whereBetween('retour_ventes.created_at', [$debut, $fin])
            ->select([
                DB::raw("'retour' as source"),
                'retour_ventes.id',
                'retour_ventes.created_at',
                DB::raw("'retour' as type"),
                'retour_ventes.montant_total as montant',
                DB::raw("CONCAT('Retour ', retour_ventes.numero, ' — ', ventes.numero) as motif"),
                DB::raw('NULL as reference_type'),
                DB::raw('NULL as reference_id'),
                'session_caisses.caisse_id',
                'caisses.nom as caisse_nom',
                'caisses.magasin_id',
                'magasins.nom as magasin_nom',
                'retour_ventes.created_by as auteur_id',
                'users.name as auteur_nom',
            ]);

        // Filtre "type" qui exclut explicitement une des branches (choisi) :
        // ne pas unir les autres branches du tout plutôt que fabriquer un
        // HAVING toujours faux.
        if ($type && ! in_array($type, ['vente', 'reglement', 'retour'], true)) {
            return $mouvements->orderByDesc('created_at');
        }
        if ($type === 'vente') {
            return $ventes->orderByDesc('created_at');
        }
        if ($type === 'reglement') {
            return $reglements->orderByDesc('created_at');
        }
        if ($type === 'retour') {
            return $retours->orderByDesc('created_at');
        }

        return $mouvements->unionAll($ventes)->unionAll($reglements)->unionAll($retours)->orderByDesc('created_at');
    }

    /**
     * Libellé/badge/signe d'affichage — la ligne brute (source UNION, donc
     * un stdClass, pas un MouvementCaisse) ne porte que le type en chaîne.
     */
    private function decorerLigneJournal(object $ligne): object
    {
        $ligne->type_libelle = match ($ligne->type) {
            'entree' => 'Entrée',
            'sortie' => 'Sortie',
            'vente' => 'Vente / Facture',
            'reglement' => 'Règlement client',
            'retour' => 'Retour (avoir)',
            default => $ligne->type,
        };
        // Une sortie générée par un remboursement d'avoir client (voir
        // RemboursementAvoirClientService) reste un vrai MouvementCaisse —
        // juste un libellé distinct pour ne pas la confondre avec une sortie
        // manuelle quelconque dans la colonne "Type".
        if ($ligne->type === 'sortie' && ($ligne->reference_type ?? null) === RemboursementAvoirClient::class) {
            $ligne->type_libelle = 'Remboursement avoir';
        }
        $ligne->type_badge = match ($ligne->type) {
            'entree', 'vente', 'reglement' => 'text-bg-success',
            'sortie' => 'text-bg-danger',
            'retour' => 'text-bg-warning-subtle text-warning-emphasis',
            default => 'text-bg-secondary',
        };
        $ligne->signe_positif = $ligne->type !== 'sortie';
        // Un retour ne mouvemente jamais le tiroir (règle 18) : jamais de
        // signe +/− en affichage, pour ne pas laisser croire à un encaissement
        // ou une sortie d'espèces — c'est un avoir, hors comptage du tiroir.
        $ligne->hors_tiroir = $ligne->type === 'retour';

        return $ligne;
    }
}
