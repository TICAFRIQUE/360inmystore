import * as bootstrap from 'bootstrap';
window.bootstrap = bootstrap;

import Alpine from 'alpinejs';
window.Alpine = Alpine;

import { Chart, registerables } from 'chart.js';
Chart.register(...registerables);
window.Chart = Chart;

// jQuery n'est chargé que pour DataTables.net et Select2 (aucune des deux
// n'a d'équivalent mûr sans jQuery) — le reste de l'app reste en Alpine.js.
import jQuery from 'jquery';
window.$ = window.jQuery = jQuery;

import 'datatables.net-bs5';

// Le module select2 exporte sa fonction d'attache UMD (factory(root, jQuery))
// plutôt que de s'auto-attacher : sous bundling ESM/CJS il faut l'appeler
// explicitement, sinon $.fn.select2 n'existe jamais silencieusement.
import select2Factory from 'select2';
select2Factory(window, jQuery);
import 'select2/dist/css/select2.min.css';
import 'select2-bootstrap-5-theme/dist/select2-bootstrap-5-theme.min.css';

// "Commence par", pas "contient" : demandé côté client pour retrouver un
// produit en tapant la première lettre de son nom (ex. taper "c" charge tout
// ce qui commence par "c"). Un libellé "Nom — Libellé distinctif" est coupé
// en segments pour que chacun puisse matcher indépendamment (taper "c" doit
// aussi trouver un produit dont seul le libellé distinctif commence par "c",
// pas seulement le nom).
function matcherCommencePar(params, data) {
    const terme = (params.term || '').trim().toLocaleLowerCase();

    if (terme === '' || !data.text) {
        return data;
    }

    const correspond = data.text
        .toLocaleLowerCase()
        .split(' — ')
        .some((segment) => segment.trim().startsWith(terme));

    return correspond ? data : null;
}

// Arrondi à 3 décimales (précision des colonnes decimal(12,3), voir
// App\Support\Arrondi::quantite() côté serveur) — évite qu'une imprécision
// flottante JS (ex. 0.1 + 0.2) ne s'affiche ou ne se compare de travers
// avant que le serveur ne revalide de toute façon.
function arrondirQuantite(valeur) {
    return Math.round(valeur * 1000) / 1000;
}

// Rendu des options d'un select produit portant un attribut data-stock (ex.
// bon de commande) : affiche le stock restant en retrait, italique, couleur
// atténuée, sous le libellé normal du produit — jamais dans templateSelection
// (select fermé), pour ne pas alourdir l'affichage une fois choisi.
window.formaterOptionAvecStock = function (data) {
    if (!data.id || ! data.element) {
        return data.text;
    }

    const stock = data.element.dataset.stock;
    if (stock === undefined) {
        return data.text;
    }

    const $resultat = jQuery('<div></div>').text(data.text);
    jQuery('<div></div>')
        .addClass('small fst-italic text-secondary')
        .text('Stock restant : ' + stock)
        .appendTo($resultat);

    return $resultat;
};

// Sélecteur recherchable (produits, etc.) — thème Bootstrap 5 cohérent avec le
// reste de l'app. Un seul point d'appel pour ne pas répéter les options.
window.initSelect2 = function (selector, options = {}) {
    const $select = jQuery(selector).select2({
        theme: 'bootstrap-5',
        width: '100%',
        matcher: matcherCommencePar,
        language: {
            noResults: () => 'Aucun résultat',
            searching: () => 'Recherche…',
            inputTooShort: () => 'Continuez à taper…',
        },
        ...options,
    });

    // Select2 met à jour le <select> natif puis fait jQuery(...).trigger('change'),
    // qui ne notifie que les écouteurs jQuery — pas les addEventListener('change', …)
    // natifs utilisés par Alpine (x-model). On redispatche donc un vrai événement
    // natif pour que la réactivité Alpine (ex. détection de doublon en temps réel)
    // voie la sélection.
    $select.on('select2:select select2:unselect select2:clear', function () {
        this.dispatchEvent(new Event('change', { bubbles: true }));
    });

    return $select;
};

// Traduction française réutilisée par tous les tableaux DataTables de l'app.
window.dataTableFrLang = {
    processing: 'Traitement en cours…',
    search: 'Rechercher :',
    lengthMenu: 'Afficher _MENU_ éléments',
    info: 'Affichage de _START_ à _END_ sur _TOTAL_ éléments',
    infoEmpty: 'Affichage de 0 à 0 sur 0 élément',
    infoFiltered: '(filtré depuis _MAX_ éléments au total)',
    loadingRecords: 'Chargement…',
    zeroRecords: 'Aucun élément trouvé',
    emptyTable: 'Aucune donnée disponible',
    paginate: { first: 'Premier', previous: 'Précédent', next: 'Suivant', last: 'Dernier' },
};

/**
 * État du panier de l'écran de vente (POS). Le stock disponible est vérifié
 * côté client pour griser en amont (voir CLAUDE.md), le serveur revalide tout
 * de toute façon dans VenteService avant d'écrire le moindre mouvement.
 */
/**
 * Rangée qui défile horizontalement (x-ref="rangee") : dit s'il reste du
 * contenu caché à gauche ou à droite, pour n'afficher une flèche que du côté
 * où elle sert — et aucune quand tout tient à l'écran.
 */
window.rangeeDefilante = function () {
    return {
        auDebut: true,
        aLaFin: true,

        mesurer() {
            const rangee = this.$refs.rangee;
            if (!rangee) return;

            // 1 px de tolérance : une position de défilement n'est pas
            // toujours entière (zoom du navigateur, écran haute densité).
            this.auDebut = rangee.scrollLeft <= 1;
            this.aLaFin = rangee.scrollLeft + rangee.clientWidth >= rangee.scrollWidth - 1;
        },

        // Avance ou recule des trois quarts de la largeur visible : la
        // dernière catégorie aperçue reste à l'écran, comme repère.
        defiler(sens) {
            const rangee = this.$refs.rangee;
            rangee.scrollBy({ left: sens * rangee.clientWidth * 0.75 });
        },
    };
};

// Nombre de vignettes de produit dessinées d'un coup sur l'écran de caisse.
const PRODUITS_PAR_PAGE = 36;

