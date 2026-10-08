<?php

namespace App\Services;

use App\Enums\EcritureCompteTresorerieType;
use App\Enums\MouvementCaisseType;
use App\Models\CompteTresorerie;
use App\Models\Depense;
use App\Models\Magasin;
use App\Models\MotifMouvement;
use App\Models\SessionCaisse;
use App\Models\User;
use App\Support\ImageProduit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Seul point d'écriture d'une dépense (voir App\Models\Depense). Chaque
 * dépense est créée dans la même transaction que la sortie d'argent qui la
 * paie — un mouvement de caisse (tiroir d'une session) ou une écriture de
 * trésorerie (Caisse Générale, compte) —, par les services qui portent déjà
 * ces règles : session ouverte, sortie plafonnée au solde.
 */
class DepenseService
{
    public function __construct(
        private readonly CaisseMouvementService $caisseMouvementService,
        private readonly CompteTresorerieService $tresorerieService,
    ) {}

    /**
     * Dépense payée avec les espèces du tiroir d'une session ouverte.
     *
     * @throws \App\Exceptions\SoldeCaisseInsuffisantException si le tiroir ne contient pas la somme
     * @throws RuntimeException si la session est fermée
     */
    public function payerDepuisSession(
        SessionCaisse $session,
        MotifMouvement $motif,
        int $montant,
        User $auteur,
        ?string $beneficiaire = null,
        ?UploadedFile $recu = null,
    ): Depense {
        $this->verifier($motif, $montant);

        $depense = DB::transaction(function () use ($session, $motif, $montant, $auteur, $beneficiaire) {
            $depense = $this->creer($motif, $montant, $auteur, $beneficiaire, [
                'session_caisse_id' => $session->id,
                'magasin_id' => $session->caisse->magasin_id,
            ]);

            $this->caisseMouvementService->enregistrer($session, MouvementCaisseType::Sortie, $montant, $motif->nom, $auteur, reference: $depense);

            return $depense;
        });

        $this->joindreRecu($depense, $recu);

        return $depense;
    }

    /**
     * Dépense payée depuis la Caisse Générale ou un compte de trésorerie.
     * $magasin : le magasin concerné, null pour toute l'entreprise.
     *
     * @throws \App\Exceptions\SoldeTresorerieInsuffisantException si le compte ne contient pas la somme
     */
    public function payerDepuisCompte(
        CompteTresorerie $compte,
        MotifMouvement $motif,
        int $montant,
        User $auteur,
        ?string $beneficiaire = null,
        ?Magasin $magasin = null,
        ?UploadedFile $recu = null,
    ): Depense {
        $this->verifier($motif, $montant);

        $depense = DB::transaction(function () use ($compte, $motif, $montant, $auteur, $beneficiaire, $magasin) {
            $depense = $this->creer($motif, $montant, $auteur, $beneficiaire, [
                'compte_tresorerie_id' => $compte->id,
                'magasin_id' => $magasin?->id,
            ]);

            $this->tresorerieService->debiter($compte, $montant, EcritureCompteTresorerieType::SortieManuelle, $auteur, reference: $depense, motif: $motif->nom);

            return $depense;
        });

        $this->joindreRecu($depense, $recu);

        return $depense;
    }

    /**
     * Annule une dépense saisie par erreur : elle reste en base, marquée
     * annulée, et l'argent est rendu par un mouvement inverse — là d'où il
     * était sorti, sauf si le tiroir a été vidé depuis : une session
     * clôturée a déposé son contenu à la Caisse Générale, c'est donc elle
     * qui reçoit le retour.
     *
     * @throws RuntimeException si la dépense est déjà annulée
     */
    public function annuler(Depense $depense, User $auteur, string $raison): Depense
    {
        return DB::transaction(function () use ($depense, $auteur, $raison) {
            $depense = Depense::whereKey($depense->id)->lockForUpdate()->firstOrFail();

            if ($depense->estAnnulee()) {
                throw new RuntimeException('Cette dépense est déjà annulée.');
            }

            $libelle = "Annulation de la dépense « {$depense->motif} »";
            $session = $depense->sessionCaisse;

            if ($session && $session->date_cloture === null && $session->date_fermeture === null) {
                $this->caisseMouvementService->enregistrer($session, MouvementCaisseType::Entree, $depense->montant, $libelle, $auteur, reference: $depense);
            } else {
                $compte = $depense->compteTresorerie ?? $this->tresorerieService->caisseGenerale();
                $this->tresorerieService->crediter($compte, $depense->montant, EcritureCompteTresorerieType::EntreeManuelle, $auteur, reference: $depense, motif: $libelle);
            }

            $depense->update(['annulee_at' => now(), 'annulee_par' => $auteur->id, 'motif_annulation' => $raison]);

            activity()
                ->causedBy($auteur)
                ->performedOn($depense)
                ->withProperties(['montant' => $depense->montant, 'raison' => $raison])
                ->log("Dépense « {$depense->motif} » annulée");

            return $depense;
        });
    }

    private function verifier(MotifMouvement $motif, int $montant): void
    {
        if (! $motif->est_depense) {
            throw new InvalidArgumentException("Le motif « {$motif->nom} » n'est pas une dépense.");
        }

        if ($montant <= 0) {
            throw new InvalidArgumentException("Le montant d'une dépense doit être positif.");
        }
    }

    /** @param  array<string, mixed>  $source */
    private function creer(MotifMouvement $motif, int $montant, User $auteur, ?string $beneficiaire, array $source): Depense
    {
        $depense = Depense::create($source + [
            'motif' => $motif->nom,
            'motif_mouvement_id' => $motif->id,
            'montant' => $montant,
            'beneficiaire' => filled($beneficiaire) ? trim($beneficiaire) : null,
            'created_by' => $auteur->id,
            'created_at' => now(),
        ]);

        activity()
            ->causedBy($auteur)
            ->performedOn($depense)
            ->withProperties(['montant' => $montant, 'beneficiaire' => $depense->beneficiaire])
            ->log("Dépense « {$motif->nom} » enregistrée");

        return $depense;
    }

    /**
     * La photo du reçu est jointe après la transaction : un souci d'image
     * ne doit jamais empêcher d'enregistrer une dépense déjà payée.
     */
    private function joindreRecu(Depense $depense, ?UploadedFile $recu): void
    {
        if ($recu === null) {
            return;
        }

        try {
            ImageProduit::attacherA($depense, $recu, 'recu');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
