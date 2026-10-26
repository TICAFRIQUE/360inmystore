# CLAUDE.md

Contexte projet pour agents de code (Claude Code). Ce fichier décrit **quoi construire**
et surtout **les règles à ne jamais violer**. Le détail fonctionnel complet est dans
`CAHIER_DES_CHARGES.md`.

---

## Vue d'ensemble

**360inmystore** — application web **ERP (inspiration Odoo)** de **caisse (POS) + gestion
de stock** pour un commerce exploitant **un ou plusieurs magasins**, **quel que soit son
secteur d'activité** (quincaillerie, alimentation, boutique, pièces détachées…). Aucun
libellé, exemple par défaut, couleur ni règle ne doit supposer un secteur précis : ce
qui varie d'un commerce à l'autre (catalogue, unités, types de client, motifs de
mouvement, nom/logo/couleur) est du **paramétrage**, jamais du code.
Cible : utilisateurs **non techniques** — interface simple et intuitive.

« 360inmystore » est le nom du **produit** (`APP_NAME`, scripts, coquille desktop,
discret en pied de sidebar) ; le nom affiché en grand à l'écran et sur les documents
reste celui du **commerce**, configurable dans Paramètres (`Parametre.nom`, qui vaut
« 360inmystore » tant qu'il n'a pas été personnalisé).

Clientèle mixte : particuliers (vente comptant) **et** clients professionnels réguliers
qui achètent **à crédit** sur compte client, réglé plus tard.

- Web **responsive** (smartphone / tablette / desktop).
- **MVP connecté** : la caisse dialogue avec le serveur. Hors-ligne reporté —
  **ne pas** implémenter de synchronisation ni de base locale.

---

## Stack technique

- **Framework** : Laravel 13 (PHP). **Vues** : Blade.
- **UI** : Bootstrap. **Réactivité client** : Alpine.js (pas de SPA lourde).
- **Base de données** : moteur relationnel **ACID** (transactions obligatoires).

### Packages imposés
- `spatie/laravel-permission` — rôles et permissions **dynamiques**.
- `spatie/laravel-medialibrary` — images produits.
- `spatie/laravel-activitylog` — journaux d'activité.
- `yajra/laravel-datatables-oracle` — tableaux de données (mode serveur).
- `giggsey/libphonenumber-for-php-lite` — validation et format des numéros de
  téléphone (voir « Téléphone »).

---

## Règles métier NON NÉGOCIABLES

1. **Le stock est dérivé, jamais écrasé.** Quantité disponible = somme des mouvements.
   Jamais `UPDATE stock SET quantite = X` ; toujours insérer un mouvement.
2. **Un mouvement de stock est immuable.** Pas de `UPDATE`/`DELETE` ; correction = mouvement inverse.
3. **Une vente est atomique.** Stock (mouvement) + paiements + session dans un seul
   `DB::transaction`. Tout ou rien.
4. **Le SKU identifie un produit**, jamais son nom (deux produits peuvent avoir le même nom).
5. **Le stock se compte dans l'unité de base du produit** (« pièce » au sens générique :
   une unité physique, mais aussi le mètre, le kilo, le litre… selon comment le produit
   est défini). Une unité de vente a un facteur **entier** ; vendre un lot de N décrémente
   N unités de base. Pas de quantités décimales dans le MVP.
6. **Vendre exige une session ouverte sur une caisse LIBRE** — pour TOUS les rôles, y
   compris gérant, admin et superadmin. Aucune vente hors session.
7. **Une caisse n'a qu'UNE session ouverte à la fois.** « Libre » = sans session ouverte.
8. **Interdit de clôturer OU fermer une session tant qu'il reste des ventes en attente**
   sur la caisse. Chaque panier en attente doit être finalisé ou **annulé** d'abord.
9. **La clôture se calcule par session**, pas par jour — une session peut donc légitimement
   s'étendre sur plusieurs jours calendaires, jamais bloquée pour cette seule raison. Des
   rappels non bloquants existent néanmoins pour éviter qu'elle traîne par oubli, jamais un
   blocage de la vente (`SessionCaisse::estOuverteDepuisJourPrecedent()`, mémorisé par jour
   via `localStorage`) :
   - Tableau de bord **caissier** (`x-alerte-session-ancienne`) : uniquement **sa propre**
     session, si ouverte depuis un jour précédent.
   - Tableau de bord **gérant/superadmin** (`x-alerte-sessions-anciennes`,
     `DashboardController::donneesMagasin()`) : **toute** session du périmètre (magasin
     pour un gérant, tous magasins pour le superadmin) encore ouverte depuis un jour
     précédent, pas seulement celle de l'utilisateur connecté — un gérant/superadmin a
     l'autorité (`caisse.gerer`) pour clôturer une session qu'il n'a pas ouverte lui-même.
   - En complément, côté gérant/superadmin uniquement : une notification in-app après 12h
     sans clôture (commande planifiée horaire `app:alerter-sessions-ouvertes-trop-longtemps`,
     une seule fois par session, indépendante du jour calendaire).
10. **Seules les espèces entrent dans le comptage du tiroir** — ventes, règlements clients
    ET mouvements de caisse manuels (entrée/sortie, voir règle 19) confondus. Autres
    moyens totalisés à part.
11. **Toute action sensible est tracée** via `activitylog`.
12. **Le solde d'un compte client est dérivé, jamais écrasé.** Même principe que le
    stock (règle 1) : solde = somme des écritures (`EcritureCompteClient`). Jamais
    d'`UPDATE clients SET solde = X`.
13. **Une vente à crédit exige un client identifié.** Paiement partiel ou nul autorisé
    uniquement si un client est rattaché à la vente ; le reste dû devient une dette sur
    son compte. Une vente sans client doit être payée intégralement.
14. **Un règlement client s'effectue dans une session de caisse ouverte**, comme une
    vente — mêmes contraintes d'atomicité et de comptage du tiroir.
15. **Un devis ne mouvemente ni le stock ni la caisse.** Seule sa transformation en
    vente le fait, dans les mêmes conditions qu'une vente normale (règle 6) — avec les
    prix et remises **courants** du catalogue, jamais les montants indicatifs figés sur
    le devis.
16. **Le solde d'un compte fournisseur est dérivé, jamais écrasé.** Même principe que le
    stock (règle 1) et le compte client (règle 12) : solde = somme des écritures
    (`EcritureCompteFournisseur`). Jamais d'`UPDATE fournisseurs SET solde = X`.
17. **Un règlement fournisseur est indépendant de toute caisse de caissier, y compris
    pour la part payée en espèces.** Contrairement au règlement client (règle 14), il
    n'exige donc jamais de session de caisse ouverte et n'entre jamais dans le comptage
    du tiroir d'un caissier. La part payée en espèces sort directement de la **Caisse
    Générale** (règle 21, voir Trésorerie) — jamais du tiroir d'un vendeur.
18. **Un retour (client ou fournisseur) crédite toujours un avoir sur le compte
    concerné, jamais un remboursement en espèces.** Il est obligatoirement rattaché à
    une vente/commande d'achat précise, ligne par ligne, dans la limite du reste
    retournable de chaque ligne ; il remet le stock retourné directement en stock
    vendable (règle 1, via un mouvement immuable dédié) sans recalcul du CMP. Comme un
    mouvement de stock ou une écriture de compte, un retour est immuable : une
    correction se fait par une nouvelle vente/un nouvel achat, jamais par l'annulation
    d'un retour.
    - **Côté fournisseur** : n'exige aucune session de caisse et n'entre jamais dans le
      comptage du tiroir (même logique que le règlement fournisseur, règle 17).
    - **Côté client** : exige une session de caisse ouverte — celle du caissier qui
      traite le retour, pas forcément celle de la vente d'origine — **et uniquement le
      jour même de la vente retournée** ; passé ce jour, plus aucun retour possible sur
      cette facture, même avec une session ouverte (`RetourVenteService::retourner()`,
      `RetourDateLimiteDepasseeException`/`SessionNonOuverteException`). Le retour lui-
      même ne mouvemente toujours pas le tiroir (rattaché à une session pour la
      traçabilité/le contrôle d'accès, pas pour un encaissement), mais son
      **remboursement** (règle 20) en sort désormais bien réellement.
19. **Un mouvement de caisse manuel (entrée/sortie) exige une session de caisse
    ouverte**, comme une vente (règle 6). Une sortie ne peut jamais dépasser le solde
    théorique du tiroir de cette session (fond de caisse + espèces déjà entrées − déjà
    sorties). Immuable comme tout mouvement (règle 2) : correction = mouvement inverse,
    jamais modification ni suppression.
20. **Un avoir (client ou fournisseur) peut être remboursé**, via une action distincte et
    postérieure au retour qui l'a créé (« remboursement d'avoir ») — ceci **n'est pas une
    exception à la règle 18** : le retour lui-même ne mouvemente toujours jamais la
    caisse, seul ce remboursement, explicitement déclenché plus tard, le fait. Plafonné à
    l'avoir disponible (jamais plus que le solde négatif actuel du compte). Immuable,
    comme un règlement.
    - **Côté fournisseur** : la part payée en espèces suit la règle 21 (Caisse Générale,
      aucune session de caisse de caissier requise) — en **entrée** (il nous rembourse).
    - **Côté client** : exige désormais une session de caisse ouverte (celle du
      caissier qui traite le remboursement) — la part payée en espèces sort réellement
      du **tiroir de cette session** (`CaisseMouvementService::enregistrer()`, type
      `sortie`, plafonnée à son solde théorique — `SoldeCaisseInsuffisantException`
      sinon), pas de la Caisse Générale. `RemboursementAvoirClient.session_caisse_id`
      renseigné ; apparaît dans le journal de caisse (`rapports.mouvements-caisse`,
      `comptabilite.caisses-vente.show`) comme une vraie sortie, libellée
      "Remboursement avoir".
21. **La Caisse Générale est un compte de trésorerie permanent, indépendant des caisses
    des caissiers** (règle 6-19) : pas de session, pas de caissier assigné, solde dérivé
    d'écritures immuables comme un compte client/fournisseur (règle 12/16). Elle reçoit
    **automatiquement** le montant compté de chaque session de caissier clôturée (règle
    9), et alimente la part espèces d'un règlement fournisseur (règle 17) ou d'un
    remboursement d'avoir (règle 20). Un **Compte** (banque/autre) suit le même principe
    et se remplit par virement depuis la Caisse Générale.

---

## Devis

- **Document commercial pré-vente**, à destination d'un **client identifié** (client
  obligatoire, contrairement à la vente comptant).
- **Création libre, hors caisse** : ne nécessite ni caisse ni session ouverte (ne
  mouvemente ni stock ni argent).
- **Lignes de devis** : produit + (pièce/unité de vente) + quantité + remise de ligne ;
  **aucun prix stocké** — montants indicatifs au catalogue courant, comme une ligne de
  vente en attente.
- **Durée de validité** configurable (ex. 30 jours), calculée à la création ; passée,
  statut **expiré** automatique, non transformable (dupliquer un nouveau devis).
- **Statuts** : `brouillon` → `transformé` (la transformation vaut acceptation), ou
  `refusé`/annulé ; `expiré` atteint automatiquement. Pas d'étape « envoyé »/« accepté »
  séparée pour le moment — pourra être réintroduite plus tard si besoin.
- **Transformation en vente** : uniquement depuis `brouillon` non expiré, dans une
  **session de caisse ouverte** (règle 6), avec les prix/remises **courants** du
  catalogue (pas les montants indicatifs du devis). Transformation **totale uniquement**
  (pas ligne par ligne). Le devis transformé est figé et référence la vente créée.