// « Câble Électrique » → « cable electrique » : recherche insensible aux
// accents et à la casse.
const sansAccents = (texte) => String(texte ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

/**
 * Catalogue en vignettes, partagé par l'écran de caisse (posApp) et le devis
 * (devisApp) — voir partials/catalogue-vignettes.blade.php : recherche,
 * catégories, vignettes dessinées par paquets, ajout au panier.
 *
 * Posé sur l'objet du composant par ses descripteurs de propriétés : un
 * simple « ...catalogue » figerait les accesseurs (get) à leur valeur du
 * moment au lieu de les garder réactifs.
 *
 * options.disponible(produit) : le produit peut-il être ajouté ? À la caisse,
 * non s'il est en rupture ; sur un devis, toujours.
 */
function avecCatalogue(composant, { disponible }) {
    return Object.defineProperties(composant, Object.getOwnPropertyDescriptors({
        recherche: '',
        categorieChoisie: '',
        limiteProduits: PRODUITS_PAR_PAGE,

        get categories() {
            return [...new Set(this.produits.map((p) => p.categorie).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'fr'));
        },

        // « Commence par », pas « contient » — comme les sélecteurs de produit
        // (matcherCommencePar) : taper « b » montre tous les produits dont le
        // nom commence par B, puis ceux dont seul le libellé distinctif
        // commence par B. La référence et le code-barres ne comptent que si la
        // saisie contient un chiffre : sinon taper « p » ramènerait tout le
        // catalogue par ses références « PRD-… ». Sans tenir compte des
        // accents ni de la casse (« cable » trouve « Câble »). Dans chaque
        // groupe, l'ordre du catalogue (alphabétique) est gardé.
        get produitsFiltres() {
            const saisie = sansAccents(this.recherche).replace(/\s+/g, ' ');
            const produits = this.categorieChoisie
                ? this.produits.filter((p) => p.categorie === this.categorieChoisie)
                : this.produits;

            if (!saisie) return produits;

            const chercheUnCode = /\d/.test(saisie);
            const rang = (p) => {
                if (sansAccents(p.nom).startsWith(saisie)) return 0;
                if (sansAccents(p.libelle_distinctif).startsWith(saisie)) return 1;
                if (chercheUnCode && [p.sku, p.code_barre].some((code) => sansAccents(code).startsWith(saisie))) return 2;
                return null;
            };

            return produits
                .map((p) => ({ p, rang: rang(p) }))
                .filter(({ rang }) => rang !== null)
                .sort((a, b) => a.rang - b.rang)
                .map(({ p }) => p);
        },

        // Jamais tout le catalogue à l'écran : seules les premières vignettes
        // sont dessinées, le reste à la demande (« Afficher plus »).
        get produitsAffiches() {
            return this.produitsFiltres.slice(0, this.limiteProduits);
        },

        // Une nouvelle recherche ou une autre catégorie repart du début de la liste.
        nouvelleRecherche() {
            this.limiteProduits = PRODUITS_PAR_PAGE;
        },

        choisirCategorie(categorie) {
            this.categorieChoisie = categorie;
            this.limiteProduits = PRODUITS_PAR_PAGE;
        },

        afficherPlus() {
            this.limiteProduits += PRODUITS_PAR_PAGE;
        },

        estDisponible(produit) {
            return disponible(produit);
        },

        // Quantité déjà au panier pour ce produit, toutes lignes confondues —
        // rappelée sur sa vignette.
        quantiteAuPanier(produitId) {
            return arrondirQuantite(this.panier.filter((l) => l.produit_id === produitId).reduce((somme, l) => somme + l.quantite, 0));
        },

        // Touche Entrée dans la recherche : une référence ou un code-barres
        // exact (douchette), ou un seul produit restant à l'écran, s'ajoute
        // directement — sans quitter le clavier.
        validerRecherche() {
            const saisie = this.recherche.trim().toLowerCase();
            if (!saisie) return;

            const exact = this.produits.find((p) => [p.sku, p.code_barre].some((code) => code && String(code).toLowerCase() === saisie));
            const produit = exact || (this.produitsFiltres.length === 1 ? this.produitsFiltres[0] : null);
            if (!produit || !this.estDisponible(produit)) return;

            this.ajouterProduit(produit);
            this.recherche = '';
            this.nouvelleRecherche();
        },

        // Un clic sur une vignette. Un produit indisponible n'est jamais
        // ajouté (vignette grisée, voir CLAUDE.md). Quand le produit n'a
        // qu'une seule unité possible — son unité de base, sans unité de
        // vente —, il n'y a rien à choisir : l'unité est posée d'office, et
        // un nouveau clic augmente la quantité de la ligne au lieu d'en
        // ouvrir une seconde. À la caisse, le lieu de prélèvement reste, lui,
        // toujours un choix explicite (voir chargerSources).
        ajouterProduit(produit) {
            if (!this.estDisponible(produit)) return;

            if (produit.unites.length > 0) {
                this.ajouterLigneVierge(produit);
                return;
            }

            const existante = this.panier.find((l) => l.produit_id === produit.id);
            if (existante) {
                this.changerQuantite(existante, 1);
                return;
            }

            this.ajouterLigneVierge(produit);
            this.changerVarianteDepuisSelect(this.panier[this.panier.length - 1], 'piece');
        },

        // Montant lisible (séparateur de milliers, devise) — affichage seul,
        // jamais une valeur envoyée au serveur.
        montant(valeur) {
            return `${new Intl.NumberFormat('fr-FR').format(Number(valeur) || 0)} ${window.DEVISE_ABREVIATION}`;
        },
    }));
}

window.posApp = function (produits, panierInitial = [], libelleInitial = '', clientIdInitial = '', magasinCaisseId = null, magasinCaisseNom = '', clientSoldes = {}, taxes = []) {
    return avecCatalogue({
        produits,
        taxes,
        panier: panierInitial,
        // Facture (par défaut) ou bon de livraison (livré automatiquement,
        // voir CLAUDE.md) — sélecteur visible uniquement avec la permission
        // vente.bon_livraison (voir ventes/create.blade.php), sinon toujours
        // 'facture' (revérifié aussi côté serveur, ValideRemises::bloquerTypeSansPermission).
        type: 'facture',
        remiseTotaleType: '',
        remiseTotaleValeur: null,
        paiements: [{ moyen_paiement_id: '', montant: null }],
        libelleAttente: libelleInitial,
        // Client sélectionné pour une vente à crédit (voir CLAUDE.md — vente à
        // crédit). Vide = vente comptant, payée intégralement comme avant.
        // Pré-rempli et figé lors de la transformation d'un devis (le client
        // vient toujours du devis, jamais d'un choix libre à cet écran).
        clientId: clientIdInitial,

        // Solde de chaque client (clé = id, valeur = somme des écritures,
        // voir CLAUDE.md règle 12) — un solde négatif est un avoir. Ne sert
        // qu'à informer/faciliter la saisie ici : le serveur applique déjà
        // l'avoir automatiquement à toute nouvelle vente à crédit (le solde
        // n'est qu'une somme d'écritures), ces getters ne font qu'anticiper
        // ce même calcul pour ne pas laisser le caissier croire qu'une
        // facture couverte par un avoir est une "vraie" dette.
        clientSoldes,

        get avoirDisponible() {
            if (!this.clientId) return 0;
            const solde = Number(this.clientSoldes[this.clientId] ?? 0);
            return solde < 0 ? -solde : 0;
        },

        // Part de l'avoir qui couvrirait cette facture si elle était payée
        // comptant à 0 F — plafonnée au net à payer (jamais plus que la
        // facture elle-même).
        get avoirApplicable() {
            return Math.min(this.avoirDisponible, this.totalNet);
        },

        get netApresAvoir() {
            return Math.max(0, this.totalNet - this.avoirApplicable);
        },

        // Part de l'avoir qui couvre réellement l'écart encore ouvert une
        // fois les paiements déjà saisis pris en compte (contrairement à
        // avoirApplicable, qui ignore les paiements pour la simple
        // information du bloc client ci-dessus).
        get avoirUtiliseSurCetteVente() {
            return Math.min(this.avoirDisponible, Math.max(0, this.totalNet - this.totalPaiements));
        },

        get resteApresAvoir() {
            return Math.max(0, this.totalNet - this.totalPaiements - this.avoirDisponible);
        },

        // Pré-remplit le montant réellement à collecter (net de l'avoir),
        // sur le premier moyen de paiement — jamais l'avoir lui-même : ce
        // n'est pas un moyen de paiement (il n'entre jamais dans le
        // comptage du tiroir), juste une écriture qui se compense
        // automatiquement côté serveur (voir CLAUDE.md, avoir client).
        appliquerAvoir() {
            if (!this.paiements.length) this.ajouterPaiement();
            const autres = this.totalPaiements - (Number(this.paiements[0].montant) || 0);
            this.paiements[0].montant = Math.max(this.netApresAvoir - autres, 0);
        },

        get messageConfirmationVente() {
            if (!this.clientId || this.totalPaiements >= this.totalNet) {
                return 'Enregistrer cette vente ? Le stock sera mis à jour et le paiement enregistré. Cette action est irréversible.';
            }
            if (this.avoirUtiliseSurCetteVente > 0) {
                return this.resteApresAvoir > 0
                    ? `Enregistrer cette vente à crédit ? ${this.avoirUtiliseSurCetteVente} F seront couverts par l'avoir du client, il restera ${this.resteApresAvoir} F de solde à crédit sur son compte. Cette action est irréversible.`
                    : `Enregistrer cette vente ? Elle sera entièrement couverte par l'avoir du client (${this.avoirUtiliseSurCetteVente} F), aucune dette ne sera créée. Cette action est irréversible.`;
            }
            return 'Enregistrer cette vente à crédit ? Le stock sera mis à jour et le solde restant sera porté au compte du client. Cette action est irréversible.';
        },

        // Lieu de prélèvement du stock, par ligne (voir CLAUDE.md — un
        // produit peut être vendu depuis un magasin ou un dépôt différent du
        // magasin de la caisse). Choix obligatoire, aucun lieu par défaut :
        // magasin_source_id === undefined tant que rien n'a été choisi (voir
        // aUneSourceNonChoisie). Le détail par lieu n'est chargé qu'à la
        // demande, jamais préchargé pour tous les produits (chargerSources).
        magasinCaisseId,
        magasinCaisseNom,
        sourcesParProduit: {},

        // Une ligne peut arriver avec un lieu déjà choisi (reprise d'une
        // vente en attente, ou panier restauré après une soumission en
        // échec) : sans ce préchargement, sourcesDisponibles(ligne) reste
        // vide tant que l'utilisateur n'a pas cliqué le select — l'option
        // déjà sélectionnée n'existe alors pas encore dans le DOM et
        // s'affiche comme "non choisie". chargerSources() ne fait rien si
        // le produit est déjà en cache, donc sans coût pour les lignes vierges.
        init() {
            this.panier.forEach((ligne) => {
                if (ligne.magasin_source_id !== undefined) {
                    this.chargerSources(ligne);
                }
            });
        },

        async chargerSources(ligne) {
            const produitId = ligne.produit_id;
            if (this.sourcesParProduit[produitId]) return;

            try {
                const response = await fetch(`/produits/${produitId}/stock-magasins`, {
                    headers: { Accept: 'application/json' },
                });
                if (!response.ok) return;
                this.sourcesParProduit[produitId] = await response.json();
            } catch {
                // Silencieux : la liste des lieux reste vide, le choix reste à faire.
            }
        },

        // Tous les lieux actifs pour ce produit, y compris le magasin de la
        // caisse : le choix doit toujours être explicite, aucun lieu n'est
        // pré-filtré/sous-entendu par défaut.
        sourcesDisponibles(ligne) {
            return this.sourcesParProduit[ligne.produit_id] || [];
        },

        // Change juste le lieu affiché/soumis pour cette ligne — ne touche
        // jamais à la quantité ni ne retire la ligne : le stock du nouveau
        // lieu reste visible (voir "Stock : N" et stockDisponible ci-dessous)
        // pour que le vendeur voie et décide, la vérification qui bloque
        // réellement se fait côté serveur à l'enregistrement (atomique, voir
        // VenteService::vendre()).
        choisirSource(ligne, valeur) {
            if (!valeur) {
                ligne.magasin_source_id = undefined;
                ligne.magasinSourceNom = null;
            } else {
                const source = this.sourcesDisponibles(ligne).find((s) => String(s.id) === String(valeur));
                ligne.magasin_source_id = Number(valeur);
                ligne.magasinSourceNom = source ? source.nom : null;
            }
        },

        // Stock du lieu choisi pour cette ligne — null tant qu'aucun lieu
        // n'a été explicitement sélectionné (voir aUneSourceNonChoisie).
        stockDisponible(ligne) {
            if (ligne.magasin_source_id === undefined) return null;

            const source = this.sourcesDisponibles(ligne).find((s) => s.id === ligne.magasin_source_id);
            return source ? source.quantite : 0;
        },

        // La ligne (cumulée avec les autres lignes du même produit prélevant
        // à la même source, voir piecesReservees) demande plus que ce qui
        // est disponible au lieu choisi — n'a de sens qu'une fois un lieu
        // explicitement sélectionné.
        enRupture(ligne) {
            if (ligne.magasin_source_id === undefined) return false;
            const dispo = this.stockDisponible(ligne);
            const facteur = ligne.facteur || 1;
            const autresPieces = this.piecesReservees(ligne.produit_id, ligne.magasin_source_id) - ligne.quantite * facteur;
            return autresPieces + ligne.quantite * facteur > dispo;
        },

        get aUneSourceNonChoisie() {
            return this.panier.some((l) => l.magasin_source_id === undefined);
        },

        get aUneLigneEnRupture() {
            return this.panier.some((l) => this.enRupture(l));
        },

        // Une ligne « vierge » (aucune variante choisie, aucun calcul) —
        // l'utilisateur choisit ensuite pièce/lot directement sur la ligne
        // (voir changerVarianteDepuisSelect), et c'est CE choix qui déclenche
        // prix, réservation de stock et vérification de doublon.
        ajouterLigneVierge(produit) {
            // On ne fusionne que sur une ligne du même produit qui n'a pas
            // encore de variante choisie : une fois une variante assignée, une
            // nouvelle sélection du même produit ouvre une ligne indépendante.
            const existante = this.panier.find((l) => l.produit_id === produit.id && l.unite_vente_id === undefined);
            if (existante) {
                existante.quantite += 1;
                return;
            }

            this.panier.push({
                produit_id: produit.id,
                unite_vente_id: undefined,
                taxe_id: '',
                produitLibelle: produit.libelle_affichage,
                uniteLibelle: null,
                facteur: null,
                quantite: 1,
                prixUnitaire: null,
                remise_type: '',
                remise_valeur: null,
                prixPersonnalise: false,
                prixSaisi: null,
                magasin_source_id: undefined,
                magasinSourceNom: null,
            });
        },

        // magasinSourceId : undefined tant qu'aucun lieu n'a été choisi. Deux
        // lignes du même produit prélevant sur des lieux différents ont des
        // stocks indépendants, donc jamais cumulées entre elles.
        piecesReservees(produitId, magasinSourceId = null) {
            return this.panier
                .filter((l) => l.produit_id === produitId && (l.magasin_source_id || null) === (magasinSourceId || null))
                .reduce((somme, l) => somme + l.quantite * (l.facteur || 0), 0);
        },

        // Deux lignes ne doivent jamais partager le même produit, la même
        // unité (pièce ou un lot précis) ET la même source de prélèvement à
        // la fois — ça peut arriver en dupliquant une ligne puis en
        // reprenant la même variante. Deux lignes du même produit/unité mais
        // prélevées sur des lieux différents restent légitimes (voir
        // piecesReservees, qui traite déjà ces stocks comme indépendants).
        // Tant qu'une ligne n'a pas encore de variante ou de source choisie,
        // elle n'est jamais comptée comme doublon (rien à comparer). On
        // bloque l'enregistrement tant que ce n'est pas corrigé (voir
        // aUnDoublon / aUneLigneNonChoisie).
        estDoublon(index) {
            const ligne = this.panier[index];
            if (ligne.unite_vente_id === undefined || ligne.magasin_source_id === undefined) return false;
            return this.panier.some(
                (l, i) => i !== index
                    && l.produit_id === ligne.produit_id
                    && l.unite_vente_id === ligne.unite_vente_id
                    && l.magasin_source_id === ligne.magasin_source_id
            );
        },

        get aUnDoublon() {
            return this.panier.some((l, i) => this.estDoublon(i));
        },

        get aUneLigneNonChoisie() {
            return this.panier.some((l) => l.unite_vente_id === undefined);
        },

        produitDe(ligne) {
            return this.produits.find((p) => p.id === ligne.produit_id);
        },

        changerVarianteDepuisSelect(ligne, valeur) {
            const produit = this.produitDe(ligne);
            if (!produit) return;

            if (valeur === '') {
                ligne.unite_vente_id = undefined;
                ligne.uniteLibelle = null;
                ligne.facteur = null;
                ligne.prixUnitaire = null;
                ligne.prixPersonnalise = false;
                ligne.prixSaisi = null;
                return;
            }

            // Toujours appliqué, même si ça dépasse le stock à la source
            // choisie : le choix ne doit jamais être silencieusement rejeté
            // (le <select> resterait visuellement sur la nouvelle option
            // sans que l'état ne suive, ce qui bloquait à tort avec le
            // message générique « Choisissez une unité pour chaque ligne »).
            // Le dépassement est signalé clairement par enRupture ci-dessus,
            // qui bloque la finalisation via aUneLigneEnRupture.
            const unite = valeur === 'piece' ? null : produit.unites.find((u) => u.id === Number(valeur));
            ligne.unite_vente_id = unite ? unite.id : null;
            ligne.uniteLibelle = unite ? unite.libelle : null;
            ligne.facteur = unite ? unite.facteur : 1;
            ligne.prixUnitaire = unite ? unite.prix : produit.prix_piece;
            // Le prix personnalisé était relatif à l'ancien prix catalogue
            // (pièce vs lot) : remis à zéro pour ne jamais garder une valeur
            // devenue incohérente après un changement de variante.
            ligne.prixPersonnalise = false;
            ligne.prixSaisi = null;
        },

        // Le <select> Remise propose "Prix personnalisé" comme 4e option, aux
        // côtés de Sans remise/Remise (F)/Remise (%) — un seul select pour
        // les deux mécanismes, jamais deux champs concurrents à l'écran.
        choisirTypeRemise(ligne, valeur) {
            if (valeur === 'prix') {
                ligne.prixPersonnalise = true;
                ligne.remise_type = '';
                ligne.remise_valeur = null;
                // Pré-rempli avec le prix catalogue au premier choix, jamais
                // écrasé si le caissier revient sur "Prix personnalisé" après
                // avoir déjà tapé une valeur.
                if (ligne.prixSaisi === null) ligne.prixSaisi = ligne.prixUnitaire;
                return;
            }
            ligne.prixPersonnalise = false;
            ligne.remise_type = valeur;
        },

        // À 1, "−" ne fait plus rien (bouton désactivé côté vue, voir
        // atteintMin ci-dessous) : retirer une ligne est un geste séparé et
        // explicite (bouton corbeille, retirerLigne()), jamais une
        // conséquence accidentelle d'un clic de trop sur "−".
        changerQuantite(ligne, delta) {
            const nouvelle = arrondirQuantite(ligne.quantite + delta);
            if (nouvelle < 0.001) return;
            const dispo = this.stockDisponible(ligne);
            if (dispo !== null) {
                const autresPieces = this.piecesReservees(ligne.produit_id, ligne.magasin_source_id) - ligne.quantite * (ligne.facteur || 0);
                if (autresPieces + nouvelle * (ligne.facteur || 0) > dispo) return;
            }
            ligne.quantite = nouvelle;
        },

        atteintMin(ligne) {
            return ligne.quantite <= 0.001;
        },

        // Saisie manuelle (en plus des boutons −/+), déclenchée à chaque
        // frappe (@input) pour agir immédiatement, sans attendre un clic
        // ailleurs — même plafond de stock que changerQuantite(), mais
        // résolu directement en quantité maximale plutôt que testée un
        // incrément à la fois (sinon taper une grande quantité, ex. 50,
        // obligerait à cliquer "+" 50 fois). Un champ vide/en cours de
        // frappe (parseFloat non fini) ne force rien : on laisse taper
        // plutôt que de faire sauter le champ à une valeur à chaque touche
        // effacée. Un point OU une virgule sont acceptés (habitude locale
        // variable), toujours ramenés au point avant parseFloat.
        // `input` (l'élément DOM, passé par @input) est mis à jour
        // explicitement : si la quantité tapée (ex. "0") retombe sur la
        // MÊME valeur déjà en mémoire (ex. déjà 1), Alpine ne redéclenche
        // pas la liaison :value (aucun changement détecté côté modèle) et
        // le champ resterait visuellement sur "0" tapé alors que la
        // quantité réelle est bien 1 — d'où ce forçage manuel.
        definirQuantite(ligne, valeur, input = null) {
            const nombre = parseFloat(String(valeur).replace(',', '.'));
            if (!Number.isFinite(nombre)) return;
            let nouvelle = Math.max(0.001, arrondirQuantite(nombre));

            const dispo = this.stockDisponible(ligne);
            if (dispo !== null) {
                const facteur = ligne.facteur || 1;
                const autresPieces = this.piecesReservees(ligne.produit_id, ligne.magasin_source_id) - ligne.quantite * facteur;
                const maxQuantite = Math.max(0.001, arrondirQuantite((dispo - autresPieces) / facteur));
                nouvelle = Math.min(nouvelle, maxQuantite);
            }

            ligne.quantite = nouvelle;
            if (input) input.value = nouvelle;
        },

        // Filet de sécurité au blur (@blur) : un champ resté vide/invalide
        // (tout effacé sans retaper) retombe sur 1, jamais une quantité
        // indéfinie — definirQuantite() n'y touche pas tant qu'on tape.
        // Même forçage explicite du DOM que definirQuantite() ci-dessus.
        validerQuantite(ligne, valeur, input = null) {
            if (!Number.isFinite(parseFloat(String(valeur).replace(',', '.')))) {
                ligne.quantite = 1;
                if (input) input.value = 1;
            }
        },

        // Désactive le bouton "+" une fois le stock du lieu choisi atteint —
        // pas de lieu choisi = pas de plafond à ce stade (voir changerQuantite).
        atteintMaxStock(ligne) {
            const dispo = this.stockDisponible(ligne);
            if (dispo === null) return false;
            const facteur = ligne.facteur || 1;
            const autresPieces = this.piecesReservees(ligne.produit_id, ligne.magasin_source_id) - ligne.quantite * facteur;
            return autresPieces + (ligne.quantite + 1) * facteur > dispo;
        },

        retirerLigne(index) {
            this.panier.splice(index, 1);
        },

        totalLigne(ligne) {
            if (ligne.prixUnitaire === null) return 0;
            const sousTotal = ligne.prixUnitaire * ligne.quantite;
            if (ligne.prixPersonnalise) {
                return this.prixPersonnaliseClamp(ligne) * ligne.quantite;
            }
            return sousTotal - this.calculerRemise(ligne.remise_type, ligne.remise_valeur, sousTotal);
        },

        // Un prix personnalisé peut aussi bien être inférieur (remise) que
        // supérieur (majoration) au prix catalogue — seul un prix négatif
        // n'a pas de sens, plafonné ici plutôt que dans l'input pour rester
        // cohérent même si le champ affiche encore l'ancienne valeur tapée.
        prixPersonnaliseClamp(ligne) {
            const prix = Number(ligne.prixSaisi) || 0;
            return Math.max(0, prix);
        },

        // Remise "montant" équivalente au prix personnalisé (négative si le
        // prix saisi majore le catalogue — voir Remise::resoudre() et
        // ValideRemises::remiseValeurMin() côté serveur, qui l'autorisent
        // explicitement pour une ligne prix_personnalise), recalculée à
        // chaque appel (donc toujours à jour si la quantité change) — envoyée
        // au serveur exactement comme une remise classique (voir
        // ventes/create.blade.php, hidden inputs remise_type/remise_valeur).
        remiseValeurEffective(ligne) {
            if (!ligne.prixPersonnalise) return ligne.remise_valeur;
            return (ligne.prixUnitaire - this.prixPersonnaliseClamp(ligne)) * ligne.quantite;
        },

        calculerRemise(type, valeur, base) {
            if (!type || !valeur) return 0;
            const montant = type === 'pourcentage' ? Math.round((base * valeur) / 100) : Number(valeur);
            return Math.min(montant, base);
        },

        get sousTotal() {
            return this.panier.reduce((somme, l) => somme + this.totalLigne(l), 0);
        },

        tauxLigne(ligne) {
            const taxe = this.taxes.find((t) => String(t.id) === String(ligne.taxe_id || ''));
            return taxe ? taxe.taux : 0;
        },

        // totalLigne(ligne) est déjà HT (remise/prix personnalisé appliqués) :
        // la taxe s'ajoute par-dessus, jamais recalculée dessus une seconde
        // fois — même principe que côté achat (montantTtcLigne).
        montantTtcLigne(ligne) {
            const ht = this.totalLigne(ligne);
            return Math.round(ht + ht * this.tauxLigne(ligne) / 100);
        },

        get totalTaxes() {
            return this.panier.reduce((somme, l) => somme + (this.montantTtcLigne(l) - this.totalLigne(l)), 0);
        },

        // La remise totale porte sur le TTC (sous-total + taxes), pas
        // seulement le HT — voir VenteService::vendre() côté serveur, même
        // calcul reproduit ici pour que le total affiché corresponde
        // exactement à ce qui sera enregistré.
        get remiseTotaleMontant() {
            return this.calculerRemise(this.remiseTotaleType, this.remiseTotaleValeur, this.sousTotal + this.totalTaxes);
        },

        get totalNet() {
            return this.sousTotal + this.totalTaxes - this.remiseTotaleMontant;
        },

        get totalPaiements() {
            return this.paiements.reduce((somme, p) => somme + (Number(p.montant) || 0), 0);
        },

        // Un montant saisi sans moyen de paiement choisi échouerait silencieusement
        // à l'enregistrement (validation serveur), donc on bloque en amont.
        get aUnPaiementSansMoyen() {
            return this.paiements.some((p) => (Number(p.montant) || 0) > 0 && !p.moyen_paiement_id);
        },

        ajouterPaiement() {
            this.paiements.push({ moyen_paiement_id: '', montant: null });
        },

        retirerPaiement(index) {
            if (this.paiements.length > 1) this.paiements.splice(index, 1);
        },

        completerPaiement(index) {
            const reste = this.totalNet - (this.totalPaiements - (Number(this.paiements[index].montant) || 0));
            this.paiements[index].montant = Math.max(reste, 0);
        },

        // Le client peut donner plus que le net à payer (monnaie à rendre) :
        // ce qui est réellement enregistré comme encaissement ne doit jamais
        // dépasser le net à payer, sinon le tiroir-caisse serait faussé à la
        // clôture (voir CLAUDE.md, règle du comptage espèces). Les montants
        // saisis servent au calcul de la monnaie à rendre, mais c'est cette
        // liste "plafonnée" qui est réellement envoyée au serveur.
        get monnaieARendre() {
            return Math.max(this.totalPaiements - this.totalNet, 0);
        },

        get paiementsAppliques() {
            let restant = this.totalNet;
            const resultats = [];
            for (const p of this.paiements) {
                const montant = Number(p.montant) || 0;
                if (montant <= 0 || restant <= 0) continue;
                const applique = Math.min(montant, restant);
                resultats.push({ moyen_paiement_id: p.moyen_paiement_id, montant: applique });
                restant -= applique;
            }
            return resultats;
        },

        declencherFinalisation(event) {
            const form = document.getElementById('formVente');
            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                form.reportValidity();
                return;
            }
            window.bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmActionModal')).show(event.currentTarget);
        },

        declencherMiseEnAttente(event) {
            const form = document.getElementById('formAttente');
            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                form.reportValidity();
                return;
            }
            window.bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmActionModal')).show(event.currentTarget);
        },
    }, { disponible: (produit) => produit.stock >= 1 });
};

