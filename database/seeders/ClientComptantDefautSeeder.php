<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

class ClientComptantDefautSeeder extends Seeder
{
    /**
     * Singleton : le client "Client comptant" doit toujours exister, un seul
     * enregistrement marqué comptant_defaut (voir Client::comptantDefaut(),
     * utilisé par RetourVenteService pour créditer l'avoir d'un retour sur
     * une vente comptant sans client identifié).
     *
     * Certaines bases existantes ont déjà un client nommé "Client comptant"
     * créé manuellement (même intention, avant l'existence de ce singleton) :
     * on le PROMEUT (simple pose du flag) plutôt que d'en créer un second,
     * pour ne jamais dupliquer les fiches ni perdre son historique.
     */
    public function run(): void
    {
        if (Client::where('comptant_defaut', true)->exists()) {
            return;
        }

        $existant = Client::whereRaw('LOWER(nom) = ?', ['client comptant'])->first();

        if ($existant) {
            $existant->update(['comptant_defaut' => true]);

            return;
        }

        Client::create(['nom' => 'Client comptant', 'code' => 'CLI-COMPTANT', 'actif' => true, 'comptant_defaut' => true]);
    }
}