- Un devis non transformé peut être **refusé/annulé** par son auteur ou le gérant.
- **Export** : consultable/imprimable en ligne (mise en page type facture : coordonnées
  de l'entreprise, du client, numéro, date, détail des lignes) et téléchargeable en
  **PDF** et **Excel** — même gabarit dans les trois cas.

---

## Ventes en attente

- Un panier mis de côté : **aucun mouvement de stock, aucun encaissement**.
- **Rattachée au caissier** qui l'a créée ; un caissier ne voit que **ses** ventes en attente.
- Le **gérant** peut voir et **annuler** les ventes en attente d'une caisse de son magasin.
- Actions : reprendre (finaliser) ou annuler.
- Bloque la clôture/fermeture de la session tant qu'elle existe (règle 8).

---

## Vente à crédit et comptes clients

- **Client** : fiche référentielle centrale (nom, téléphone, adresse, **limite de
  crédit** optionnelle, actif) — pas rattachée à un magasin, comme le catalogue produit.
- **Vente comptant** (client anonyme ou renseigné) : payée intégralement à l'encaissement,
  comme aujourd'hui.
- **Vente à crédit** : nécessite un client identifié ; le total encaissé (paiement mixte
  possible) peut être **inférieur** au net à payer. Le reste dû est porté au compte du
  client (règle 13).
- **Solde client dérivé** (règle 12) : jamais stocké/écrasé directement, calculé comme la
  somme des `EcritureCompteClient` (+dette à chaque vente à crédit, −dette à chaque
  règlement) — même logique que le stock (`MouvementStock`).
- **Limite de crédit** : si définie (> 0) et que la vente ferait dépasser le solde du
  client au-delà de cette limite, la vente à crédit est **bloquée**, sauf pour un
  utilisateur disposant de la permission `client.depasser_limite`. Limite vide/0 =
  illimitée.
- **Règlement client** : encaissement ultérieur d'une dette (partiel ou total), dans une
  **session de caisse ouverte** (règle 14), un ou plusieurs moyens de paiement. Immuable,
  comme une vente ; alimente le même tiroir (règle 10).
- Le ticket d'une vente à crédit affiche le montant réglé et le **solde restant dû**.
- **Avoir client** : un solde négatif (après un ou plusieurs retours, règle 18) se déduit
  **automatiquement** de la dette d'une prochaine vente à crédit — le solde n'est qu'une
  somme d'écritures (règle 12), aucune logique spécifique requise. Affiché au caissier au
  moment de choisir le client sur l'écran de vente, pour qu'il en tienne compte.
- **`Vente.avoir_applique`** : part de l'avoir déjà disponible sur le compte qui a couvert
  le solde à crédit posé par cette vente, figée à la création (comme `cout_applique` sur
  une `LigneVente`) — jamais recalculée après coup. Le « reste dû » affiché partout
  (ticket, historique client/session, export, bouton « Régler ») est **`soldeDuReel()`**
  (`soldeDu() - avoir_applique`, jamais 0 négatif), pas `soldeDu()` brut : sans ça, une
  facture intégralement compensée par un avoir préexistant continuait d'afficher un reste
  dû alors que le compte du client était déjà revenu à 0.
- **Remboursement d'un avoir client** (règle 20) : action séparée, à l'initiative du
  gérant/caissier habilité (`client.reglement`), qui rend effectivement l'argent au
  client — un ou plusieurs moyens de paiement, plafonné à l'avoir disponible. La part en
  espèces exige une session de caisse ouverte et génère une **sortie** de caisse liée
  (voir « Mouvements de caisse »). Immuable.

---

## Achat à crédit et comptes fournisseurs

- **Fournisseur** : fiche référentielle (nom, téléphone, e-mail, adresse, actif),
  symétrique du `Client` mais sans notion de limite de crédit.
- **Chaque ligne de commande précise en option une destination prévue** (magasin ou
  dépôt, voir « Dépôts » ci-dessous) : un même bon de commande peut livrer plusieurs
  sites en une fois. Cette destination n'est qu'un **plan facultatif** — un bon de
  commande standard (brouillon → validé → réceptionné plus tard) n'a pas besoin de la
  connaître d'avance, la destination réelle se choisit de toute façon à nouveau, ligne
  par ligne, à chaque réception (voir ci-dessous), et peut en dévier. Seul le raccourci
  « Enregistrer et réceptionner immédiatement » (achat direct, voir plus bas) l'exige dès
  la saisie, puisqu'il crée la réception dans la même transaction.
- **Taxe par ligne** : optionnelle, choisie parmi un référentiel `Taxe` préenregistré
  (nom + taux %). `prix_achat` sur une ligne de commande est **HT indicatif**, en
  **décimal(14,2)** — seule exception à la règle d'arrondi unique (voir « Argent et
  arrondis ») : jamais arrondi à l'entier, ni à la saisie ni dans le **TTC** dérivé
  (jamais stocké), calculé et affiché par ligne et en récapitulatif (total HT, total
  TTC) — 2 décimales max, ni plus ni moins (`Arrondi::prixAchat()`,
  `App\Http\Controllers\CommandeAchatController::nettoyerLignes()` normalise la virgule
  saisie comme pour `quantite`). Le prix **réellement facturé**, capturé à la
  réception (`prix_achat_reel`), reste lui **entier** comme le reste de l'application
  (CMP, stock, vente) — l'exception ne porte que sur l'indicatif du bon de commande.