/**
 * Constructeur de lignes pour l'écran Devis : même logique de panier que
 * posApp (ajout/duplication/doublon/remise de ligne), mais sans réservation
 * de stock (un devis ne mouvemente rien, voir CLAUDE.md) ni section
 * paiement — juste des montants indicatifs.
 */
window.devisApp = function (produits, panierInitial = [], taxes = []) {
    return avecCatalogue({
        produits,
        taxes,
        panier: panierInitial,

        // Stock par lieu affiché à côté de chaque ligne — purement informatif
        // (montrer au vendeur ce qui est disponible où), jamais un choix à
        // enregistrer : un devis ne réserve rien (règle 15).
        sourcesParProduit: {},

        async chargerSources(ligne) {
            const produitId = ligne.produit_id;
            if (this.sourcesParProduit[produitId]) return;

            try {
                const response = await fetch(`/devis/produits/${produitId}/stock-magasins`, {
                    headers: { Accept: 'application/json' },
                });
                if (!response.ok) return;
                this.sourcesParProduit[produitId] = await response.json();
            } catch {
                // Silencieux : aucun badge ne s'affiche pour cette ligne.
            }
        },

        ajouterLigneVierge(produit) {
            const existante = this.panier.find((l) => l.produit_id === produit.id && l.unite_vente_id === undefined);
            if (existante) {
                existante.quantite += 1;
                return;
            }

            this.panier.push({
                produit_id: produit.id,
                unite_vente_id: undefined,
                taxe_id: '',
                produitLibelle: produit.libelle_affichage,
                uniteLibelle: null,
                facteur: null,
                quantite: 1,
                prixUnitaire: null,
                remise_type: '',
                remise_valeur: null,
            });
        },

        estDoublon(index) {
            const ligne = this.panier[index];
            if (ligne.unite_vente_id === undefined) return false;
            return this.panier.some(
                (l, i) => i !== index && l.produit_id === ligne.produit_id && l.unite_vente_id === ligne.unite_vente_id
            );
        },

        get aUnDoublon() {
            return this.panier.some((l, i) => this.estDoublon(i));
        },

        get aUneLigneNonChoisie() {
            return this.panier.some((l) => l.unite_vente_id === undefined);
        },

        produitDe(ligne) {
            return this.produits.find((p) => p.id === ligne.produit_id);
        },

        // Pas de vérification de stock ici (à la différence de posApp) : un
        // devis est indicatif et ne réserve rien, voir CLAUDE.md.
        changerVarianteDepuisSelect(ligne, valeur) {
            const produit = this.produitDe(ligne);
            if (!produit) return;

            if (valeur === '') {
                ligne.unite_vente_id = undefined;
                ligne.uniteLibelle = null;
                ligne.facteur = null;
                ligne.prixUnitaire = null;
                return;
            }

            const unite = valeur === 'piece' ? null : produit.unites.find((u) => u.id === Number(valeur));
            ligne.unite_vente_id = unite ? unite.id : null;
            ligne.uniteLibelle = unite ? unite.libelle : null;
            ligne.facteur = unite ? unite.facteur : 1;
            ligne.prixUnitaire = unite ? unite.prix : produit.prix_piece;
        },

        // À 1, "−" ne fait plus rien (voir atteintMin) : retirer une ligne
        // reste un geste séparé et explicite (bouton corbeille).
        changerQuantite(ligne, delta) {
            const nouvelle = arrondirQuantite(ligne.quantite + delta);
            if (nouvelle < 0.001) return;
            ligne.quantite = nouvelle;
        },

        atteintMin(ligne) {
            return ligne.quantite <= 0.001;
        },

        // Saisie manuelle (en plus des boutons −/+), déclenchée à chaque
        // frappe (@input) pour agir immédiatement — un devis ne vérifie
        // jamais le stock (montants indicatifs, hors caisse — voir
        // CLAUDE.md), donc juste un plancher, pas de plafond. Un point OU
        // une virgule sont acceptés, toujours ramenés au point avant
        // parseFloat. Un champ vide/en cours de frappe ne force rien (voir
        // validerQuantite()). `input` forcé explicitement : si la valeur
        // clampée (ex. "0" → 1) égale la quantité déjà en mémoire, Alpine ne
        // redéclenche pas la liaison :value et le champ resterait
        // visuellement sur "0" tapé.
        definirQuantite(ligne, valeur, input = null) {
            const nombre = parseFloat(String(valeur).replace(',', '.'));
            if (!Number.isFinite(nombre)) return;
            ligne.quantite = Math.max(0.001, arrondirQuantite(nombre));
            if (input) input.value = ligne.quantite;
        },

        // Filet de sécurité au blur (@blur) : un champ resté vide/invalide
        // retombe sur 1, jamais une quantité indéfinie. Même forçage du DOM
        // que definirQuantite() ci-dessus.
        validerQuantite(ligne, valeur, input = null) {
            if (!Number.isFinite(parseFloat(String(valeur).replace(',', '.')))) {
                ligne.quantite = 1;
                if (input) input.value = 1;
            }
        },

        retirerLigne(index) {
            this.panier.splice(index, 1);
        },

        calculerRemise(type, valeur, base) {
            if (!type || !valeur) return 0;
            const montant = type === 'pourcentage' ? Math.round((base * valeur) / 100) : Number(valeur);
            return Math.min(montant, base);
        },

        totalLigne(ligne) {
            if (ligne.prixUnitaire === null) return 0;
            const sousTotal = ligne.prixUnitaire * ligne.quantite;
            return sousTotal - this.calculerRemise(ligne.remise_type, ligne.remise_valeur, sousTotal);
        },

        get sousTotal() {
            return this.panier.reduce((somme, l) => somme + this.totalLigne(l), 0);
        },

        tauxLigne(ligne) {
            const taxe = this.taxes.find((t) => String(t.id) === String(ligne.taxe_id || ''));
            return taxe ? taxe.taux : 0;
        },

        montantTtcLigne(ligne) {
            const ht = this.totalLigne(ligne);
            return Math.round(ht + ht * this.tauxLigne(ligne) / 100);
        },

        get totalTaxes() {
            return this.panier.reduce((somme, l) => somme + (this.montantTtcLigne(l) - this.totalLigne(l)), 0);
        },

        // Pas de remise sur le total pour un devis (contrairement à une
        // vente) : total net = sous-total HT + taxes, voir
        // Devis::calculerMontants() côté serveur.
        get totalNet() {
            return this.sousTotal + this.totalTaxes;
        },

        declencherEnregistrement(event, formId) {
            const form = document.getElementById(formId);
            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                form.reportValidity();
                return;
            }
            window.bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmActionModal')).show(event.currentTarget);
        },
    }, { disponible: () => true });
};

