{{-- Catalogue en vignettes, partagé par l'écran de caisse et le devis :
     recherche « commence par » (nom, référence, code-barres), catégories à flèches de
     défilement, vignettes dessinées par paquets. La logique est dans
     avecCatalogue (resources/js/app.js) ; le composant Alpine parent fournit
     produits, panier et ce qui ajoute une ligne. $produits : le catalogue,
     pour l'état « aucun produit ». --}}
<section class="caisse__catalogue card border-0 shadow-sm" aria-label="Produits">
    <div class="caisse__recherche">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" id="produit-recherche" class="form-control" x-model="recherche" autocomplete="off" autofocus
                   placeholder="Rechercher un produit, une référence ou un code-barres…" aria-label="Rechercher un produit"
                   @input="nouvelleRecherche()" @keydown.enter.prevent="validerRecherche()">
        </div>

        {{-- Les catégories tiennent sur une seule rangée : quand elles
             dépassent, une flèche apparaît du côté où il en reste (voir
             rangeeDefilante, resources/js/app.js) — sans elle, rien ne dit
             qu'il y en a d'autres. --}}
        <div class="caisse__categories-zone" x-show="categories.length > 0" x-cloak
             x-data="rangeeDefilante()" x-effect="categories.length; $nextTick(() => mesurer())" @resize.window="mesurer()">
            <button type="button" class="caisse__fleche caisse__fleche--gauche" x-show="! auDebut" x-cloak @click="defiler(-1)" aria-label="Catégories précédentes">
                <i class="bi bi-chevron-left"></i>
            </button>

            <div class="caisse__categories" x-ref="rangee" @scroll.passive="mesurer()" role="group" aria-label="Catégories">
                <button type="button" class="caisse__categorie" :class="{ 'est-active': categorieChoisie === '' }" @click="choisirCategorie('')">Tous</button>
                <template x-for="categorie in categories" :key="categorie">
                    <button type="button" class="caisse__categorie" :class="{ 'est-active': categorieChoisie === categorie }"
                            @click="choisirCategorie(categorie)" x-text="categorie"></button>
                </template>
            </div>

            <button type="button" class="caisse__fleche caisse__fleche--droite" x-show="! aLaFin" x-cloak @click="defiler(1)" aria-label="Catégories suivantes">
                <i class="bi bi-chevron-right"></i>
            </button>
        </div>
    </div>

    <div class="caisse__grille-zone">
        <p class="caisse__compte" x-show="produits.length > 0" x-text="produitsFiltres.length + (produitsFiltres.length > 1 ? ' produits' : ' produit')"></p>

        <div class="caisse__grille">
            <template x-for="produit in produitsAffiches" :key="produit.id">
                <button type="button" class="produit-tuile" :class="{ 'produit-tuile--rupture': ! estDisponible(produit) }"
                        :disabled="! estDisponible(produit)" @click="ajouterProduit(produit)"
                        :title="estDisponible(produit) ? 'Ajouter' : 'Rupture de stock'">
                    <span class="produit-tuile__visuel" :class="{ 'produit-tuile__visuel--vide': ! produit.image }">
                        <template x-if="produit.image">
                            <img :src="produit.image" alt="" loading="lazy" decoding="async">
                        </template>
                        <template x-if="!produit.image">
                            <i class="bi bi-box-seam"></i>
                        </template>
                        <span class="produit-tuile__compteur" x-show="quantiteAuPanier(produit.id) > 0" x-text="quantiteAuPanier(produit.id)"></span>
                    </span>
                    <span class="produit-tuile__nom" x-text="produit.libelle_affichage"></span>
                    <span class="produit-tuile__prix" x-text="montant(produit.prix_piece)"></span>
                    {{-- Le stock n'est connu que là où il compte (la caisse) ; sur un
                         devis, la vignette ne l'affiche pas. --}}
                    <span class="produit-tuile__stock" x-show="produit.stock !== undefined"
                          x-text="produit.stock < 1 ? 'Rupture de stock' : 'Stock : ' + produit.stock + (produit.unite_base_libelle ? ' ' + produit.unite_base_libelle : '')"></span>
                </button>
            </template>
        </div>

        <div class="etat-vide" x-show="produits.length > 0 && produitsFiltres.length === 0" x-cloak>
            <i class="bi bi-search"></i>
            <p>Aucun produit ne correspond à votre recherche.</p>
        </div>

        {{-- Catalogue vide : rien à vendre tant qu'aucun produit n'est
             enregistré — y conduire plutôt que de laisser l'écran vide. --}}
        @if ($produits->isEmpty())
            <div class="etat-vide etat-vide--grand">
                <i class="bi bi-box-seam"></i>
                <h3 class="h5">Aucun produit dans le catalogue</h3>
                <p>Enregistrez vos produits : ils apparaîtront ici.</p>
                @can('produit.creer')
                    <a href="{{ route('produits.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Ajouter un produit</a>
                @else
                    <p class="fw-medium">Demandez à votre responsable d'enregistrer les produits.</p>
                @endcan
            </div>
        @endif

        <div class="text-center mt-3" x-show="produitsFiltres.length > produitsAffiches.length" x-cloak>
            <button type="button" class="btn btn-outline-secondary" @click="afficherPlus()">
                Afficher plus (<span x-text="produitsFiltres.length - produitsAffiches.length"></span> restants)
            </button>
        </div>
    </div>
</section>