- **Réceptions échelonnées (bon d'achat)** : un bon de commande **validé** (`statut` =
  `validee`, prix indicatifs, aucun impact stock/argent à ce stade) peut être
  **réceptionné en une ou plusieurs fois** (`ReceptionAchat`/`LigneReceptionAchat`),
  chacune un document immuable — le **bon d'achat** au sens propre — qui capture, ligne
  par ligne : la quantité réellement arrivée (plafonnée au reste à recevoir de la
  ligne), le prix **réellement facturé** par le fournisseur pour ce lot (peut différer
  de l'indicatif, alimente le CMP — règle « coût suivi via CMP »), et la destination
  réelle (éditable, pré-remplie avec le plan de la ligne mais librement modifiable — une
  même ligne de commande peut ainsi finir répartie sur plusieurs destinations via
  plusieurs réceptions). Un ou plusieurs paiements (`PaiementAchat`, paiement mixte)
  peuvent être saisis à **chaque réception** (pas à la validation du bon de commande) ;
  le reste devient une dette fournisseur pour cette réception (règle 16). `CommandeAchat`
  reste la seule chose visible/gérée du fournisseur (numéro, prix indicatifs) ; le
  montant réellement dû (`CommandeAchat::totalTtcReel()`, somme des `totalTtc()` de
  chaque réception, ou l'indicatif tant qu'aucune réception n'existe) et le reste à
  recevoir (`quantiteRecuePieces()`/`quantiteResteARecevoirPieces()`/`tauxCompletion()`)
  s'affichent sur sa fiche. **Achat direct (sans document envoyé au préalable)** : pour
  un achat déjà effectué (ex. achat comptant chez le fournisseur), l'écran de création
  propose une action « Enregistrer et réceptionner immédiatement » qui enchaîne, dans
  une seule transaction, la création du bon de commande, sa validation et sa réception
  complète (mêmes lignes/prix/destinations/paiement saisis, indicatif = réel puisque
  c'est un seul événement) — évite de forcer un achat comptant à traverser
  brouillon → validation → réception séparément. Le chemin classique (brouillon →
  validation séparée → réception en une ou plusieurs fois) reste disponible pour une
  vraie commande envoyée au fournisseur, en attente de livraison.
- **Solde fournisseur dérivé** (règle 16) : somme des `EcritureCompteFournisseur`
  (+dette à chaque réception à crédit, −dette à chaque règlement) — même logique que le
  compte client.
- **Règlement fournisseur** : encaissement ultérieur d'une dette (au-delà de ce qui a
  déjà été réglé à la réception), un ou plusieurs moyens de paiement, **jamais rattaché
  à une session de caisse de caissier** (règle 17, contrairement au règlement client) —
  la part payée en espèces sort directement de la Caisse Générale (voir « Trésorerie »),
  sans aucune session requise. Immuable, comme un règlement client. Deux modes, réservés
  chacun à un usage précis pour ne jamais laisser un règlement "en l'air" :
  - **Ciblé sur un bon de commande précis** (bouton dédié depuis la fiche fournisseur ou
    le détail du bon de commande) : **partiel ou total autorisé**, plafonné au reste dû
    de CE bon de commande (`CommandeAchat::resteDu()`, indépendant de la dette totale du
    fournisseur qui peut inclure d'autres bons de commande).
  - **Global** (bouton général de la fiche fournisseur, sans bon de commande ciblé) :
    **intégral uniquement** — doit couvrir exactement le solde actuel du compte, jamais un
    montant partiel. `ReglementFournisseurService::reglerIntegralite()` répartit alors
    automatiquement ce montant en une `ReglementFournisseur` **par bon de commande encore
    dû**, le plus ancien d'abord (répartition "en cascade", entière, sans arrondi) —
    jamais un règlement non imputé à un bon de commande précis. Sans cette répartition,
    le solde du compte diminuait bien après un règlement global, mais le "reste dû"
    affiché sur chaque bon de commande ne bougeait jamais, ce qui semblait un bug alors
    que c'était juste une dette non imputée.
- Pas de limite de crédit fournisseur ni de blocage associé dans le MVP.
- **Avoir fournisseur** : symétrique de l'avoir client (voir « Vente à crédit et comptes
  clients ») — un solde négatif se déduit automatiquement de la dette d'un prochain achat
  à crédit, affiché au gestionnaire au moment de choisir le fournisseur sur l'écran de
  saisie d'une commande d'achat.
- **Remboursement d'un avoir fournisseur** (règle 20) : symétrique du remboursement d'un
  avoir client, mais c'est le fournisseur qui rembourse — la part en espèces génère une
  **entrée** de caisse liée (et non une sortie), le reste identique (session ouverte pour
  cette part, plafonné à l'avoir disponible, immuable, permission `fournisseur.reglement`).

---

## Retours (client et fournisseur)

- **Toujours rattaché à un document précis** : un retour client référence une `Vente`
  et des `LigneVente` précises ; un retour fournisseur référence une `CommandeAchat`
  **validée** et des `LigneCommandeAchat` précises. Jamais un avoir libre, non rattaché
  à un document (règle 18).
- **Ligne par ligne, avec plafond** : la quantité retournée sur une ligne ne peut jamais
  dépasser son reste retournable (quantité vendue/achetée moins déjà retournée sur cette
  ligne). Un même document peut faire l'objet de plusieurs retours partiels successifs.
- **Aucun remboursement en espèces** : un retour crédite un avoir sur le compte client
  (`EcritureCompteClient`, type `retour_client`) ou fournisseur
  (`EcritureCompteFournisseur`, type `retour_fournisseur`), qui peut faire passer le
  solde sous 0 (avoir utilisable sur un futur achat) — contrairement à un règlement, ce
  crédit n'est **jamais plafonné** par la dette actuelle.
- **Stock remis directement en stock vendable**, sans zone d'inspection séparée : le
  mouvement (type `retour_client` ou `retour_fournisseur`, immuable comme tout
  `MouvementStock`, règle 2) porte sur le magasin/dépôt d'origine de la ligne
  (`magasin_source_id` de la `LigneVente` ; côté achat, le magasin **réellement reçu** —
  `LigneReceptionAchat.magasin_id` si le bon de commande a été réceptionné via le
  système de réceptions échelonnées, sinon `magasin_destination_id` de la
  `LigneCommandeAchat` pour un bon de commande légitimement antérieur à ce système). Un
  retour fournisseur est plafonné à ce qui a été **réellement reçu à cette destination
  précise** (pas la quantité commandée), et peut échouer (stock insuffisant) si la
  marchandise reçue a déjà été revendue ou transférée ailleurs. L'avoir crédité se base
  sur le prix **réellement facturé** à la réception (voir « Achat à crédit et comptes
  fournisseurs »), pas sur l'indicatif du bon de commande.
- **CMP jamais recalculé** par un retour, comme tout mouvement hors réception. Le coût
  historique d'un retour client reprend `cout_applique` de la `LigneVente` d'origine
  (CMP figé au moment de la vente).
- **Vente sans client identifié (comptant anonyme)** : le retour n'est **pas** bloqué —
  n'importe quelle vente doit pouvoir être retournée. La vente elle-même reste anonyme
  (`Vente.client_id` inchangé). Le formulaire de retour **impose de choisir ou créer le
  client réellement crédité** (`client_id_retour`, sélecteur + bouton "+" identique à
  celui de l'écran de vente, `partials/client-rapide-modal.blade.php`) **uniquement si
  l'avoir va rester sur un compte** — jamais un repli silencieux, puisque plusieurs
  personnes différentes font des ventes comptant et mélanger leurs avoirs sur un seul
  compte les rendrait impossibles à distinguer. **Exception** : si le retour est
  remboursé tout de suite (case "Rembourser l'avoir tout de suite", voir ci-dessous),
  rien ne reste sur aucun compte — l'identification n'apporte alors rien, le sélecteur
  disparaît et le client singleton **"Client comptant"** (`Client.comptant_defaut`,
  seedé une fois par `ClientComptantDefautSeeder`, même principe que
  `CompteTresorerie.type=caisse_generale` — voir « Trésorerie ») est utilisé comme
  simple compte de passage. Ce même singleton reste aussi sélectionnable dans le
  sélecteur (pré-sélectionné par défaut) pour le cas où l'avoir persiste mais que le
  client ne se présente vraiment pas — jamais supprimable depuis l'UI
  (`ClientController::destroy()`). `RetourVenteService::retourner()` accepte ce client en
  paramètre explicite (`$clientCredite`) ; sans lui (appel programmatique), il retombe
  sur `$vente->client ?? Client::comptantDefaut()`.
- **Immuable** (règle 18) : pas d'édition ni de suppression applicative d'un retour déjà
  enregistré. Corriger un retour erroné se fait par une nouvelle vente/un nouvel achat,
  jamais par son annulation.
- **Point d'entrée dédié** (`/retours`, menu Vente → Retours, permission `vente.retour`) :
  recherche une facture par numéro **du jour même** (comptant comme crédit, voir règle 18
  — une facture d'un jour précédent n'apparaît plus, `Vente::scopeRetournable()`) sans
  devoir déjà la connaître, puis ouvre sa fiche pour y faire le retour — le retour reste
  toujours rattaché à cette facture précise (pas de sélection libre produit/quantité/prix,
  voir ci-dessus). Bandeau si l'utilisateur n'a pas de session ouverte. Symétrique de
  `bons-livraison.index`.
- **Remboursement immédiat, coché par défaut, espèces présélectionnées** (deux boutons
  radio sur le formulaire de retour, visibles si `client.reglement` : « Rembourser
  maintenant » — par défaut, puisqu'un retour se fait désormais forcément le jour même
  en session, le client est là, moyen de paiement "Espèces" déjà sélectionné (cas quasi
  systématique au comptoir) — ou « Créer un avoir pour ce client », toujours possible :
  tiroir de session court, ou client à crédit qui préfère garder ça en avoir sur son
  compte). Le premier enchaîne, dans la foulée du retour, un
  `RemboursementAvoirClient` pour tout ou partie de l'avoir qui vient d'être créé — ce
  n'est **pas** une exception à la règle 18 (le retour lui-même ne mouvemente toujours
  jamais la caisse) : ce sont deux écritures distinctes et immuables
  (`RetourVente` + `RemboursementAvoirClient`), simplement déclenchées dans le même
  geste côté écran plutôt qu'en deux visites séparées, avec la **même** session ouverte
  (résolue une seule fois). Si le remboursement échoue (avoir insuffisant après
  compensation d'une dette existante, ou tiroir de la session à sec), le retour reste
  acquis ; seul le remboursement peut être retenté plus tard. Le sélecteur "Client à
  créditer" ne s'affiche qu'en mode "Créer un avoir" (rien à identifier si tout repart en
  espèces tout de suite, voir plus haut).
- **Liste centralisée des avoirs** (`/remboursements-avoir`, menu Vente → Avoirs
  clients, permission `client.reglement`) : tous les clients actifs à solde négatif
  (`Client::actifsAvecAvoir()`, symétrique de `actifsAvecDette()`), remboursables
  directement depuis la liste — sans devoir ouvrir la fiche de chaque client. Bandeau +
  bouton "Rembourser" désactivé si l'utilisateur n'a pas de session ouverte (règle 20).
- **Traçabilité de la facture d'origine d'un avoir** : même quand une vente reste
  comptant (`Vente.client_id` jamais modifié par un retour) et que son retour est
  crédité à un client précis, cette facture apparaît dans la section "Ventes" de la
  fiche de **ce client** (`ClientController::show()`, union `client_id = X` OU
  `retours.client_id = X`) avec un badge "Retour attribué" — pas seulement noyée dans
  "Historique du compte". Distinct d'un achat réel de ce client (badge = avertissement,
  jamais affiché comme une vente normale). Dans l'autre sens — depuis la facture/la
  liste `/retours` — le nom du client crédité par CHAQUE retour est affiché dès qu'il
  diffère du client de la vente (`$retour->client`, jamais `$vente->client` qui reste
  anonyme) : sur le ticket ("Crédité à …", lien vers sa fiche) et dans la liste
  `/retours` ("Avoir crédité à …", sous "Comptant").
- **`Client::totalVentes()` ("Total ventes net") ne déduit QUE les retours sur les
  propres ventes du client** (`retours.vente.client_id = $this->id`), jamais tous les
  retours crédités à son compte au sens large — un client peut recevoir un avoir sur la
  vente comptant anonyme de quelqu'un d'autre, ce qui n'a rien à voir avec SON volume
  d'achat propre. Sans cette distinction, un client n'ayant jamais rien acheté mais
  ayant reçu un tel avoir affichait un "Total ventes net" négatif. `max(0, …)` en filet
  de sécurité, jamais un chiffre d'affaires négatif affiché.
- Un document (`Vente`/`CommandeAchat`) ayant déjà fait l'objet d'un retour partiel ne
  peut plus être annulé en totalité (l'annulation totale restituerait une seconde fois
  le stock déjà repris par le retour) — utiliser un nouveau retour pour le reste, ou
  laisser le document tel quel.

---

## Mouvements de caisse (entrée/sortie)

- **Mécanisme générique** (`MouvementCaisse`, type `entree` | `sortie`) : un montant, un
  motif (libre, obligatoire), une session de caisse, un auteur — utilisable pour
  n'importe quelle raison (appoint, prélèvement, dépense diverse, paiement fournisseur en
  espèces…). Immuable comme tout mouvement (règle 2/19) : correction = mouvement inverse.
- **Motif saisi via un `<select>`**, alimenté par un référentiel géré en Administration
  (`MotifMouvement`, permission `motif.gerer`) — jamais une contrainte stricte : le champ
  `motif` reste une chaîne libre sur `MouvementCaisse`/`EcritureCompteTresorerie` (ce
  référentiel ne fait qu'harmoniser les libellés déjà proposés). Un bouton "+" à côté du
  select permet de créer un motif à la volée sans quitter le formulaire de mouvement
  (`motifs-mouvement.rapide`, accessible à tout utilisateur connecté — pas seulement
  `motif.gerer` — puisque quiconque peut déjà soumettre un mouvement) : le nouveau motif
  s'insère en tête de liste (juste après le placeholder) et se sélectionne aussitôt, sans
  recharger la page ni perdre le montant/type déjà saisis. Utilisé par les deux
  formulaires d'entrée/sortie existants (`sessions/{session}` pour une caisse de
  caissier, `comptabilite/caisses/{compte}` pour la trésorerie) — même référentiel
  partagé entre les deux, un seul endroit à administrer.
- **Session de caisse ouverte obligatoire** (règle 19), comme une vente. Le montant
  impacte directement le solde théorique du tiroir de cette session, en temps réel — pas
  seulement au moment de la clôture.
- **Une sortie ne peut jamais dépasser le solde théorique du tiroir** (fond de caisse +
  ventes espèces + règlements clients espèces + entrées − sorties déjà enregistrées) :
  échoue sinon (`SoldeCaisseInsuffisantException`), on ne peut pas sortir plus d'argent
  qu'il n'y en a physiquement dans le tiroir.
- **Règlement fournisseur en espèces → sortie de caisse automatique liée** (exception à la
  règle 17) : la part du règlement payée en espèces génère une sortie de caisse
  (`reference` = le `ReglementFournisseur`), avec un motif généré automatiquement. Un
  règlement 100% non-espèces reste totalement indépendant de la caisse, comme avant.
- **Clôture de session étendue** (règle 10) : théorique = fond de caisse + ventes espèces
  + règlements clients espèces + entrées de caisse − sorties de caisse. Calcul centralisé
  dans une seule méthode (`CaisseSessionService::calculerTheorique()`), réutilisée par la
  clôture, son aperçu, et le solde théorique temps réel — jamais recalculée deux fois.
- **Qui peut enregistrer un mouvement** : Caissier (sur sa propre session ouverte) et
  Gérant/Superadmin, via la permission `caisse.mouvement`.
- **Saisie directement sur l'écran de session** (`/sessions/{session}`, pas d'onglet
  séparé) : `sessions.index`/`sessions.show` couvraient déjà la liste des caisses et le
  détail d'une session (CA, décomposition, répartition, clôture) — un onglet « Mes
  caisses » distinct ne faisait plus que dupliquer cet écran pour n'y ajouter que le
  formulaire entrée/sortie et le solde théorique temps réel ; les deux ont été fusionnés
  dans `sessions/show.blade.php` pour ne pas faire cohabiter deux écrans qui montrent
  presque la même chose. Pour une session **ouverte**, une ligne de KPI dédiée raconte
  la "story" financière dans l'ordre — **Total vendu** (brut, `total_net` de toutes les
  ventes de la session) → **Retour/avoir** (montant + nombre de retours) → **Total en
  caisse** (calculé en direct via `CaisseSessionService::calculerTheorique()` — fond de
  caisse + espèces encaissées − sorties, remboursements d'avoir client inclus, règle 20 ;
  c'est le nombre utilisé à la clôture pour comparer avec le comptage réel), séparée de
  la ligne opérationnelle au-dessus (fond de caisse, nombre de ventes, ventes en
  attente). L'ancien KPI « Total en caisse » de la décomposition (entrées seules, sans
  jamais déduire les sorties) a été retiré : affiché à côté du vrai solde du tiroir, il
  créait trop de confusion sur ce qu'il y a vraiment dedans — un seul chiffre "caisse",
  celui qui compte réellement. La ligne « Espèces » de « Répartition par moyen de paiement »
  (juste en dessous, `SessionCaisseController::paiementsParMoyen()`) raconte la même
  histoire : les sorties (manuelles ou remboursement d'avoir client, toujours en
  espèces, règle 19) en sont déduites — sans ça, "Espèces : 600" pouvait s'afficher à
  côté d'un "Total en caisse" à 150, laissant croire qu'il restait 600 F dans le tiroir.
  Les autres moyens (mobile money, carte…) restent bruts, jamais concernés par une
  sortie de caisse (toujours en espèces). Formulaire entrée/sortie (visible si
  `caisse.mouvement`, réservé au propriétaire de la
  session ou à un titulaire de `caisse.gerer` — `SessionCaisseController::storeMouvement()`/
  `assurerProprietaireOuGerant()`), et l'historique des mouvements manuels de cette
  session — affiché en tableau dédié (distinct de la liste des ventes), positionné
  **après** cette dernière sur l'écran. Une session **clôturée** garde son bloc
  « Clôture » existant (entrées/sorties y figurent déjà) ; le formulaire disparaît
  puisqu'aucun mouvement n'est plus possible. **L'écran de clôture** (`sessions/cloturer.blade.php`)
  reprend la même ligne de KPI (Fond de caisse, Total ventes, **Montant vente** — tous
  moyens de paiement confondus, contrairement à « Total en caisse » — Retour/avoir,
  Entrée, Sortie, Total en caisse) : seul le montant comparé au comptage physique du
  tiroir juste en dessous reste strictement les espèces (règle 10), « Montant vente »
  n'est qu'une donnée informative supplémentaire, jamais celle utilisée pour le calcul
  de l'écart à la validation.
- **Historique complet** : rapport dédié (`rapports.mouvements-caisse`, sous `rapport.voir`,
  comme les autres rapports) — tous les mouvements toutes sessions/périodes confondues,
  filtrable par date et magasin, exportable PDF/Excel.
- **Une vente encaissée en espèces apparaît dans ce même historique**, avec le libellé
  « Vente / Facture » — mais **une vente n'est jamais un `MouvementCaisse`** : elle est
  déjà comptabilisée via ses propres `Paiement` (voir « Clôture de session étendue »
  ci-dessus). Ce n'est qu'un assemblage en lecture seule pour l'affichage (mêmes filtres,
  mêmes exports), qui ne duplique jamais le calcul du solde théorique. Seule la part
  encaissée en espèces d'une vente y apparaît (règle 10) ; une vente annulée n'y apparaît
  pas.
- **Un retour client apparaît aussi dans ce même historique**, libellé « Retour (avoir) »
  — rattaché à la caisse où il est **réellement traité** (`RetourVente.session_caisse_id`,
  pas forcément celle de la vente d'origine, règle 18). Comme une vente, **un retour
  n'est jamais un `MouvementCaisse`** : simple lecture seule via le même assemblage
  (`JournalCaisse`, trait partagé par `rapports.mouvements-caisse` et
  `comptabilite.caisses-vente.show`) — jamais additionné aux totaux entrées/sorties/
  ventes (KPI "Retours" séparé), jamais affiché avec un signe +/− (il ne mouvemente
  jamais le tiroir lui-même, règle 18). Un retour créé avant l'exigence de session
  (`session_caisse_id` nul) disparaît de ce journal — pas de rattachement rétroactif
  incertain à la caisse de la vente.
- **Un avoir remboursé (`RemboursementAvoirClient`) apparaît aussi**, mais différemment :
  côté client (règle 20), c'est désormais un **vrai** `MouvementCaisse` (type `sortie`,
  généré par `CaisseMouvementService`, `reference` = le `RemboursementAvoirClient`) — il
  compte donc normalement dans "Total sorties" et le solde théorique du tiroir (il sort
  réellement de la caisse). `decorerLigneJournal()` lui donne un libellé distinct
  (« Remboursement avoir ») pour ne pas le confondre avec une sortie manuelle
  quelconque. Côté fournisseur, en revanche, un remboursement d'avoir reste hors caisse
  (Caisse Générale, règle 20) et n'apparaît dans aucun de ces deux rapports — visible
  uniquement via la Trésorerie.

---

## Trésorerie (Caisse Générale et Comptes)