// Validation Bootstrap générique : chaque formulaire de l'app s'appuie sur les
// contraintes HTML natives (required, min, type=email, unique côté serveur…) et
// affiche le style Bootstrap (bordure rouge + .invalid-feedback) au lieu des bulles
// natives du navigateur. `noValidate` est posé en JS plutôt que dans chaque vue pour
// ne pas dupliquer l'attribut sur des dizaines de formulaires.
document.querySelectorAll('form').forEach((form) => {
    form.noValidate = true;
});

// Spinner générique + anti double-soumission — voir demarrerSpinner() plus bas.
// Retient le bouton qui a ouvert une popup de confirmation, pour lui appliquer
// le spinner (et pas au bouton "Confirmer" de la popup) une fois le formulaire
// réellement soumis via requestSubmit().
let dernierDeclencheurConfirmation = null;

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }
    if (!form.checkValidity()) {
        event.preventDefault();
        event.stopPropagation();
        form.classList.add('was-validated');
        return;
    }
    form.classList.add('was-validated');

    const bouton = event.submitter
        ?? (dernierDeclencheurConfirmation?.getAttribute('data-form-id') === form.id ? dernierDeclencheurConfirmation : null);
    dernierDeclencheurConfirmation = null;

    // data-sans-spinner : formulaire dont la réponse s'ouvre ailleurs (nouvel
    // onglet) — la page courante ne se recharge pas, le bouton resterait
    // bloqué sur son indicateur d'attente.
    if (bouton instanceof HTMLElement && form.dataset.sansSpinner === undefined) {
        demarrerSpinner(bouton, form.dataset.attenteLongue !== undefined);
        if (form.dataset.telechargement !== undefined) {
            surveillerTelechargement(bouton);
        }
    }
}, true);

