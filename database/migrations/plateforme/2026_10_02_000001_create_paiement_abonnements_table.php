<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registre immuable des paiements d'abonnement reçus par la plateforme
     * (réussis comme refusés) : aucun UPDATE/DELETE applicatif, même principe
     * que les écritures de compte. Les caractéristiques de la formule sont
     * recopiées (nom, durée, prix) : un paiement doit rester lisible même si
     * la formule est modifiée ou supprimée ensuite.
     */
    public function up(): void
    {
        Schema::create('paiement_abonnements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises');
            $table->string('formule_nom');
            $table->unsignedInteger('jours')->nullable();
            $table->boolean('illimite')->default(false);
            $table->unsignedInteger('montant');
            // Moyen choisi par le client (mobile_money, carte…).
            $table->string('moyen', 30);
            // Pilote ayant traité le paiement (« simulation », puis le
            // prestataire réel) — permet d'écarter les paiements simulés
            // d'un chiffre d'affaires réel.
            $table->string('passerelle', 30);
            $table->string('reference')->unique();
            $table->string('statut', 20)->index(); // reussi | refuse
            $table->string('payeur')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiement_abonnements');
    }
};