- **`CompteTresorerie`** : compte de trésorerie de l'entreprise, `type` = `caisse_generale`
  | `banque` | `autre`. Un seul enregistrement `caisse_generale` existe (singleton créé
  par un seeder au déploiement, jamais créable/supprimable/renommable depuis l'UI) ;
  les comptes `banque`/`autre` se créent librement (nom + type). Solde **dérivé**, jamais
  écrasé : somme des `EcritureCompteTresorerie` (règle 21), exactement comme un compte
  client/fournisseur — aucune notion de session, de caissier assigné, ni d'ouverture/
  fermeture.
- **Volontairement indépendant des caisses de vente des caissiers** (`Caisse`/
  `SessionCaisse`/`MouvementCaisse`, voir « Mouvements de caisse » ci-dessus) : les deux
  univers ne se mélangent jamais comptablement, même s'ils apparaissent côte à côte dans
  l'écran « Trésorerie » (entrée dédiée du menu — voir « Interface »).
- **Dépôt automatique à la clôture d'une session** : dès qu'une session de caissier est
  clôturée (règle 9), le montant **compté** (pas le théorique) génère une écriture
  `depot_session_cloturee` sur la Caisse Générale — modélise le geste physique de vider
  le tiroir dans le coffre. Aucune étape manuelle à faire, aucun oubli possible.
  `CaisseSessionService::cloturer()` reste la seule méthode qui déclenche ce dépôt, dans
  la même transaction que la clôture.
- **Sorties/entrées manuelles** : motif (obligatoire) + montant, comme un mouvement de
  caisse manuel (règle 19) mais sur un `CompteTresorerie` au lieu d'une session — une
  sortie ne peut jamais dépasser le solde actuel du compte
  (`SoldeTresorerieInsuffisantException` sinon).
- **Virement interne** entre deux comptes de trésorerie (ex. Caisse Générale → compte
  bancaire) : une sortie sur la source et une entrée sur la destination dans la même
  transaction, sans valorisation supplémentaire — même principe qu'un transfert de stock
  inter-magasin.
- **Alimente automatiquement la part espèces d'un règlement fournisseur** (règle 17) et
  **d'un remboursement d'avoir fournisseur** (règle 20) : sortie pour un règlement
  fournisseur, entrée pour un remboursement d'avoir fournisseur (il nous rembourse) —
  aucune session de caisse de caissier requise pour ces deux flux, contrairement à un
  règlement **client** (règle 14) qui reste, lui, toujours rattaché à la session du
  caissier qui l'encaisse. **Un remboursement d'avoir client, lui, ne passe plus par
  ici** (règle 20 amendée) : la part espèces sort désormais du tiroir de la session du
  caissier qui rembourse (`CaisseMouvementService`, comme une sortie de caisse manuelle),
  pas de la Caisse Générale.
- **Un `Compte` n'a aucun lien avec les `MoyenPaiement`** de la vente (Espèces, Mobile
  Money, Carte…) : c'est une poche de trésorerie interne, jamais une destination
  automatique d'un encaissement à la vente.
- **Permissions dédiées** `tresorerie.voir`/`tresorerie.gerer`, distinctes de
  `caisse.mouvement`/`caisse.gerer` — accordées au Gérant et au Superadmin, jamais au
  Caissier (« la Caisse Générale n'a rien à voir avec les caisses des caissiers »).
- **Rapport dédié** (`rapports.tresorerie`) : tous les mouvements de trésorerie, filtrable
  par compte/type/période, exportable PDF/Excel — **séparé** de
  `rapports.mouvements-caisse` qui reste scopé aux tiroirs des caissiers.
- **Écran « Trésorerie » (`comptabilite.caisses.index`, entrée dédiée du menu)** :
  répertoire listant la Caisse Générale, les Comptes, et **toutes** les caisses de vente
  (pas seulement celles actuellement ouvertes — un répertoire de trésorerie, pas l'écran
  opérationnel du jour, voir `/sessions`). Cliquer sur une caisse de vente ouvre un
  **rapport dédié à cette caisse** (`comptabilite.caisses-vente.show`), toutes sessions
  confondues (contrairement à `/sessions/{session}`, scopé à une session précise) : mêmes
  KPI et même journal (ventes + mouvements manuels) que `rapports.mouvements-caisse`,
  juste pré-filtré sur cette caisse, avec export PDF/Excel qui réutilise ces mêmes routes
  (`caisse_id` forcé). Nommé "Comptabilité" en interne (routes, contrôleur) mais jamais
  affiché ainsi à l'écran, pour ne pas laisser croire à une comptabilité générale (hors
  périmètre, voir « Hors périmètre »).

---

## Dépenses

- **Une dépense = une sortie d'argent dont le motif est marqué « dépense »**
  (`MotifMouvement.est_depense` : loyer, salaires, facture…). Il n'y a **qu'une seule
  façon de sortir de l'argent** — la sortie de caisse (règle 19) ou de trésorerie — et
  c'est le motif choisi qui décide si elle compte dans les dépenses : jamais deux
  écrans concurrents qui feraient la même chose, jamais une sortie « à requalifier »
  après coup. Un retrait du propriétaire, un appoint, un dépôt en banque restent des
  sorties ordinaires (motif non marqué).
- **Les motifs de départ qui sont des charges sont cochés d'office**
  (`MotifMouvementSeeder::MOTIFS` : loyer, salaires, eau, électricité, transport,
  entretien, frais bancaires…) ; pour un commerce déjà installé, la migration des
  dépenses les coche une fois. Restent décochés ce qui ne fait que déplacer de
  l'argent (appoint, dépôt en banque) et **« Paiement fournisseur en espèces »** : la
  marchandise est déjà dans le coût des ventes, la compter en dépense la retirerait
  deux fois du résultat. Un motif créé par le commerce n'est jamais coché à sa place,
  et relancer le seeder ne revient pas sur ses réglages.