// Formulaires de téléchargement de fichier (ex. sauvegarde) : la réponse est
// un fichier, pas une page — aucun rechargement ne vient jamais réinitialiser
// le spinner générique tout seul. Le contrôleur pose un cookie une fois le
// fichier prêt à être envoyé (ex. ParametreController::backup()) ; on le
// sonde pour arrêter le spinner dès que le téléchargement démarre vraiment,
// plutôt que d'attendre le délai de sécurité de 20 s (qui afficherait à tort
// une erreur de lenteur alors que le fichier a bien été livré).
function surveillerTelechargement(bouton) {
    const intervalle = window.setInterval(() => {
        if (!document.cookie.includes('telechargement_pret=1')) {
            return;
        }
        window.clearInterval(intervalle);
        document.cookie = 'telechargement_pret=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
        arreterSpinner(bouton);
    }, 300);
}

Alpine.start();

// Modal de confirmation de suppression partagé (voir partials/confirm-delete-modal.blade.php
// et components/delete-button.blade.php) : jamais de confirm() natif du navigateur.
document.addEventListener('show.bs.modal', (event) => {
    const modal = event.target;
    if (modal.id !== 'confirmDeleteModal') {
        return;
    }

    const trigger = event.relatedTarget;
    const formId = trigger?.getAttribute('data-form-id');
    const label = trigger?.getAttribute('data-label') || 'cet élément';

    modal.querySelector('#confirmDeleteLabel').textContent = label;

    const confirmButton = modal.querySelector('#confirmDeleteButton');
    confirmButton.onclick = () => {
        dernierDeclencheurConfirmation = trigger ?? null;
        // requestSubmit() (contrairement à submit()) déclenche l'événement
        // 'submit', nécessaire pour que le spinner générique s'applique ici
        // aussi.
        document.getElementById(formId)?.requestSubmit();
    };
});

