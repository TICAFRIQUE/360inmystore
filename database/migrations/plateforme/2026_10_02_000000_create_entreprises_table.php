<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registre des entreprises clientes de la plateforme (voir CLAUDE.md
     * « Plateforme SaaS »). Table de la base de la PLATEFORME uniquement : ce
     * dossier de migrations n'est jamais exécuté sur la base d'une
     * entreprise.
     */
    public function up(): void
    {
        Schema::create('entreprises', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            // {sous_domaine}.{domaine de la plateforme} : adresse de l'espace.
            $table->string('sous_domaine', 40)->unique();
            // Nom de la base de données dédiée à cette entreprise.
            $table->string('base', 64)->unique();
            $table->string('proprietaire_nom');
            // Format international (voir App\Support\Telephone). Unique : un
            // seul espace par numéro, garde-fou contre la création en série.
            $table->string('telephone', 20)->unique();
            $table->string('pays', 2);
            // creation → active ⇄ suspendue
            $table->string('statut', 20)->default('creation')->index();
            $table->string('motif_suspension')->nullable();
            // Copie de l'échéance, pour la console de l'éditeur uniquement :
            // la source de vérité reste le registre des activations dans la
            // base de l'entreprise (voir App\Models\Abonnement).
            $table->date('abonnement_fin')->nullable();
            $table->boolean('abonnement_illimite')->default(false);
            $table->string('ip_inscription', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entreprises');
    }
};