- **`Depense`** : motif (libellé figé à la saisie, comme `motif` sur un mouvement),
  montant, bénéficiaire (facultatif), magasin concerné (nul = toute l'entreprise),
  source (`session_caisse_id` OU `compte_tresorerie_id`), auteur, photo du reçu
  facultative (medialibrary, collection `recu`, réduite comme une image produit —
  `ImageProduit::attacherA()`).
- **Écrite uniquement par `DepenseService`**, dans la **même transaction** que le
  `MouvementCaisse` (tiroir d'une session ouverte) ou l'`EcritureCompteTresorerie`
  (Caisse Générale, compte) qui la paie, dont elle est la `reference`. Les plafonds
  existants s'appliquent donc tels quels (`SoldeCaisseInsuffisantException`,
  `SoldeTresorerieInsuffisantException`) : **pas de dépense à crédit**, pas de dépense
  « à payer plus tard » — on n'enregistre que de l'argent réellement sorti.
- **Trois points d'entrée, une seule règle** : l'écran « Dépenses → Nouvelle dépense »
  (`DepenseController`), le formulaire de mouvement d'une session
  (`SessionCaisseController::storeMouvement()`) et celui d'un compte de trésorerie
  (`ComptabiliteController::storeMouvement()`). Le champ motif est partagé
  (`partials/champ-motif-mouvement.blade.php`) : choisir un motif « dépense » propose
  le bénéficiaire. Une **sortie** doit toujours prendre son motif dans la liste (le
  bouton « + » en crée un à la volée, avec la case « C'est une dépense »).
- **Permissions** : `depense.voir` (liste, exports), `depense.creer`, `depense.annuler`.
  Un caissier **sans** `depense.creer` ne voit pas les motifs « dépense » dans son
  formulaire de mouvement et se voit refuser (403) une sortie sous un tel motif ; avec
  la permission, il paie depuis **sa propre session ouverte** uniquement. Payer depuis
  la Caisse Générale ou un compte exige en plus `tresorerie.gerer`. Gérant : les trois
  par défaut ; Caissier : aucune (à accorder au cas par cas).
- **Annulation, jamais de modification ni de suppression** (`DepenseService::annuler()`,
  raison obligatoire) : la dépense reste dans la liste, barrée, hors totaux
  (`Depense::effectives()`), et l'argent revient par un **mouvement inverse** — dans le
  tiroir si la session est encore ouverte, sinon à la Caisse Générale (une session
  clôturée y a déjà déposé son contenu, règle 21) ou sur le compte d'origine.
- **Journal de caisse** : la sortie liée à une dépense y est libellée « Dépense » (et
  son retour « Annulation de dépense »), comme « Remboursement avoir » — c'est un vrai
  `MouvementCaisse`, compté normalement dans les sorties et le solde théorique.
- **Écran « Dépenses »** (`depenses.index`, menu Pilotage) : un récapitulatif **fin**
  (`.recap-flux`, une seule carte basse), puis le détail filtrable (période, motif,
  magasin, recherche) et paginé, exports comme toute liste. Le récapitulatif montre le
  total à gauche et, à droite, les motifs en lignes serrées — **motif et montant
  seulement**, ni barre ni pourcentage — avec la flèche et le rouge des sorties
  d'argent (comme le « − » des journaux de caisse). Les 6 premiers postes, la suite
  résumée sous « N autres motifs » et dépliable : sa hauteur ne dépend ni du nombre de
  dépenses ni, repliée, du nombre de motifs.
- **Jeu d'essai** (`DepenseTestSeeder`, refusé en production) : 30 dépenses du début du
  mois à aujourd'hui, payées par `DepenseService` depuis la Caisse Générale après un
  apport du même total (solde inchangé) ; cet apport sert de témoin, le relancer
  n'ajoute rien.
- **Carte « Résultat » du tableau de bord** (gérant et superadmin, même filtre de
  période) : **marge brute** (ventes − coût des marchandises vendues, même calcul que
  le rapport de marge) **− dépenses** (hors annulées) **− pertes de stock** (casse,
  estimée au CMP **actuel** — le mouvement de casse ne fige pas le coût du jour) =
  **bénéfice ou perte**. Un résultat simplifié, pas une comptabilité : les achats encore
  en stock n'y figurent pas (comptés à la vente), un règlement fournisseur n'est pas
  une dépense (déjà dans le coût des marchandises). Vue d'un magasin : ses dépenses
  seulement ; une dépense « toute l'entreprise » n'apparaît que dans la vue consolidée.

---

## Dépôts

- Un **dépôt** est un lieu de stockage sans vente : même entité `Magasin`, différenciée
  par un champ `type` (`magasin` | `depot`).
- Un dépôt ne peut **jamais** avoir de caisse ni de session — la création d'une caisse ne
  propose que les magasins de type `magasin`.
- Partout ailleurs (stock, mouvements, transferts, destination de ligne d'achat, devis,
  inventaire, rapports), un dépôt se comporte comme un magasin normal : il détient du
  stock et peut être source/destination d'un transfert.

---

## Remises (vente)

- **Par ligne** : montant OU pourcentage. **Sur le total** : montant OU pourcentage.
- Ordre : remises de ligne d'abord, puis remise sur le sous-total.
- Stocker le type (`montant`|`pourcentage`), la valeur saisie **et** le montant résolu
  appliqué, pour des tickets et rapports reproductibles.

---

## Argent et arrondis

- Devise **XOF**, **sans sous-unité** → montants en **entiers (francs)**. Jamais de flottant.
  **Seule exception** : le prix d'achat indicatif d'un bon de commande (`prix_achat` et
  tout ce qui en dérive — HT/TTC de ligne, totaux du bon de commande), en décimal 2
  chiffres, jamais arrondi à l'entier (voir « Achat à crédit et comptes fournisseurs ») —
  le prix réellement facturé à la réception (`prix_achat_reel`), lui, reste entier comme
  partout ailleurs.
- Remise en pourcentage → montant arrondi à l'entier ; règle d'arrondi unique, appliquée
  partout **sauf** le prix d'achat indicatif ci-dessus (`Arrondi::prixAchat()`, 2
  décimales, jamais l'entier).
- **Pas de taxes côté vente** dans le MVP (aucun taux, aucune ligne taxe sur le ticket).
  Les taxes existent **uniquement côté achat** (voir « Achat à crédit et comptes
  fournisseurs ») : `prix_achat` = HT indicatif (décimal, non arrondi), TTC dérivé avec
  la même précision — jusqu'à la réception, où le montant réellement facturé redevient
  entier comme le reste de l'application.
- Clôture de session = `fond de caisse + total ventes espèces + total règlements clients
  espèces + total entrées de caisse − total sorties de caisse` (voir « Mouvements de
  caisse » et règle 19 ; un règlement fournisseur n'entre dans ce calcul que pour sa part
  payée en espèces, via la sortie de caisse liée qu'elle génère — voir règle 17).

---

## Rôles et permissions (dynamiques, spatie/laravel-permission)

- **Rôles créés à la volée** par l'admin, avec attribution de permissions à la carte.
  Ne pas coder les autorisations en dur ; toujours vérifier via permission.
- **Convention de nommage : `module.action`** — ex. `vente.creer`, `vente.credit`,
  `vente.retour`, `devis.creer`, `devis.transformer`, `achat.creer`,
  `achat.receptionner`, `achat.retour`, `produit.voir`, `produit.modifier`,
  `stock.ajuster`, `stock.transferer`, `inventaire.realiser`, `caisse.ouvrir`,
  `caisse.cloturer`, `caisse.mouvement`, `tresorerie.voir`, `tresorerie.gerer`,
  `client.gerer`, `client.reglement`, `client.depasser_limite`, `fournisseur.voir`,
  `fournisseur.gerer`, `fournisseur.reglement`, `taxe.gerer`, `typeclient.gerer`,
  `motif.gerer`, `depense.voir`, `depense.creer`, `depense.annuler`, `rapport.voir`,
  `utilisateur.gerer`, `role.gerer`, `parametre.gerer`.
- **Ajouter une permission** = l'ajouter à `config/permissions.php` : chaque
  déploiement la crée en base (`php artisan app:apres-mise-a-jour`, qui relance
  `RolePermissionSeeder` puis vide le cache des permissions) sans jamais toucher aux
  réglages des rôles existants. Elle n'est donc donnée à personne d'office : pour
  qu'un rôle par défaut déjà installé la reçoive (ex. le Gérant), une migration doit
  le faire, comme celle des dépenses.
- **Après chaque mise à jour du code** : `php artisan migrate --force` puis
  `php artisan app:apres-mise-a-jour` (permissions, cache des permissions, miniatures
  de photos manquantes) — déjà dans `.github/workflows/deploy.yml` ; sur la
  plateforme, pour chaque entreprise via `entreprises:executer --toutes --`.
- **Superadmin** : bypass total via `Gate::before` (ne reçoit pas de permissions).
- **Noyau de permissions système protégé** : non attribuable aux rôles créés à la volée.
- Rôles par défaut seedés : Superadmin, Gérant, Caissier — **modifiables**.

---

## Modèle de domaine (entités clés)

- **Magasin** : point de vente ou dépôt de stockage, distingués par un champ `type`
  (`magasin` | `depot`, voir « Dépôts »). Un dépôt n'a jamais de caisse.
- **Produit** : SKU (unique), nom, libellé distinctif, catégorie, **prix pièce** (prix de
  vente unitaire de l'unité de base — pas nécessairement une pièce physique : peut être le
  mètre, le kilo, le litre… selon le produit), seuil d'alerte, image (medialibrary).
- **UniteVente** (optionnelle, plusieurs par produit) : rattachée à un produit ; facteur
  entier (multiple de l'unité de base) + prix total propre. Libellé UI retenu :
  « **Unité de vente** » (ex. rouleau de 50 m pour un câble dont l'unité de base = 1 m,
  sac de 25 kg pour un produit dont l'unité de base = 1 kg, carton de 12 pour des vis à
  la pièce). L'unité de base elle-même n'est pas une ligne `UniteVente` : c'est le
  `prix_piece` du produit.
- **Stock** : quantité par (produit × magasin), *dérivée des mouvements* ; porte aussi le
  **coût moyen pondéré (CMP)**, recalculé à chaque entrée d'achat.
- **MouvementStock** : immuable ; type = réception | vente | casse | transfert |
  ajustement | annulation | retour_client | retour_fournisseur ; quantité en pièces ;
  magasin ; référence source.
- **Inventaire** : fiche par magasin (date, statut brouillon/validé).
- **LigneInventaire** : produit + quantité comptée + écart calculé.
- **Fournisseur** : nom, **code** (identifiant type `FRN-000001`, saisi ou généré
  automatiquement, comme le SKU produit), téléphone, e-mail, adresse, actif. Référentiel
  central, comme `Client`.
- **CommandeAchat** (« bon de commande » à l'écran) : un numéro, un fournisseur, une
  date, des lignes à prix **indicatifs** ; pas de magasin d'en-tête (la destination est
  par ligne, voir `LigneCommandeAchat`). Statut `brouillon` → `validee` (confirmation
  simple : envoyée au fournisseur, prête à être réceptionnée — n'impacte ni stock ni
  argent) → réceptionnée une ou plusieurs fois (voir `ReceptionAchat`). Un produit peut
  être acheté à des prix différents dans le temps : le **CMP** (recalculé à chaque
  réception, au prix réellement facturé) lisse ça en une seule valeur de référence par
  (produit × magasin).
- **LigneCommandeAchat** : produit + unité d'achat (pièce ou `UniteVente`, même
  référentiel qu'à la vente) + quantité + **prix d'achat HT indicatif** (`decimal(14,2)`,
  2 décimales, jamais arrondi à l'entier — voir « Argent et arrondis ») + **taxe**
  optionnelle (`Taxe`) + **destination prévue optionnelle** (`Magasin`, magasin ou dépôt —
  un plan facultatif, potentiellement modifié à la réception ; obligatoire uniquement
  pour l'achat direct, voir ci-dessus) ; TTC dérivé, jamais stocké.
- **Taxe** : nom + taux (%) + actif. Référentiel préenregistré, utilisé uniquement côté
  achat (voir « Argent et arrondis »).
- **ReceptionAchat** (« bon d'achat » à l'écran) : immuable, comme un mouvement (règle
  2/18) — numéro en série indépendante (`BA-000123`, comme `BC-000123` pour la commande
  et `RF-000123` pour un retour fournisseur), la `CommandeAchat` (obligatoirement
  `validee`) qu'elle réceptionne, motif optionnel, auteur. Matérialise l'événement réel
  (marchandise + prix + argent) : c'est elle, et seulement elle, qui mouvemente le stock,
  recalcule le CMP et pose la dette fournisseur — jamais `CommandeAchat`. Une même
  commande peut avoir plusieurs réceptions successives (partielles), jusqu'à réception
  complète.
- **LigneReceptionAchat** : `LigneCommandeAchat` d'origine + produit + **destination
  réelle** (`Magasin`, choix éditable au moment de la réception, pré-rempli avec le plan
  de la ligne mais librement modifiable — une même ligne de commande peut ainsi finir
  répartie sur plusieurs destinations via plusieurs réceptions) + quantité en pièces
  (plafonnée au reste à recevoir de la ligne) + **prix d'achat réel HT** par pièce (peut
  différer de l'indicatif de la ligne de commande) + taxe (reprise de la ligne d'origine).
- **PaiementAchat** : rattaché à une `CommandeAchat` (toujours renseigné, y compris pour
  un paiement de réception) et, le cas échéant, à une `ReceptionAchat` précise ; moyen +
  montant (paiement mixte possible), saisi **à chaque réception** (pas à la validation
  du bon de commande). Le reste (total TTC de la réception − ses paiements) devient une
  dette fournisseur pour cette réception (voir « Achat à crédit et comptes
  fournisseurs »).
- **Caisse** : rattachée à un magasin ; au plus une session ouverte à la fois.
- **SessionCaisse** : caissier + fond de caisse + ouverture/clôture + écart ; totaux
  espèces stockés à la clôture (ventes, règlements clients, entrées et sorties de caisse
  — voir « Mouvements de caisse »).
- **MouvementCaisse** : immuable (règle 19) ; type = entree | sortie ; montant (toujours
  positif, direction portée par `type`) ; motif (obligatoire) ; session de caisse ;
  auteur. Réservé aux mouvements manuels d'un caissier sur sa propre session — la part
  espèces d'un règlement fournisseur ou d'un remboursement d'avoir passe désormais par
  `EcritureCompteTresorerie` (Caisse Générale, règle 21), jamais par ici.
- **MotifMouvement** : nom, actif, **est_depense** (une sortie sous ce motif est une
  dépense — voir « Dépenses »). Référentiel des motifs proposés pour un mouvement de
  caisse ou de trésorerie.
- **Depense** : motif (figé) + `MotifMouvement` d'origine, montant, bénéficiaire
  (nullable), magasin (nullable = toute l'entreprise), session de caisse **ou** compte
  de trésorerie qui l'a payée, auteur ; annulable (date, auteur, raison), jamais
  modifiable ni supprimable. Toujours créée avec la sortie d'argent qui la paie
  (`DepenseService`).
- **CompteTresorerie** : compte de trésorerie de l'entreprise (voir « Trésorerie ») ;
  nom, type (`caisse_generale` | `banque` | `autre`), actif. Un seul enregistrement
  `caisse_generale` (singleton), indépendant des `Caisse`/`SessionCaisse` des caissiers.
- **EcritureCompteTresorerie** : immuable ; compte de trésorerie, type
  (`depot_session_cloturee` | `sortie_manuelle` | `entree_manuelle` |
  `reglement_fournisseur` | `remboursement_avoir_client` | `remboursement_avoir_fournisseur`
  | `virement_sortant` | `virement_entrant`), montant **signé** (+ entrée / − sortie,
  contrairement à `MouvementCaisse`), motif (nullable), référence optionnelle
  (`SessionCaisse`, `ReglementFournisseur`, `RemboursementAvoirClient`,
  `RemboursementAvoirFournisseur` ou un autre `CompteTresorerie` pour un virement),
  auteur. Le solde d'un compte est la somme de ses écritures (règle 21).
- **Vente** : numéro (`M{magasin}-C{caisse}-{séquence}`, préfixé `BL-` pour un bon de
  livraison — `type = bon_livraison`, voir plus bas — même compteur de séquence que les
  factures de cette caisse, seul le préfixe change), lignes, remise total, session,
  magasin, **client** (nullable — obligatoire si la vente est à crédit), **avoir_applique**
  (part de l'avoir client déjà déduite du solde à crédit posé, figée à la création — voir
  « Vente à crédit et comptes clients »).
- **LigneVente** : produit + (pièce **ou** unité de vente) + quantité + prix appliqué +
  remise de ligne + **coût appliqué** (CMP figé au moment de la vente, pour un rapport de
  marge reproductible dans le temps).
- **Paiement** : rattaché à une vente ; moyen + montant (plusieurs = paiement mixte). Une
  vente à crédit peut avoir un total de paiements **inférieur** au net à payer.
- **MoyenPaiement** : configurable, actif/inactif ; espèces par défaut.
- **VenteEnAttente** : panier non finalisé + caissier propriétaire ; sans mouvement ni
  paiement ; reprise = continuation du panier au **prix courant** (pas de figeage de prix).
- **Client** : nom, **code** (identifiant type `CLI-000001`, saisi ou généré
  automatiquement, comme le SKU produit), **type de client** (`TypeClient`, optionnel),
  téléphone, adresse, **limite de crédit** (nullable = illimitée), actif. Référentiel
  central, comme le catalogue.
- **TypeClient** : nom + actif. Référentiel libre (ex. particulier, maçon, entrepreneur),
  associé à un client pour catégorisation/reporting — aucune logique métier attachée dans
  le MVP.
- **EcritureCompteClient** : immuable ; type = vente_credit | reglement | retour_client |
  remboursement_avoir ; montant signé ; client ; référence source (`Vente`,
  `ReglementClient`, `RetourVente` ou `RemboursementAvoirClient`) ; auteur. Le solde du
  client est la somme de ses écritures (règle 12).
- **ReglementClient** : encaissement d'une dette client ; client + montant + moyen de
  paiement + session de caisse + caissier ; immuable, comme une vente (règle 14).
- **RemboursementAvoirClient** : remboursement d'un avoir client (règle 20) ; client +
  session de caisse + montant + auteur ; immuable. La part espèces sort du tiroir de
  cette session de caisse (`CaisseMouvementService`), jamais la Caisse Générale.
- **RemboursementAvoirClientPaiement** : rattaché à un remboursement d'avoir client ;
  moyen + montant (paiement mixte possible).
- **EcritureCompteFournisseur** : immuable ; type = achat_credit | reglement |
  retour_fournisseur | remboursement_avoir ; montant signé ; fournisseur ; référence
  source (`CommandeAchat`, `ReglementFournisseur`, `RetourAchat` ou
  `RemboursementAvoirFournisseur`) ; auteur. Le solde du fournisseur est la somme de ses
  écritures (règle 16).
- **ReglementFournisseur** : encaissement d'une dette fournisseur ; fournisseur + montant
  + auteur ; jamais de session de caisse de caissier (règle 17, contrairement à
  `ReglementClient` où la session est toujours obligatoire) — la part espèces alimente
  la Caisse Générale (règle 21). Immuable.
- **ReglementFournisseurPaiement** : rattaché à un règlement fournisseur ; moyen + montant
  (paiement mixte possible).
- **RemboursementAvoirFournisseur** : remboursement d'un avoir fournisseur, symétrique de
  `RemboursementAvoirClient` (règle 20) ; fournisseur + montant + auteur ; immuable. La
  part espèces entre dans la Caisse Générale (règle 21).
- **RemboursementAvoirFournisseurPaiement** : rattaché à un remboursement d'avoir
  fournisseur ; moyen + montant (paiement mixte possible).
- **RetourVente** : retour client, immuable (règle 18) ; numéro, `Vente` + `Client`
  d'origine, **session de caisse** (celle où le retour est traité, nullable — absente
  sur les retours antérieurs à cette exigence), motif optionnel, montant total (avoir
  crédité), auteur.
- **LigneRetourVente** : `LigneVente` d'origine + produit + magasin (source du mouvement
  de retour) + quantité en pièces + montant + coût appliqué (repris de la ligne de
  vente, pour la marge).
- **RetourAchat** : retour fournisseur, symétrique de `RetourVente` ; `CommandeAchat`
  (validée) + `Fournisseur` d'origine, motif optionnel, montant total, auteur.
- **LigneRetourAchat** : `LigneCommandeAchat` d'origine + produit + magasin (destination
  reprise par le mouvement de retour) + quantité en pièces + montant.
- **Devis** : client (obligatoire), magasin, auteur, statut (brouillon | refusé |
  transformé | expiré), remise totale, date de validité, référence vers la `Vente` une
  fois transformé. Modifiable tant que non transformé/expiré.
- **LigneDevis** : produit + (pièce ou unité de vente) + quantité + remise de ligne ;
  **aucun prix stocké** (indicatif au catalogue courant, comme `LigneVenteEnAttente`).
- **Utilisateur** : rôle(s), magasin de rattachement.

---

## Interface (ERP type Odoo)

- **Layout à sidebar** (`layouts/app.blade.php`) : menu vertical à gauche + barre
  supérieure fine (bouton de bascule du menu, titre de la page, raccourci « Caisse
  ouverte », cloches de notification, compte utilisateur). Le menu
  (`partials/sidebar.blade.php`) est décrit par **un seul tableau** sections → entrées →
  sous-entrées : Tableau de bord ; *Opérations* (Vente, Achats, Stock, Catalogue) ;
  *Pilotage* (Trésorerie — Caisse Générale/Comptes, Gérant/Superadmin uniquement, voir
  « Trésorerie » —, Dépenses et Rapports) ; *Configuration* (Administration). Un groupe ne
  s'affiche que si au moins une de ses sous-entrées est visible pour l'utilisateur, et
  son état actif se déduit d'elles — **jamais** une seconde liste de permissions/routes
  à maintenir sur le parent (c'était la source d'un menu « Stock » au HTML cassé pour un
  rôle ayant `stock.transferer` sans `stock.voir`). Ajouter un écran = ajouter une ligne
  à ce tableau.
  Les entrées/sorties de tiroir des caissiers ne sont **pas** un menu séparé : elles se
  saisissent directement sur l'écran de session de vente (`/sessions/{session}`, voir
  « Mouvements de caisse ») — `sessions.index`/`sessions.show` couvraient déjà
  l'essentiel de ce qu'un onglet « Mes caisses » dédié aurait montré, donc pas de
  duplication.
- **Sidebar réductible en miniature** (desktop ≥ lg) : icônes seules (classe
  `erp-sidebar-mini` sur `<html>`), le libellé d'un lien simple devient une infobulle et
  un groupe ouvre son sous-menu en panneau volant au survol/focus. Choix mémorisé **par
  poste** (`localStorage`, clé `erp.sidebar.mini`) et réappliqué par un script en tête
  du layout, avant le premier rendu (pas de saut visuel) ; sans préférence enregistrée,
  réduite d'office sous 1200 px de large. Sur mobile/tablette (< lg), le **même** élément
  devient un panneau coulissant (`.offcanvas-lg` de Bootstrap), toujours déplié — un seul
  menu dans le DOM, mêmes regroupements/permissions, seule la présentation change.
- **« Vente → Facture/BL » mène directement à la session en cours si le caissier connecté
  en a déjà une d'ouverte** (`sessionCaissierOuverteMenu`, `AppServiceProvider::boot()`,
  partagé avec `layouts.app`) — plutôt que de repasser par `sessions.index` (sélection
  d'une caisse libre) à chaque fois, alors qu'une caisse n'a qu'une session ouverte à la
  fois par caissier (règle 7). Sans session active, le lien reste `sessions.index` comme
  avant. La même donnée alimente la pastille « Caisse ouverte » de la barre supérieure
  (`partials/topbar.blade.php`), visible depuis n'importe quel écran, y compris menu
  réduit.
- **Aperçus d'écran** (`views/apercus/*`, cadre `x-apercu-ecran`) : écrans stylisés de
  l'application, dessinés en vectoriel en ligne, montrés sur la **page de connexion**
  (présentation à gauche du formulaire, masquée sous lg) et sur le site vitrine de la
  plateforme. Toutes leurs couleurs viennent des classes de `.illu`
  (`resources/sass/app.scss`) : jamais une couleur écrite dans la vue. Les données
  montrées sont des exemples sans secteur précis.
- **Tableaux de bord** (`views/dashboard/*`, et `plateforme/tableau-de-bord` pour
  l'éditeur) : même grammaire partout — `x-kpi-card stacked` pour un indicateur
  (libellé, valeur jamais tronquée, précision ou évolution, lien vers l'écran concerné),
  `x-liste-barres` pour comparer des montants (à la place d'un camembert ou d'un
  tableau), `.carte-titre`, `.etat-vide`. Les couleurs d'état (vert, ambre, rouge) y
  sont **réservées à ce qui signale un état** ; le reste porte la primaire ou un gris.
  Une évolution (« vs semaine/mois dernier ») compare toujours à la période précédente
  **arrêtée au même point**, jamais à une période entière. La **période d'analyse**
  (`?periode=jour|7j|30j|mois|mois_dernier`, « ce mois » par défaut) porte sur la courbe
  (heure par heure pour « aujourd'hui »), les moyens de paiement, l'aperçu financier,
  les meilleures ventes et le comparatif par magasin — **pas** sur les trois chiffres
  du haut (jour, semaine, mois), qui ont chacun la leur. Jamais deux échelles sur un
  même graphique : deux mesures sans rapport = deux graphiques.
- **Dashboard adapté au rôle** : caissier → sa session ; gérant → son magasin (CA, panier
  moyen, top produits, stock sous seuil, écarts de caisse, **total des créances clients**,
  clients en dépassement de limite) ; superadmin → consolidé multi-magasin. Graphiques
  (ventes dans le temps, moyens de paiement, top produits).
- **Aperçu financier du mois** (dashboard gérant/superadmin) : quatre chiffres volontairement
  côte à côte pour qu'ils ne se confondent jamais — total ventes (`total_net`, inclut crédit
  et avoir), total dû (créances clients, solde positif **par client**, jamais net entre
  clients, tous magasins confondus comme la dette fournisseurs), avoirs appliqués du mois
  (`Vente.avoir_applique`), et total en caisse du mois (espèces réellement encaissées —
  paiements à la vente **et** règlements clients, règle 10 ; jamais confondu avec le total
  ventes). Le graphique « Moyens de paiement » compte lui aussi les deux sources.
- Palette **neutre, sans connotation de secteur** : primaire **bleu** (`#2563eb`),
  accents mélangés (cyan sarcelle, ambre foncé, gris ardoise), surfaces claires
  (sidebar et barre supérieure blanches, fond de page gris très clair, cartes blanches à
  bordure fine). Définie dans `resources/sass/app.scss` (variables Bootstrap `$primary`
  et suivantes) — toujours modifier la palette à cet endroit, jamais de couleur codée en
  dur dans une vue. La primaire est **personnalisable par installation** (Paramètres →
  couleur, `PaletteCouleur` + `partials/couleur-primaire.blade.php`) : tout élément
  teinté (entrée de menu active, avatar…) doit dériver des variables `--bs-primary*`,
  jamais de `$primary` en dur côté composant ; `PaletteCouleur::DEFAUT` doit rester
  identique à `$primary`. `$warning` est un **ambre foncé** (`#92650a`), pas un jaune
  franc : un jaune type `#f5b800` tombe sous le seuil WCAG AA (~1.6:1 de contraste sur
  les surfaces claires du thème) — quasi illisible en texte comme en libellé de bouton.
- **Police fine** : Inter (compilée via `vite.config.js`, graisses 400/500/600
  uniquement — chaque gabarit doit porter la directive `@fonts` avant `@vite`, sans
  quoi la page retombe sur la police du système), base 14 px, titres en 500, « gras » plafonné à 600 par les variables
  Bootstrap (`$font-weight-*`) — donc appliqué partout sans toucher aux vues. Ne pas
  réintroduire de graisse 700 ni de taille de base 16 px vue par vue.
- Listes en **DataTables serveur** (jamais charger toute une table en mémoire).
- **Toute liste a un menu « Exporter »** (`x-export-buttons`) : un seul bouton déroulant
  avec PDF, Excel, CSV et Imprimer — jamais une rangée de boutons, jamais un bouton
  « Imprimer » isolé qui imprimerait le HTML de la page. Les quatre entrées reposent sur
  **deux routes** par liste (trait `ExporteListe`) : `*.pdf` (téléchargement, ou
  impression avec `?imprimer=1`) et `*.excel` (xlsx, ou CSV avec `?format=csv` —
  séparateur `;`, UTF-8 avec BOM, pour s'ouvrir directement dans un Excel français). Un
  export porte toujours sur ce que l'écran affiche (mêmes filtres et tri, passés par la
  query string), sans la pagination : le contrôleur expose une seule requête
  (`requeteListe()`) partagée par `index()` et `lignesExport()`, jamais une copie des
  filtres. Quand le contrôleur exporte déjà un document unitaire sous `pdf()`/`excel()`
  (bon de commande, devis, bon de livraison), l'export de la liste s'appelle
  `listePdf()`/`listeExcel()` (routes `*.liste.pdf`/`*.liste.excel`). Tout export tableur
  sort par `ExporteListe::telechargerTableur()`, y compris ceux qui construisent leur
  propre classeur.
- Feedback explicite (toasts), sentence case, responsive (menu coulissant sur mobile).
- **Alertes et badges : la couleur dit le sens** — vert, c'est fait ; ambre, il faut
  agir ; rouge, c'est refusé ou bloquant ; cyan, une information. Les teintes douces
  (fond, bordure, texte foncé) sont fixées dans `resources/sass/app.scss`
  (`$success-bg-subtle`… avant l'import de Bootstrap) plutôt que déduites de la
  couleur pleine, qui donnait des beiges et roses grisés indistincts. Un statut
  s'affiche en **badge doux** (`badge-doux-{success|warning|danger|info|secondary}`),
  comme ceux des devis (`DevisStatut::classeBadge()`).
- **Détail d'un devis** (`devis/show.blade.php`) : une seule rangée de boutons de même
  taille, de la moins engageante à la principale — menu « Document » (imprimer, PDF,
  Excel), « Modifier », menu « … » qui range « Refuser / annuler » (irréversible, avec
  confirmation), puis « Transformer en vente », pleine largeur sur téléphone. Ce bouton
  n'est **jamais désactivé pour un manque de stock** : la transformation ouvre l'écran
  de vente avec le panier du devis, où les quantités se corrigent avant d'encaisser —
  l'alerte « Stock insuffisant » dit seulement quelles lignes ajuster. Une alerte par
  état (expiré, refusé, transformé, validité qui s'achève sous 3 jours, stock
  insuffisant). Un devis déjà refusé ne se
  refuse plus (`Devis::peutEtreAnnule()` : brouillon uniquement).
- **Jamais d'impasse sur une page vide.** Un commerce qui démarre n'a ni magasin, ni
  caisse, ni produit. Tant qu'il reste une étape avant la première vente, le tableau
  de bord affiche le guide « Mise en route » (`App\Support\PremiersPas`, étapes
  déduites des données, mémorisé une fois terminé). Un écran qui dépend d'un
  prérequis absent dit ce qui manque et y conduit par un bouton (`.etat-vide`) : choix
  d'une caisse sans caisse ou sans magasin, création d'une caisse sans magasin,
  création d'un produit sans catégorie, écran de caisse sans catalogue. Sans la
  permission nécessaire, le message renvoie vers le responsable au lieu du bouton.
- **Écran de caisse et devis** (`ventes/create.blade.php`, `devis/create|edit` ; logique
  dans `posApp` et `devisApp`, `resources/js/app.js`) : même disposition pour les deux.
  Le catalogue en vignettes est **un seul partiel** (`partials/catalogue-vignettes`) et
  **un seul morceau de JavaScript** (`avecCatalogue`, posé sur les deux composants) —
  jamais une copie par écran. Sur un devis, aucune vignette n'est bloquée par le stock
  (un devis ne mouvemente rien) et la ligne rappelle le stock de chaque lieu. À la
  caisse : produits en **vignettes** à gauche (recherche **« commence par »**, jamais
  « contient », sans accents ni casse — taper « b » montre tous les produits dont le
  nom commence par B, puis ceux dont le libellé distinctif commence par B ; la
  référence et le code-barres ne comptent que si la saisie contient un chiffre ;
  même règle que les sélecteurs de produit, `matcherCommencePar` ; filtre par
  catégorie, sur une rangée à flèches
  de défilement — `rangeeDefilante`, une flèche seulement du côté où il reste des
  catégories ; au plus 36 vignettes
  dessinées d'un coup, la suite à la demande), panier et encaissement à droite ; sous
  lg les colonnes s'empilent et un rappel du panier reste fixé en bas d'écran.
  **« Vendre » est le bouton principal** : vert, plus haut que tous les autres, le
  montant à payer écrit dessus, dans un bloc de validation (`.caisse__validation`,
  avec ce qui reste à corriger et la ligne compacte « Mettre en attente ») collé en
  bas de la fenêtre dès que le panier est à l'écran — jamais à chercher en bas d'un
  long panier. Le rappel mobile s'efface quand ce bloc est visible, pour ne pas le
  couvrir. En transformation de devis, le même bouton dit « Transformer en vente ». La
  touche Entrée ajoute directement une référence ou un code-barres exact (douchette).
  Un produit qui n'a **qu'une seule unité possible** (son unité de base) reçoit cette
  unité d'office et un second clic augmente sa quantité ; le **lieu de prélèvement
  reste toujours un choix explicite**, jamais posé par défaut. Remise et taxe d'une
  ligne sont repliées tant qu'elles ne servent pas. Les champs envoyés au serveur
  sont ceux des deux formulaires en bas de vue, et eux seuls. Le catalogue mis en
  cache (`Produit::catalogueVente()`) porte catégorie, code-barres et image : renommer
  une catégorie l'invalide aussi.
- **Image d'un produit** (`App\Support\ImageProduit`) : jamais plus de **1 Mo**
  enregistré. L'image envoyée (jusqu'à 8 Mo) est réduite à l'enregistrement —
  dimensions puis qualité — plutôt que refusée ; une miniature « vignette » (320 × 240)
  est produite en plus, et c'est elle que sert le catalogue de vente. Sans GD ni
  Imagick sur le serveur, l'image est gardée telle quelle si elle fait moins de 1 Mo,
  refusée sinon.
- **Import des produits** (`ImportProduitController`, `App\Services\ImportProduits`,
  Catalogue → Produits → Importer, permission `produit.creer`) : Excel ou CSV, à partir
  d'un modèle à télécharger ; colonnes reconnues à leur intitulé, dans n'importe quel
  ordre. **Tout ou rien** : le fichier est vérifié en entier, et à la moindre erreur
  rien n'est enregistré — chaque ligne fautive est signalée par son numéro. Catégories
  et unités absentes sont créées ; un produit existant n'est **jamais modifié** (une
  référence déjà prise est une erreur). Le stock initial, s'il est renseigné, entre par
  un mouvement (règle 1), au prix d'achat indiqué.
- **Réapprovisionnement en un clic** : depuis « Stock à réapprovisionner » du tableau
  de bord, `commande-achats.create?reappro=1` ouvre un bon de commande prérempli d'une
  ligne par produit sous son seuil (dans le magasin ou dépôt où il manque ; le seul
  magasin d'un gérant), quantité = de quoi remonter au double du seuil, prix = dernier
  coût moyen. Simple proposition : rien n'est enregistré tant que l'utilisateur ne
  valide pas, et un brouillon ne mouvemente rien.
- **Rupture de stock** : produit ou unité de vente en stock insuffisant affiché **grisé**
  avec mention « rupture de stock », non sélectionnable au panier (blocage en amont dans
  l'UI, pas d'erreur après coup).

---

## Performance (obligatoire)

- **Cache Laravel** : `config/route/view:cache` en prod ; `Cache::remember` pour les
  données de référence (catalogue, moyens de paiement) avec **invalidation** aux
  mises à jour.
- **Eager loading** (`with()`) systématique — zéro N+1.
- **Index** DB sur FK et colonnes filtrées (SKU, magasin_id, dates).
- **Pagination** partout ; DataTables mode serveur.
- Rapports lourds → vues SQL / agrégats mis en cache.

---

## Téléphone

- **Un seul endroit connaît les règles** : `App\Support\Telephone` (bibliothèque
  `giggsey/libphonenumber-for-php-lite`) — pays proposés (les 54 pays d'Afrique),
  validation selon le plan de numérotation de chaque pays, forme enregistrée, forme
  affichée. Jamais de règle de format recopiée dans un contrôleur ou une vue.
- **Enregistré au format international E.164** (`+2250700000001`), dans toutes les
  colonnes téléphone : `clients`, `fournisseurs`, `magasins`, `users`,
  `parametres.numero`, `configuration_abonnements` (téléphone, WhatsApp), `entreprises`
  (plateforme). Une seule écriture par numéro, quel que soit le pays ou la façon dont
  il a été tapé.
- **Saisi via `x-champ-telephone`** (sélecteur de pays + numéro sans indicatif),
  jamais un `<input>` texte libre. Le contrôleur appelle
  `Telephone::normaliserDansRequete($request)` AVANT la validation, puis valide avec
  la règle `TelephoneValide` : un numéro invalide pour le pays choisi est refusé,
  jamais enregistré tel quel. Un numéro d'un pays absent du sélecteur (fournisseur à
  l'étranger) reste accepté s'il est tapé en entier avec son « + ».
- **Affiché via le helper `telephone()`** (`+225 07 00 00 00 01`), y compris dans les
  documents imprimables et les exports.
- **Pays par défaut = pays du commerce** (`Parametre.pays`, code ISO, Côte d'Ivoire
  tant qu'il n'est pas réglé) : il fixe l'indicatif présélectionné dans tous les
  formulaires.
- **Recherche par téléphone** sur les chiffres seuls (`Telephone::pourRecherche()`) :
  « 07 00-00 » retrouve `+2250700000001`.
- Un ancien numéro saisi librement et non reconnu n'est ni modifié ni masqué : il
  s'affiche tel quel, et doit être corrigé à la prochaine modification de la fiche.
- **Tests** : ils tournent sur MySQL (base `360inmystore_test`, voir `phpunit.xml`) —
  les migrations ne sont pas compatibles SQLite.

---

## Plateforme SaaS (branche `multi-tenant`)

Cette branche est la plateforme en ligne, **toujours multi-client** — détail
d'exploitation dans `PLATEFORME.md`. La version installée (un commerce par
déploiement) est la branche `mono_client`, dont celle-ci hérite tout le métier.

- **Aucun interrupteur mono/multi**, ni dans `.env` ni à l'écran. Le domaine de la
  plateforme se déduit de `APP_URL` (`config/plateforme.php`) ; `DB_DATABASE` est la
  base de la plateforme.
- **Une base de données par entreprise** (`{préfixe}{sous_domaine}`) + la base de la
  plateforme. **Aucune colonne `entreprise_id` dans les tables métier**, aucun filtre à
  ajouter dans les requêtes : le cloisonnement est celui des bases. Les règles métier
  sont identiques à celles de `mono_client`.
- **Identification par sous-domaine**, avant le démarrage de l'application
  (`PlateformeServiceProvider`, rappel « booting ») : la configuration de la connexion
  par défaut est corrigée avant que quoi que ce soit ne soit créé — donc connexion,
  cache et sessions en base, stockage des images (`storage/app/public/entreprises/{id}`)
  pointent d'emblée vers l'entreprise. Ne jamais changer de base « à chaud » en cours
  de requête.
- **`plateforme()`** (`App\Plateforme\Plateforme`) est la seule source du contexte :
  `centrale` (domaine principal, valeur par défaut), `entreprise`, `introuvable`,
  `indisponible`. Ne jamais relire le nom d'hôte ou la configuration ailleurs.
- **Parcours client** : site vitrine (`plateforme.accueil`, tarifs = formules actives
  du catalogue) → inscription (`InscriptionController`, protégée par `AntiRobot`,
  limitation par IP, plafonds quotidiens, un espace par téléphone, adresses
  réservées) → création de l'espace (`CreationEntreprise`, tout ou rien) → arrivée
  **déjà connecté** dans l'espace par un lien à usage unique (`LienAcces`,
  `AccesEspaceController`) → **choix de la formule** (`abonnement.espace`) → usage.
- **Site vitrine** (`plateforme/accueil.blade.php`, gabarit `layouts/plateforme`,
  styles dans `resources/sass/_plateforme-public.scss`) : chaque module y est présenté
  avec ses sous-modules et une illustration. La liste `$modules` de la vue **reprend le
  menu de l'application** (`partials/sidebar.blade.php`) — un écran ajouté ou retiré du
  menu doit l'être aussi là. Les illustrations sont les aperçus d'écran partagés avec
  la page de connexion (voir « Interface »). Le logo du
  produit est dans `public/images/logo/` (déclinaisons couleur, fond sombre, favicon).
  Rien de chiffré n'y est inventé (pas de nombre de clients ni de témoignage).
- **Pas de formule en cours = espace fermé** : `Abonnement::estBloquant()` est vrai
  dans l'espace d'une entreprise tant qu'aucune activation n'existe (comme après
  expiration) ; tout ramène alors à la page des formules.
- **Formules = tarifs.** Le catalogue (`FormuleAbonnement::catalogue()`, base de la
  plateforme) est géré par l'éditeur (`plateforme.formules.*`) et alimente seul la
  page des tarifs et le choix dans chaque espace : nom, prix, durée, accroche,
  avantages (une ligne par élément), mise en avant. **Une formule à 0 F est l'essai
  gratuit**, prenable une seule fois, au démarrage (`AbonnementEnLigne::demarrerEssai()`).
  Une activation référence toujours une copie locale de la formule
  (`FormuleAbonnement::copieLocale()`).
- **Limites d'une formule** (`App\Plateforme\LimitesFormule`) : nombre maximal de
  magasins (les dépôts ne comptent pas), de caisses, d'utilisateurs et de produits,
  réglé par l'éditeur sur chaque formule (vide = sans limite) et affiché de lui-même
  sur la page des tarifs. La limite qui vaut est celle du **catalogue de l'éditeur**
  pour la formule en cours — un réglage vaut donc tout de suite pour ses clients ; si
  la formule a disparu du catalogue, celle gardée à la souscription. Elle ne fait que
  **refuser une création de plus** : rien de ce qui existe n'est retiré. Appliquée par
  le middleware `AppliqueLimitesFormule`, sur les routes par leur nom — pas dans les
  contrôleurs, partagés avec `mono_client` — et, pour l'import, par le point
  d'extension `limite.creation` du conteneur (absent de la version installée).
- **Sauvegardes des entreprises** (`Plateforme\SauvegardeController`, Entreprises →
  « Sauvegardes et restauration ») : chaque soir, la base de la plateforme puis celle de
  chaque entreprise, suspendues comprises (`entreprises:executer --toutes
  app:sauvegarder`) ; l'éditeur peut en lancer une, la télécharger, ou **restaurer** un
  espace — depuis la plateforme, avec `mysql` visant la base de l'entreprise (aucune
  connexion de l'application ne change de base), puis vidage du cache de l'espace dans
  son propre processus. Tracé sur l'entreprise.
- **Accès perdu d'un propriétaire** : il contacte l'éditeur (coordonnées du support
  affichées sur sa page de connexion), qui entre dans son espace par l'accès de
  maintenance et réinitialise son code depuis Administration → Utilisateurs.
- **Paiement derrière `PasserellePaiement`** — aujourd'hui `PasserelleSimulation` :
  **aucun argent encaissé**. Un paiement en ligne est toujours inscrit au registre
  AVANT l'activation (`AbonnementEnLigne`) : les deux vivent dans deux bases, sans
  transaction commune.
- **Espace de l'éditeur** (`plateforme.*`, domaine principal, réservé à
  `User::estGestionnaireAbonnement()`) : tableau de bord (`ConsoleController`),
  entreprises (`EntrepriseController` : suspension, réouverture, **activation d'un
  abonnement payé en direct** — `ActivationParEditeur`, paiement inscrit seulement
  une fois l'abonnement activé —, **accès de maintenance** à un espace, tracé des
  deux côtés), registre des paiements (`PaiementController`), formules et contact
  (`FormuleController`). Les écrans de gestion d'un commerce y sont fermés
  (`ReserveAuxCommerces`).
- **Dans l'espace d'une entreprise** : `estGestionnaireAbonnement()` est toujours
  faux (le propriétaire — rôle Superadmin de SON espace — choisit et paie sa
  formule, il ne s'active rien) ; la restauration d'une base et le dossier de
  sauvegarde sont refusés (`ParametreController::interdireDansUnEspaceHeberge()` — du
  SQL arbitraire s'y exécuterait avec un compte MySQL qui voit toutes les
  entreprises).
- **Modèles de la plateforme** (`App\Models\Plateforme\*`, base `ModelePlateforme`) :
  `Entreprise` (registre + copie de la formule et de l'échéance, pour la console
  uniquement — la source de vérité reste les activations de l'espace),
  `PaiementAbonnement` (registre immuable), `AccesEspace` (liens d'accès et registre
  des accès de maintenance). Migrations dans `database/migrations/plateforme`,
  exécutées uniquement sur la base de la plateforme.
- **Toute commande artisan visant une entreprise tourne dans un processus séparé**
  (`ProcessusEntreprise`, variable `PLATEFORME_ENTREPRISE`) : `entreprises:initialiser`,
  `entreprises:activer-abonnement`, et `entreprises:executer -- <commande>` pour les
  migrations et tâches planifiées. Une mise à jour = `migrate` sur la plateforme PUIS
  `entreprises:executer -- migrate --force`.
- **Limiter les modifications des fichiers partagés avec `mono_client`** : le code
  propre à la plateforme va dans `app/Plateforme`, `App\Models\Plateforme`,
  `Controllers/Plateforme`, `views/plateforme` — chaque retouche d'un fichier du
  métier complique la fusion suivante depuis `mono_client`.
- **Tests** : le contexte par défaut est le domaine principal ;
  `$this->dansUnEspace($proprietaire)` (`Tests\TestCase`) place un test dans l'espace
  d'une entreprise ; `plateforme()->definir(...)` simule les autres contextes.

---

## Sauvegardes

- **Un seul endroit construit les commandes** `mysqldump` / `mysql` :
  `App\Services\SauvegardeBase` (sauvegarde à la demande des Paramètres, sauvegarde
  automatique, restauration). Un fichier de sauvegarde s'appelle toujours
  `{auto|manuelle|avant-restauration}-AAAA-MM-JJ-HHMMSS.sql` : la purge, le
  téléchargement et la restauration n'acceptent que ce motif, jamais un nom de fichier
  fourni tel quel.
- **Chaque soir** (`app:sauvegarder`, planifiée dans `routes/console.php`) : une
  sauvegarde, et seules les **10 plus récentes** de chaque type sont gardées. Dossier :
  celui réglé dans Paramètres s'il existe (version installée), sinon
  `storage/app/sauvegardes/{base}`.
- **Une restauration sauvegarde toujours l'état actuel d'abord** (« avant-restauration ») ;
  si cette sauvegarde de sécurité échoue, rien n'est restauré.

## Accès perdu

- **Aucune réinitialisation en libre-service** : elle demanderait un code envoyé par
  SMS, qui n'est pas branché — et un code simplement affiché à l'écran laisserait
  n'importe qui prendre un compte. La page de connexion (« Mot de passe oublié ? »)
  dit qui contacter : un employé son responsable (Administration → Utilisateurs →
  « Réinitialiser le code ») ; le responsable, le support, dont les coordonnées
  (téléphone, WhatsApp, e-mail — `ConfigurationAbonnement`) sont saisies par l'éditeur.
- En dernier recours, depuis le serveur : `php artisan app:reinitialiser-code {username}`.

## Traçabilité

- **activitylog** sur entités sensibles (produits, prix, mouvements, ventes, sessions,
  utilisateurs, rôles, **clients, limites de crédit, règlements, devis, fournisseurs,
  règlements fournisseurs, taxes, comptes de trésorerie, dépenses et leur annulation**) :
  auteur, date, avant/après.
- **Historique connexions** : login/logout, date, utilisateur, IP (écouter les événements d'auth).

---

## Conventions

- Terminologie métier en **français** (magasin, caisse, mouvement, unité de vente,
  client, règlement…).
- Écritures stock/vente/**compte client**/**compte fournisseur**/**compte de trésorerie**
  **uniquement via une couche service** encapsulant la transaction ; pas d'écriture
  directe depuis les contrôleurs.
- Rapports = agrégats/vues, sans dupliquer la logique métier.
- **`nom`/`libellé` mis en forme automatiquement à la casse "phrase"** (première lettre
  en majuscule, reste en minuscule — ex. `"VIS À BOIS"` → `"Vis à bois"`) sur tous les
  référentiels simples : `Categorie`, `Produit` (`nom` et `libelle_distinctif`),
  `Fournisseur`, `Client`, `MotifMouvement`, `Caisse`, `Magasin`, `Taxe`, `TypeClient`,
  `Unite`, `MoyenPaiement`. Via un mutator d'attribut Eloquent partagé
  (`App\Models\Concerns\MetEnFormePhrase`), donc appliqué uniformément à la création et
  à la modification, quel que soit le point d'entrée (formulaire, tinker, seeder) —
  jamais une transformation dupliquée par contrôleur. Volontairement absent des noms de
  personnes (`Utilisateur`, `Role`) où une casse imposée serait déplacée.

---

## Hors périmètre (ne pas implémenter dans le MVP)

- Hors-ligne, base locale, synchronisation.
- Facture normalisée FNE — mais **structurer la vente** pour la brancher plus tard.
- Fidélité, promotions avancées, paie.
- **Comptabilité générale** (plan comptable, journal général, bilan, liasse fiscale) —
  seul un périmètre de **trésorerie** est couvert (Caisse Générale, comptes bancaires/
  autres, virements internes, voir « Trésorerie »), pas une comptabilité complète.
- **Taxes côté vente** — aucun taux, aucune ligne taxe sur le ticket (les taxes existent
  côté achat, voir « Achat à crédit et comptes fournisseurs »).
- **Annulation ou modification d'un retour** — un retour, une fois enregistré, est
  immuable comme un règlement ou un mouvement de stock (règle 18) ; toute correction se
  fait par une nouvelle vente/un nouvel achat, jamais par une modification a posteriori
  (voir « Retours »).
- **Remboursement en espèces sur un retour** — un retour crédite toujours un avoir sur
  le compte client/fournisseur, jamais un mouvement de tiroir (règle 18).
- **Limite de crédit fournisseur** — pas de blocage, contrairement au client.
- **Transformation partielle d'un devis** — un devis se transforme en bloc, pas ligne
  par ligne.

---

## Décisions actées (voir CAHIER_DES_CHARGES §8 pour le détail)

Toutes les hypothèses initiales sont tranchées : prix des unités de vente = prix total ;
casse = mouvement dédié ; remises cumulables (ligne puis sous-total) ; libellé UI
« Unité de vente » ; prix pièce = champ direct sur le produit (unité de base générique,
pas forcément une pièce physique) ; vente en attente reprise au prix courant ; transfert
de stock inter-magasin simple (sortie + entrée, sans valorisation) ; achat = entrée de
stock directe à la validation (pas de « Réception » séparée), coût suivi via **CMP**
(recalculé à chaque achat, figé sur chaque ligne de vente pour la marge) ; **vente à
crédit** sur compte client, solde dérivé d'écritures immuables, limite de crédit
optionnelle bloquante, règlement encaissé en session de caisse ; **devis** hors caisse à
montants indicatifs, transformable en vente (totalité, prix courants) pendant sa durée
de validité ; taxes vente exclues du MVP (voir « Hors périmètre » ci-dessus).

Extension post-MVP (retours utilisateur) : **dépôts** ajoutés au référentiel magasin
(champ `type`, sans caisse) ; **taxes** introduites côté achat uniquement (prix d'achat
HT, TTC dérivé par ligne, jamais côté vente) ; **destination par ligne d'achat**
(magasin ou dépôt, une commande peut livrer plusieurs sites) ; **compte fournisseur**
symétrique du compte client (solde dérivé, règlement encaissé à la validation de l'achat
ou ultérieurement) mais **volontairement hors caisse pour la part non-espèces** —
aucune session requise, aucun impact sur le tiroir ; **types de client** en référentiel
libre, sans logique métier attachée ; **menu horizontal** en remplacement de la sidebar ;
**retours client et fournisseur** ajoutés (initialement hors périmètre MVP), sur le même
principe avoir crédité/ligne par ligne/immuable que le reste des écritures de compte,
également hors caisse comme le règlement fournisseur ; **codes client/fournisseur**
ajoutés (identifiant type SKU, saisi ou généré) ; **bug corrigé** : l'annulation d'une
vente/d'un achat à crédit reverse désormais la dette posée à la validation (nouveaux
types d'écriture `annulation_vente`/`annulation_achat`), qui restait auparavant gonflée
indéfiniment ; **mouvements de caisse manuels** (entrée/sortie) ajoutés pour les
caissiers.

Nouvelle extension (trésorerie) : une **Caisse Générale** (compte de trésorerie
permanent, solde dérivé d'écritures immuables, sans session ni caissier assigné — voir
« Trésorerie ») remplace désormais les caisses de caissier comme source/destination de
la part espèces d'un règlement fournisseur et d'un remboursement d'avoir (règle 17/20
amendées en conséquence : ces trois flux n'exigent plus aucune session de caisse
ouverte, contrairement à avant) ; elle reçoit aussi automatiquement le montant compté de
chaque session de caissier clôturée (règle 21). Des **Comptes** (banque/autre)
s'alimentent par virement depuis la Caisse Générale, sans aucun lien avec les moyens de
paiement de la vente. Nouvel écran **Trésorerie** (`tresorerie.voir`/`tresorerie.gerer`,
Gérant/Superadmin uniquement, entrée dédiée du menu) : répertoire listant
Caisse Générale + Comptes + **toutes** les caisses de vente (pas seulement celles
ouvertes), chacune menant à son propre rapport historique ; et un rapport dédié
(`rapports.tresorerie`) séparé de `rapports.mouvements-caisse`. L'ancien onglet « Mes
caisses » (écran séparé pour saisir un mouvement de tiroir) a été retiré : redondant
avec `sessions.show` qui affichait déjà l'essentiel des mêmes informations pour une
session de caissier, le formulaire entrée/sortie et le solde théorique temps réel ont
été fusionnés directement dans `/sessions/{session}` (voir « Mouvements de caisse »).

Nouvelle extension (réceptions échelonnées côté achat) : **remplace** la décision MVP
initiale « achat = entrée de stock directe à la validation, pas de Réception séparée »
(première ligne de la première décision actée ci-dessus, laissée telle quelle comme
trace historique). `CommandeAchat` (« bon de commande » à l'écran) ne mouvemente plus
rien à la validation ; seule sa réception — une ou plusieurs fois, chacune une
`ReceptionAchat` immuable (« bon d'achat » à l'écran) — impacte réellement stock, CMP
et dette fournisseur, au prix et à la destination **réellement constatés** (peuvent
différer de l'indicatif/du plan de la ligne de commande, voir « Achat à crédit et
comptes fournisseurs »). Aucune donnée/mouvement historique touché : un bon de commande
déjà validé avant cette extension se comporte à l'identique (`totalTtcReel()`/
`resteDu()` retombent naturellement sur l'ancien calcul quand aucune réception
n'existe), la distinction se fait uniquement par présence ou non de `ReceptionAchat`
liées, sans flag stocké. Pour un achat déjà effectué (achat comptant chez le
fournisseur, sans document envoyé au préalable), l'écran de création propose un
raccourci « Enregistrer et réceptionner immédiatement » qui enchaîne création,
validation et réception complète en une seule action.

Nouvelle extension (360inmystore, application tous secteurs) : le projet, né pour une
quincaillerie (« G-Stock Quincaillerie »), devient **360inmystore**, utilisable par
tout type de commerce — nom remplacé partout (scripts, coquille desktop, docs, valeur
par défaut de `Parametre.nom`), aucun secteur supposé dans les libellés/exemples
(voir « Vue d'ensemble »). **Refonte de l'interface** : la décision « menu horizontal
en remplacement de la sidebar » ci-dessus est **annulée** — retour à une **sidebar**,
réductible en miniature sur desktop et coulissante sur mobile, avec un groupe
« Achats » (Fournisseurs, Bons de commande) séparé de « Stock » ; palette neutre à
primaire bleue en remplacement de l'orange chantier, police fine (Inter 14 px) — voir
« Interface ». Seule la présentation change : routes, permissions et règles métier
sont inchangées. Le pont desktop exposé aux vues s'appelle désormais
`window.inmystore` (anciennement `window.gstock`) : vues et `desktop/preload.js`
doivent toujours évoluer ensemble. `QuincaillerieTestSeeder` reste un simple jeu de
données de démonstration (environnement local uniquement), pas une hypothèse produit.

Nouvelle extension (téléphone, organisation des branches) : **téléphone** géré par pays
(sélecteur des pays d'Afrique, +225 par défaut, enregistrement au format
international — voir « Téléphone »). **Deux branches, deux produits** : `mono_client`
est la version installée (un commerce par déploiement, hébergement mutualisé ou VPS,
personnalisable client par client) ; `multi-tenant` est la plateforme SaaS, construite
PAR-DESSUS `mono_client`. Toute correction du métier (vente, stock, caisse, comptes)
se fait dans `mono_client`, puis `mono_client` est fusionnée dans `multi-tenant` —
jamais l'inverse : aucun code de plateforme ne doit arriver dans la version installée.
**Déploiement** : un push ne met plus rien en production ; le déploiement se lance à
la main depuis GitHub (voir `.github/workflows/deploy.yml`).

Nouvelle extension (plateforme SaaS, branche `multi-tenant` uniquement) : une base
par entreprise et un sous-domaine chacun ; site vitrine avec tarifs, inscription en
libre-service protégée contre les robots, arrivée directe dans l'espace sur le choix
de la formule, essai gratuit (formule à 0 F, une seule fois), **paiement en ligne
simulé** (aucun prestataire pour l'instant) ; espace de l'éditeur (tableau de bord,
entreprises, paiements, formules, activation manuelle, accès de maintenance) — voir
« Plateforme SaaS ». L'idée initiale d'un interrupteur mono/multi dans un même code a
été abandonnée au profit de deux branches. L'e-mail n'est volontairement pas demandé
à l'inscription : le téléphone est l'identifiant de contact d'une entreprise.

Nouvelle extension (dépenses) : les charges du commerce (loyer, salaires, factures…)
n'étaient suivies nulle part — une sortie de caisse en portait bien le motif, mais rien
ne les totalisait ni ne les rapprochait des ventes. Décision : **pas de second
mécanisme de sortie d'argent**, c'est le motif qui est marqué « dépense »
(`MotifMouvement.est_depense`) ; toute sortie sous un tel motif crée une `Depense`
dans la même transaction (voir « Dépenses »). Un caissier n'en enregistre que sur
permission (`depense.creer`) ; pas de dépense à crédit ; correction par annulation
(mouvement inverse), jamais par modification. Le tableau de bord du gérant comme celui
du propriétaire affichent un **résultat simplifié** (marge brute − dépenses − pertes de
stock), sans prétendre à une comptabilité (toujours hors périmètre).