// Modal de confirmation générique (Valider, Clôturer…) : voir
// partials/confirm-action-modal.blade.php et components/confirm-button.blade.php.
document.addEventListener('show.bs.modal', (event) => {
    const modal = event.target;
    if (modal.id !== 'confirmActionModal') {
        return;
    }

    const trigger = event.relatedTarget;
    const formId = trigger?.getAttribute('data-form-id');
    const message = trigger?.getAttribute('data-message') || 'Confirmer cette action ?';
    const buttonLabel = trigger?.getAttribute('data-button-label') || 'Confirmer';
    const buttonClass = trigger?.getAttribute('data-button-class') || 'btn-primary';

    modal.querySelector('#confirmActionMessage').textContent = message;

    const confirmButton = modal.querySelector('#confirmActionButton');
    confirmButton.className = 'btn ' + buttonClass;
    confirmButton.textContent = buttonLabel;
    confirmButton.onclick = () => {
        dernierDeclencheurConfirmation = trigger ?? null;
        // requestSubmit() (contrairement à submit()) déclenche l'événement
        // 'submit' et la validation native — nécessaire pour les formulaires
        // avec des champs required (ex. valider un achat depuis sa création).
        document.getElementById(formId)?.requestSubmit();
    };
});

// --- Spinner générique sur tout bouton qui soumet un formulaire ---
//
// Empêche un double-clic de renvoyer deux fois la même action (ex. finaliser
// deux fois la même vente) et donne un retour visuel immédiat. Comme les
// formulaires de l'app se soumettent classiquement (rechargement de page, pas
// de fetch), le navigateur ne prévient pas toujours JS en cas d'échec réseau
// pur (serveur injoignable) : un délai de sécurité réactive alors le bouton et
// signale l'erreur, plutôt que de laisser le spinner tourner indéfiniment. En
// cas d'erreur métier normale (validation, stock insuffisant…), Laravel
// recharge la page avec le message habituel — le spinner disparaît de
// lui-même puisque toute la page est remplacée.
const DELAI_SECURITE_SPINNER_MS = 20000;

// attenteLongue (formulaire marqué data-attente-longue) : traitement
// légitimement long côté serveur (ex. création d'un espace : préparation
// d'une base de données) — pas de délai de sécurité, qui réactiverait le
// bouton et inviterait à renvoyer une seconde fois une demande déjà en cours.
function demarrerSpinner(bouton, attenteLongue = false) {
    if (bouton.dataset.spinnerActif) {
        return;
    }

    bouton.dataset.spinnerActif = '1';
    bouton.dataset.libelleOriginal = bouton.innerHTML;
    bouton.disabled = true;

    const texte = bouton.textContent.trim();
    const spinner = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
    bouton.innerHTML = texte ? `${spinner} ${texte}` : spinner;

    if (attenteLongue) {
        return;
    }

    window.setTimeout(() => {
        if (bouton.isConnected && bouton.dataset.spinnerActif) {
            arreterSpinner(bouton);
            afficherToastErreur("La requête met trop de temps à répondre. Vérifiez votre connexion et réessayez.");
        }
    }, DELAI_SECURITE_SPINNER_MS);
}

function arreterSpinner(bouton) {
    bouton.disabled = false;
    if (bouton.dataset.libelleOriginal !== undefined) {
        bouton.innerHTML = bouton.dataset.libelleOriginal;
    }
    delete bouton.dataset.spinnerActif;
    delete bouton.dataset.libelleOriginal;
}

function afficherToastErreur(message) {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = 1080;
        document.body.appendChild(container);
    }

    const toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center text-bg-danger border-0';
    toastEl.setAttribute('role', 'alert');
    toastEl.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    container.appendChild(toastEl);

    const toast = window.bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 8000 });
    toast.show();
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

// --- Création rapide d'un client depuis l'écran de vente ou de devis ---
//
// Ouvre #clientRapideModal (voir partials/client-rapide-modal.blade.php),
// crée le client en AJAX (POST /clients/rapide) puis l'ajoute et le
// sélectionne directement dans le <select> (select2) visé — la vente ou le
// devis en cours n'est jamais rechargé ni perdu.
window.clientRapideCible = null;

window.ouvrirClientRapide = function (selectId) {
    window.clientRapideCible = selectId;

    const nomInput = document.getElementById('clientRapideNom');
    nomInput.value = '';
    nomInput.classList.remove('is-invalid');
    const telephoneInput = document.getElementById('clientRapideTelephone');
    telephoneInput.value = '';
    telephoneInput.classList.remove('is-invalid');
    document.getElementById('clientRapideTelephoneErreur').textContent = '';
    document.getElementById('clientRapideNomErreur').textContent = '';

    window.bootstrap.Modal.getOrCreateInstance(document.getElementById('clientRapideModal')).show();
};

window.soumettreClientRapide = function () {
    const nomInput = document.getElementById('clientRapideNom');
    const erreurEl = document.getElementById('clientRapideNomErreur');
    const bouton = document.getElementById('clientRapideBouton');
    const nom = nomInput.value.trim();
    const telephoneInput = document.getElementById('clientRapideTelephone');
    const telephoneErreurEl = document.getElementById('clientRapideTelephoneErreur');
    const telephone = telephoneInput.value.trim();
    const telephonePays = document.getElementById('clientRapideTelephonePays').value;
    const typeClientId = document.getElementById('clientRapideTypeClient').value;

    nomInput.classList.remove('is-invalid');
    erreurEl.textContent = '';
    telephoneInput.classList.remove('is-invalid');
    telephoneErreurEl.textContent = '';

    if (!nom) {
        nomInput.classList.add('is-invalid');
        erreurEl.textContent = 'Le nom est obligatoire.';
        nomInput.focus();
        return;
    }

    bouton.disabled = true;

    fetch('/clients/rapide', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({ nom, telephone: telephone || null, telephone_pays: telephonePays, type_client_id: typeClientId || null }),
    })
        .then(async (response) => {
            const data = await response.json();

            if (!response.ok) {
                // Numéro refusé pour le pays choisi : signalé sur son propre
                // champ, pas sur le nom.
                if (data.errors?.telephone) {
                    telephoneInput.classList.add('is-invalid');
                    telephoneErreurEl.textContent = data.errors.telephone[0];
                }
                if (data.errors?.nom || !data.errors?.telephone) {
                    nomInput.classList.add('is-invalid');
                    erreurEl.textContent = data.errors?.nom?.[0] ?? data.message ?? 'Impossible de créer le client.';
                }
                return;
            }

            const selectId = window.clientRapideCible;
            const $select = selectId ? jQuery('#' + selectId) : null;
            if ($select && $select.length) {
                const texte = data.telephone ? `${data.nom} — ${data.telephone}` : data.nom;
                // Ajoute puis sélectionne via l'API select2 (pas seulement le
                // <select> natif), sinon l'affichage select2 ne se met pas à
                // jour. trigger('change') ne notifie que les écouteurs jQuery
                // (pas addEventListener utilisé par Alpine x-model) : on
                // redispatche donc aussi un vrai événement natif, comme le
                // fait déjà initSelect2() pour les sélections via l'UI.
                $select.append(new Option(texte, data.id, true, true)).trigger('change');
                $select[0].dispatchEvent(new Event('change', { bubbles: true }));
            }

            window.bootstrap.Modal.getOrCreateInstance(document.getElementById('clientRapideModal')).hide();
        })
        .catch(() => afficherToastErreur('Impossible de créer le client. Vérifiez votre connexion.'))
        .finally(() => {
            bouton.disabled = false;
        });
};

// --- Ajout rapide d'un motif de mouvement (caisse ou trésorerie) ---
//
// Insère le nouveau motif tout en haut du <select> (juste après le
// placeholder "— Choisir —") et le sélectionne directement — le formulaire
// de mouvement en cours (type/montant déjà saisis) n'est jamais perdu ni
// rechargé. Le motif reste une chaîne libre côté MouvementCaisse/
// EcritureCompteTresorerie (règle 19) : ce référentiel ne fait qu'alimenter
// le <select>, la valeur soumise est le nom, jamais un identifiant.
window.ajouterMotifRapide = function (selectEl, inputEl, fermerCallback, estDepense = false) {
    const nom = inputEl.value.trim();
    if (!nom) {
        inputEl.focus();
        return;
    }

    fetch('/motifs-mouvement/rapide', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({ nom, est_depense: estDepense }),
    })
        .then(async (response) => {
            const data = await response.json();

            if (!response.ok) {
                afficherToastErreur(data.errors?.nom?.[0] ?? data.message ?? 'Impossible de créer le motif.');
                return;
            }

            const option = new Option(data.nom, data.nom, true, true);
            // La nature du motif suit l'option : le formulaire s'en sert pour
            // proposer le bénéficiaire (voir partials/champ-motif-mouvement).
            if (data.est_depense) option.dataset.depense = '1';
            selectEl.add(option, selectEl.options.length > 0 ? 1 : null);
            selectEl.value = data.nom;
            selectEl.dispatchEvent(new Event('change', { bubbles: true }));

            inputEl.value = '';
            if (fermerCallback) fermerCallback();
        })
        .catch(() => afficherToastErreur('Impossible de créer le motif. Vérifiez votre connexion.'));
};

// --- Sidebar (voir layouts/app.blade.php et partials/sidebar.blade.php) ---
//
// Deux états sur desktop (≥ lg) : dépliée ou miniature (icônes seules). Le
// choix est mémorisé par poste (localStorage) et réappliqué avant le premier
// rendu par le script en tête du layout — ici, on ne fait que le basculer.
// Sous lg, la sidebar est un panneau coulissant Bootstrap, toujours déplié.
const CLE_SIDEBAR_MINI = 'erp.sidebar.mini';
const racineDocument = document.documentElement;
const ecranDesktop = window.matchMedia('(min-width: 992px)');

function sidebarEstMini() {
    return ecranDesktop.matches && racineDocument.classList.contains('erp-sidebar-mini');
}

document.querySelectorAll('[data-bascule-sidebar]').forEach((bouton) => {
    bouton.setAttribute('aria-expanded', String(!racineDocument.classList.contains('erp-sidebar-mini')));

    bouton.addEventListener('click', () => {
        const mini = racineDocument.classList.toggle('erp-sidebar-mini');
        bouton.setAttribute('aria-expanded', String(!mini));
        try {
            localStorage.setItem(CLE_SIDEBAR_MINI, mini ? '1' : '0');
        } catch {
            // Stockage indisponible (navigation privée…) : le choix vaut
            // pour la page en cours, simplement pas mémorisé.
        }
    });
});

document.querySelectorAll('[data-menu-groupe]').forEach((groupe) => {
    const bouton = groupe.querySelector(':scope > .erp-menu__link');
    const sousMenu = groupe.querySelector(':scope > .erp-menu__sub');

    // Sidebar dépliée : le clic replie/déplie le groupe. En miniature, le
    // sous-menu volant s'ouvre au survol/focus (CSS) — le clic n'y change
    // rien, pour ne pas modifier en douce l'état du menu déplié.
    bouton.addEventListener('click', () => {
        if (sidebarEstMini()) return;
        const ouvert = groupe.classList.toggle('is-open');
        bouton.setAttribute('aria-expanded', String(ouvert));
    });

    // Sous-menu volant (miniature) : aligné sur son icône par défaut, remonté
    // juste ce qu'il faut s'il dépasse le bas de l'écran (groupes du bas de
    // la sidebar, ex. Administration et sa douzaine d'entrées).
    const recalerSousMenu = () => {
        if (!sidebarEstMini()) return;
        sousMenu.style.top = '0px';
        const depassement = sousMenu.getBoundingClientRect().bottom - (window.innerHeight - 8);
        if (depassement > 0) {
            sousMenu.style.top = `${-depassement}px`;
        }
    };
    groupe.addEventListener('mouseenter', recalerSousMenu);
    groupe.addEventListener('focusin', recalerSousMenu);
});

// Entrée de la page courante toujours visible à l'arrivée, même en bas d'un
// long groupe (ex. Administration → Rôles) qui dépasse la hauteur d'écran.
if (ecranDesktop.matches && !sidebarEstMini()) {
    document.querySelector('.erp-menu .is-active')?.scrollIntoView({ block: 'nearest' });
}
