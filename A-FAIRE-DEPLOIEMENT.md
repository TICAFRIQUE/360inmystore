# À faire — actions de déploiement en attente

Journal des actions à exécuter sur le poste serveur après une session de
travail, en plus du déploiement standard (voir `GUIDE-MISES-A-JOUR.md` pour
le détail de chaque commande — ce fichier-ci n'est qu'une liste concrète et
datée de ce qui a changé et de ce qu'il reste à faire).

Une entrée par session, la plus récente en haut. Cocher une fois fait, ne
pas supprimer les entrées cochées (historique).

---

## 2026-10-07 — Plateforme SaaS (branche `multi-tenant`)

Rien à faire sur une installation existante : la plateforme est un nouveau
déploiement, sur un VPS (voir `PLATEFORME.md`, § 5 et § 6). Le déploiement
automatisé depuis GitHub refuse cette branche.

- [ ] Hébergement : sous-domaines génériques, droit de créer des bases,
  processus en ligne de commande.
- [ ] Mise en place : `migrate`, `db:seed --class=ReferentielSeeder`,
  `app:creer-superadmin`, puis création des formules depuis l'espace éditeur.
- [ ] À chaque mise à jour : `php artisan migrate --force` puis
  `php artisan entreprises:executer -- migrate --force`.
- [ ] Avant toute mise en vente : brancher un vrai prestataire de paiement —
  le paiement en ligne est **simulé**.

## 2026-10-07 — 360inmystore : renommage, sidebar, exports, téléphone

Branche `mono_client` (version installée). **Un push ne déploie plus rien** : la
mise en production se lance à la main depuis GitHub (Actions → « Tests et
déploiement » → « Run workflow », branche `mono_client`).

- [ ] **Nouvelle dépendance PHP** (`giggsey/libphonenumber-for-php-lite`,
  numéros de téléphone) et nouveaux assets (sidebar, police Inter) :
  ```
  composer install --no-dev --optimize-autoloader
  php artisan migrate --force
  npm install
  npm run build
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  ```
- [ ] **Migrations** : le nom d'application resté sur l'ancienne valeur par
  défaut (« Gérer Mon Stock ») devient « 360inmystore » ; nouvelle colonne
  `parametres.pays` (Côte d'Ivoire par défaut) ; les numéros de téléphone
  déjà saisis et valides passent au format international (`+225…`) — un
  numéro non reconnu est laissé tel quel.
- [ ] **Vérifier le pays du commerce** dans Paramètres (indicatif proposé par
  défaut dans tous les champs téléphone).
- [ ] **Coquille desktop** : c'est une nouvelle application (« 360inmystore »,
  nouvel identifiant) — elle ne met pas à jour une ancienne installation
  « G-Stock Quincaillerie », à désinstaller à la main sur chaque poste.
- [ ] **Tests** : ils tournent désormais sur MySQL — créer une fois la base
  `360inmystore_test` sur le poste de développement.

## 2026-09-15 (suite 12) — sauvegarde vers un dossier + restauration de la base

- [ ] **Nouveau : dossier de sauvegarde configurable** (`Paramètres`, carte
  "Sauvegarde de la base de données") — un champ texte permet de renseigner un
  dossier existant sur l'ordinateur serveur (ex. un disque externe ou un
  dossier partagé) ; si renseigné, chaque sauvegarde y est désormais écrite
  **directement** (nom `sauvegarde-AAAA-MM-JJ-HHMMSS.sql`, déjà le cas avant
  ce changement) au lieu d'être proposée en téléchargement navigateur. Laisser
  le champ vide conserve l'ancien comportement (téléchargement).
  (`ParametreController::mettreAJourDossierSauvegarde()`/`executerMysqldump()`).
  Nécessite :
  ```
  php artisan migrate --force
  ```
  Une migration ajoute `parametres.dossier_sauvegarde` (nullable, vide par
  défaut — aucune donnée existante touchée, comportement identique à avant
  tant qu'il n'est pas renseigné).
- [ ] **Nouveau : restauration de la base de données depuis l'application**
  (même carte, section "Restauration") — importe un fichier `.sql` et
  remplace intégralement le contenu de la base actuelle
  (`ParametreController::restaurer()`, exécute `mysql` avec le fichier importé
  en entrée standard, comme `mysqldump` pour l'export). **Sauvegarde de
  sécurité automatique** de l'état actuel juste avant (même mécanisme que la
  sauvegarde manuelle, dans le dossier configuré ci-dessus ou un fichier
  temporaire sinon) — si la restauration échoue en cours de route ou que le
  fichier importé est mauvais, cette sauvegarde de sécurité reste disponible
  pour revenir en arrière manuellement. Si la sauvegarde de sécurité
  elle-même échoue, la restauration est annulée avant de toucher à quoi que
  ce soit. Après une restauration réussie : tous les caches applicatifs
  (Paramètres, Devise, permissions Spatie, cache général) sont vidés, l'action
  est tracée dans le journal d'activité. Confirmation obligatoire avant
  d'exécuter (modal partagé `#confirmActionModal`), réservé à
  `parametre.gerer` (même permission que le reste de l'écran Paramètres,
  aucune nouvelle permission).
  - [ ] **Correctif associé : `mysqldump`/`mysql` introuvables sur une
    installation cliente Laragon** — le process lancé par le serveur web ne
    trouve pas ces exécutables via le PATH global Windows (Laragon ne l'ajoute
    que dans son propre terminal), d'où le message d'erreur "mysqldump
    introuvable" remonté par le client. Les deux commandes détectent
    désormais automatiquement le dossier Laragon standard
    (`C:\laragon\bin\mysql\*\bin\`) ; à défaut, deux nouvelles variables
    `.env` (`MYSQLDUMP_PATH`/`MYSQL_PATH`) permettent de forcer un chemin
    exact si l'auto-détection ne suffit pas (installation non standard).
  - [ ] **Tester après déploiement (poste serveur)** :
    - Sans dossier configuré : cliquer "Télécharger une sauvegarde" → doit
      toujours proposer un téléchargement, nommé avec la date/heure du jour.
    - Renseigner un dossier existant (ex. `C:\Sauvegardes`) → cliquer
      "Sauvegarder maintenant" → vérifier qu'un fichier `.sql` apparaît
      directement dans ce dossier (pas de téléchargement navigateur).
      Renseigner un dossier inexistant → doit être rejeté avec un message
      clair au moment d'enregistrer le champ.
    - Restaurer un fichier `.sql` valide (idéalement une sauvegarde
      précédente, sur une base de test si possible avant de le faire en
      production) → vérifier le message de confirmation, puis le message de
      succès mentionnant le chemin de la sauvegarde de sécurité créée juste
      avant ; vérifier que les données de l'app reflètent bien le contenu
      importé après rechargement d'une page (ex. `/produits`).
    - Tenter de restaurer sans fichier sélectionné → bloqué côté formulaire
      (champ requis).
    - Si `mysqldump`/`mysql` ne sont pas trouvés automatiquement sur ce poste
      (message "introuvable" persistant) : renseigner `MYSQLDUMP_PATH` et
      `MYSQL_PATH` dans le `.env` avec le chemin complet vers les deux
      exécutables, puis réessayer.

## 2026-09-15 (suite 11) — logo noir et blanc partout, onglet BL retiré, dashboard

- [ ] **Généralisé : le logo est désormais affiché en noir et blanc sur TOUS
  les documents imprimables** (facture/ticket de vente, devis, bon de
  livraison), pas seulement la facture du bon de commande (suite 9
  ci-dessous, seule à l'avoir jusqu'ici). Logique déplacée dans
  `Parametre::logoDataUri()` (un seul endroit, réutilisé par tous les
  contrôleurs de facture — `VenteController`, `DevisController`,
  `BonLivraisonController`, `CommandeAchatController`) au lieu d'être
  dupliquée. Déploiement standard, aucune migration.
  - [ ] **Tester après déploiement** : ouvrir une facture de vente, un devis
    et un bon de livraison ayant un logo personnalisé configuré → vérifier
    qu'il s'affiche en noir et blanc sur les trois (écran, PDF, impression),
    comme c'était déjà le cas sur la facture du bon de commande.
- [ ] **Retiré : l'onglet "Bon de livraison" du menu Vente** (demande
  utilisateur, temporaire) — route/contrôleur/permission `vente.livrer`
  inchangés, uniquement l'entrée de menu masquée (`partials/topnav.blade.php`).
  Déploiement standard, aucune migration.
- [ ] **Changement de comportement : le dashboard superadmin/gérant ne bascule
  plus automatiquement sur le dashboard "caissier"** quand cet utilisateur
  ouvre lui-même une session de caisse — il garde toujours son dashboard
  habituel (CA, stock, créances...), le suivi de sa session restant
  accessible via le menu Vente → Facture/BL. Revient sur un changement
  récent (ce basculement automatique) jugé source de confusion.
  `DashboardController::__invoke()`. Déploiement standard, aucune migration.
  - [ ] **Tester après déploiement** : connecté en gérant/superadmin, ouvrir
    une session de caisse sur une caisse libre → vérifier que le dashboard
    affiché reste bien celui du magasin/consolidé, pas celui du caissier.
    Vérifier qu'un utilisateur ayant le rôle Caissier voit toujours son
    dashboard de session comme avant.

## 2026-09-15 (suite 9) — mise en page de la facture du bon de commande

- [ ] **Ajustements visuels sur `commande-achats/facture.blade.php`** (écran/PDF/
  impression/Excel du bon de commande) :
  - Colonnes du tableau des lignes passées en largeurs explicites (`<colgroup>`,
    absent jusqu'ici) — Désignation élargie à 26 % pour limiter les retours à la
    ligne sur un nom de produit long, Destination portée à 13 % (magasin/dépôt),
    reste réparti entre Unité/Qté/Prix HT/Taxe/Total HT/Total TTC.
  - Police des en-têtes de colonnes (`<th>`) réduite (12px → 10px), le contenu des
    lignes (`<td>`) reste à 12px.
  - Colonne "Reçu" retirée du tableau des lignes, et toute la section "Bons d'achat
    (réceptions)" (détail ligne par ligne de chaque réception + paiements) retirée
    du document — redondant avec le récapitulatif "Total TTC réel"/"Écart" déjà
    affiché juste au-dessus, et bien plus détaillé que ce qu'un bon de commande
    envoyé au fournisseur a besoin de montrer. Cette section restait de toute façon
    disponible sur la fiche interne (`commande-achats/show.blade.php`, non touchée).
    `CommandeAchatController::chargerDonneesFacture()` allégé en conséquence
    (`dejaRecuParLigne` retiré, eager loading des réceptions réduit à
    `receptions.lignes.taxe` — suffisant pour le calcul du montant réel, plus besoin
    de `produit`/`magasin`/`paiements`/`auteur`).
  - **Logo affiché en noir et blanc par défaut** (écran, impression et PDF — pas
    seulement à l'impression comme le texte déjà en place) : dompdf ne supportant
    pas le filtre CSS `grayscale()`, la désaturation se fait côté serveur via GD
    (`CommandeAchatController::logoEnNoirEtBlanc()`, même driver image que
    medialibrary) au moment de construire le data URI — le logo original stocké
    n'est jamais modifié, uniquement cette copie encodée pour ce document. Repli
    silencieux sur l'image couleur d'origine si GD ne sait pas la décoder.
  Déploiement standard, aucune migration, aucun changement JS.
  - [ ] **Tester après déploiement** : ouvrir la facture d'un bon de commande ayant
    un nom de produit long → vérifier qu'il ne revient plus à la ligne. Ouvrir un bon
    de commande déjà réceptionné → vérifier l'absence de la colonne "Reçu" et de la
    section "Bons d'achat (réceptions)" (le récapitulatif "Total TTC réel"/"Écart"
    reste, lui, affiché). Vérifier que le logo s'affiche en noir et blanc sur l'écran,
    le PDF et l'impression ; vérifier qu'il reste en couleur ailleurs (ticket de
    vente, devis — non touchés par ce changement).

## 2026-09-15 (suite 8) — rapport de caisse enrichi (mêmes détails que la session)

- [ ] **Amélioration : le "Rapport de caisse" (`sessions/rapport.blade.php`, accessible
  depuis une session clôturée) affiche désormais les mêmes détails que le tableau de
  bord de session** (`sessions/show.blade.php`) :
  - Liste des ventes enrichie (Montant dû, Déjà réglé, Avoir appliqué, Reste à payer,
    Livraison en notation "livré/total", Retours) — au lieu des 3 seules colonnes
    Numéro/Date/Total net d'avant. Colonne "Statut" (toujours "Validée") volontairement
    omise, sans valeur ajoutée sur ce rapport.
  - Nouveau tableau "Historique des mouvements de caisse" (entrée/sortie/remboursement
    d'avoir, un par ligne), absent auparavant du rapport (seuls les totaux agrégés
    apparaissaient).
  - Le bilan (Fond de caisse, ventes, moyens de paiement, données de clôture) inclut
    désormais **Retour/avoir** (montant + nombre de retours entre parenthèses),
    manquant jusqu'ici. Quelques libellés renommés pour rester cohérents avec
    `sessions/show.blade.php`/`cloturer.blade.php` : "Nombre de ventes" → "Total
    ventes", "Total net" → "Montant vente", "Théorique"/"Entrées de caisse"/"Sorties
    de caisse" → "Total en caisse"/"Entrée"/"Sortie". **Même calcul qu'avant** (aucune
    donnée chiffrée ne change, seuls les libellés et l'ajout de Retour/avoir) —
    `SessionCaisseController::bilanRapportSession()` reste l'unique source, réutilisée
    telle quelle par l'écran, le PDF et l'Excel (jamais un calcul dupliqué).
  - Présentation de l'écran rapprochée de celle du PDF exporté (`exports/liste.blade.php`)
    : bilan affiché comme un tableau bordé aligné à droite plutôt que deux cartes à
    bordures colorées, ligne "Total en caisse" mise en évidence (fond vert).
  Déploiement standard, aucune migration.
  - [ ] **Tester après déploiement** : ouvrir le rapport d'une session clôturée ayant
    eu au moins un retour et un mouvement de caisse manuel → vérifier que la liste des
    ventes affiche toutes les colonnes, que l'historique des mouvements apparaît, que
    le bilan liste "Retour/avoir" avec le bon montant, et que le PDF/Excel exportés
    reflètent les mêmes libellés/chiffres que l'écran.
- [ ] **Correctif : le PDF du rapport de caisse n'affichait que 3 colonnes (Numéro,
  Date et heure, Total net)**, incohérent avec la liste enrichie de l'écran ci-dessus —
  le PDF affiche désormais les mêmes 8 colonnes (`SessionCaisseController::lignesVentesRapport()`,
  partagée par le PDF et l'Excel pour ne jamais dupliquer ce calcul ; colonne "Statut",
  toujours "Validée", volontairement omise — sans valeur ajoutée). En-têtes **abrégés**
  côté PDF uniquement (N°, Date/heure, Montant dû, Réglé, Avoir, Reste, Livraison,
  Retours) — l'Excel garde des en-têtes complets, une feuille de calcul n'a pas la
  contrainte de largeur d'une page imprimée. **Taille de police du tableau réduite**
  (`exports/liste.blade.php`, gabarit PDF partagé par tous les rapports de l'app —
  ventes, marge, stock, écarts de caisse, mouvements de caisse, trésorerie : 11px → 9px,
  padding resserré) pour que 8 colonnes tiennent lisiblement sur une page A4. Déploiement
  standard, aucune migration.
  - [ ] **Tester après déploiement** : télécharger le PDF du rapport d'une session avec
    des ventes variées (à crédit, avec avoir, avec retour, avec livraison partielle) →
    vérifier que les 8 colonnes s'affichent avec les bonnes valeurs, tiennent sur la
    largeur de la page sans débordement, et restent lisibles. Vérifier qu'un autre
    rapport PDF (ex. `rapports/ventes/pdf`) reste lisible avec la police réduite.

## 2026-09-15 (suite 7) — prix d'achat indicatif du bon de commande passé en décimal

- [ ] **Changement de règle : le prix d'achat indicatif d'une ligne de bon de commande
  (`prix_achat`) n'est plus arrondi à l'entier XOF** — seule exception à la règle
  "argent en entier" (voir CLAUDE.md, « Argent et arrondis ») : la saisie, les totaux
  HT/TTC de ligne (`montantHt()`/`montantTtc()`) et les totaux du document
  (`totalHt()`/`totalTtc()`/`totalTaxes()`) acceptent désormais jusqu'à 2 décimales, sans
  arrondi (`Arrondi::prixAchat()`, `App\helpers.php::montantAchat()` pour l'affichage).
  Le prix **réellement facturé**, capturé à la réception (`prix_achat_reel`), reste lui
  **entier** comme le reste de l'application (CMP, stock, vente, règlement fournisseur)
  — aucun changement de ce côté. Nécessite :
  ```
  php artisan migrate --force
  npm run build
  ```
  Une migration convertit `ligne_commande_achats.prix_achat` de `INT UNSIGNED` à
  `DECIMAL(14,2)` (`ALTER TABLE ... MODIFY COLUMN`, pas de `->change()` — doctrine/dbal
  non installé, même approche que `2026_09_02_130000_convertir_quantites_en_decimal.php`)
  — aucune donnée existante perdue, un prix déjà enregistré (toujours entier) reste
  identique. `npm run build` nécessaire : le pas (`step`) et l'arrondi du champ prix
  d'achat sur `commande-achats/create.blade.php` sont gérés en JS
  (`resources/js/app.js` n'est pas concerné, tout est inline dans la vue, mais le build
  Vite recompile quand même les assets partagés — garder le réflexe).
  - [ ] **Tester après déploiement** : créer un bon de commande avec un prix d'achat
    décimal (ex. 1250,50) → vérifier que le Total HT/TTC de la ligne et les totaux du
    document l'affichent avec 2 décimales, sans arrondi, aussi bien à la création que sur
    la fiche/la facture PDF/Excel du bon de commande. Utiliser « Enregistrer et
    réceptionner immédiatement » sur une ligne à prix décimal → vérifier que le prix
    réellement reçu (`prix_achat_reel`, CMP, stock) est bien un entier (arrondi). Vérifier
    qu'un bon de commande à prix entiers (cas courant) se comporte exactement comme avant.
- [ ] **Correctif urgent (même entrée) : la page de création était cassée** — un
  commentaire JS ajouté dans `commande-achats/create.blade.php` contenait des guillemets
  droits (`"Achat à crédit et comptes fournisseurs"`) à l'intérieur de l'attribut HTML
  `x-data="..."` lui-même délimité par des guillemets doubles : le navigateur refermait
  l'attribut prématurément à ce guillemet, invalidant tout le reste de l'objet Alpine (plus
  aucun clic ne fonctionnait sur la page, y compris le sélecteur "La marchandise est-elle
  déjà chez vous ?"). **Même classe de bug** que le correctif du 2026-09-11 sur
  `ventes/ticket.blade.php` (voir plus bas dans ce fichier) — remplacé par des guillemets
  français (« »). Déploiement standard, aucune migration supplémentaire.
  - [ ] **Tester après déploiement** : ouvrir `/commande-achats/create` → vérifier que les
    deux cartes "Non — Bon de commande" / "Oui — Achat direct" réagissent bien au clic
    (surbrillance qui change), et qu'ajouter/retirer une ligne fonctionne normalement.

## 2026-09-15 (suite 6) — largeurs de colonnes du document facture corrigées (colgroup)

- [ ] **Correctif : "Désignation" élargie (32 %) ne suffisait pas** — sans largeur
  explicite sur les autres colonnes, dompdf répartissait le reste à parts égales
  entre Unité/Qté/P.u/Remise/Total, leur donnant une largeur bien supérieure à leur
  contenu réel (ex. "m", "3", "—") et un nom de produit un peu long continuait de
  revenir à la ligne (constaté sur le PDF réel d'un bon de livraison). Remplacé par
  un `<colgroup>` à largeurs explicites sur les deux gabarits concernés
  (`ventes/facture.blade.php`, bloc imprimable de `ventes/ticket.blade.php`) :
  Désignation 38 % (34 % si une colonne Taxe est affichée), Unité/Qté/Remise resserrées
  (7-10 % chacune), P.u et Total gardent une largeur confortable pour un montant.
  Vérifié en régénérant le PDF réel d'un bon de livraison existant (dompdf respecte
  bien le colgroup) : le nom de produit tient désormais sur une seule ligne. Déploiement
  standard, aucune migration.
  - [ ] **Tester après déploiement** : ouvrir/télécharger la facture PDF d'une vente
    avec un nom de produit un peu long → vérifier qu'il ne revient plus à la ligne, et
    que les colonnes Unité/Qté/Remise sont visiblement plus étroites qu'avant. Vérifier
    aussi une vente avec au moins une ligne taxée (colonne Taxe affichée) → largeurs
    toujours cohérentes, rien ne déborde.

## 2026-09-15 (suite 5) — écran de vente : cases du panier resserrées, bouton retiré

- [ ] **Ajustement visuel du panier** (`ventes/create.blade.php`) : les `min-width` des
  champs de saisie par ligne étaient trop larges et de taille quasi identique
  (sélecteur de lieu 170px, unité 150px, remise 150px) — resserrés et hiérarchisés
  (lieu 150px > unité 130px > remise 110px, quantité inchangée, déjà la plus compacte)
  pour que Désignation reste visuellement la zone la plus large du panier, suivie
  d'Unité puis Qté puis Remise. Déploiement standard, aucune migration.
- [ ] **Retiré : le bouton "Ventes en attente" en haut de l'écran de création de
  facture** (`ventes/create.blade.php`) — redondant avec le bouton identique déjà
  présent sur `sessions/{session}` (`sessions/show.blade.php`) et le lien du dashboard
  caissier, qui restent les deux points d'accès à cette liste. `VenteController::formulaire()`
  nettoyé de la variable `venteEnAttentesCount` devenue inutilisée. Déploiement
  standard, aucune migration.
  - [ ] **Tester après déploiement** : ouvrir l'écran de création de facture → vérifier
    l'absence du bouton "Ventes en attente" en haut (le panier reste utilisable
    normalement, "Mettre en attente" en bas du panier inchangé) ; vérifier que le
    bouton reste bien accessible depuis `/sessions/{id}` et le dashboard caissier.

## 2026-09-15 (suite 4) — mise en page de la facture (PDF/impression/Excel)

- [ ] **Ajustements visuels sur le document "facture"** (`ventes/facture.blade.php` —
  vue web/PDF/impression partagée — et le bloc imprimable équivalent de
  `ventes/ticket.blade.php`, `#factureImprimable`) :
  - Colonne "Désignation" élargie (32 % de la largeur du tableau) pour limiter les
    retours à la ligne sur un nom de produit long.
  - En-tête "Prix unitaire" raccourci en "P.u".
  - Colonne "Livré" retirée du tableau des lignes (redondante avec le bandeau de
    statut de livraison déjà affiché en haut du document) — reste inchangée sur
    l'écran interne `sessions/{session}` et le détail non imprimé de la facture
    (`ventes/ticket.blade.php`, tableau `d-print-none`), qui gardent leur colonne de
    suivi de livraison à usage interne.
  - Même trois changements appliqués à l'export Excel (`VenteController::excel()`) :
    en-tête "P.u", colonne "Livré" retirée, code mort nettoyé (`$dejaLivreParLigne`/
    `$livraisonEngagee` qui ne servaient plus qu'à cette colonne).
  - **Nouveau : mention "Facture à suivre"** ajoutée en bas du document (facture web/
    PDF/impression et export Excel) quand la vente est de type **bon de livraison**
    (`Vente::estBonLivraison()`, voir la fonctionnalité Facture/BL du 2026-09-15
    précédent) — indique au client qu'une facture en bonne et due forme suivra
    séparément. Absente pour une facture classique.
  Déploiement standard, aucune migration, plus :
  ```
  npm run build
  ```
  (aucun changement JS pour cette entrée, mais garder le réflexe si pas déjà fait
  depuis les entrées précédentes de cette même journée).
  - [ ] **Tester après déploiement** : ouvrir une facture avec un nom de produit long
    → vérifier que "Désignation" ne provoque plus de retour à la ligne excessif ;
    vérifier "P.u" à la place de "Prix unitaire" et l'absence de colonne "Livré" sur
    le document facture (écran, PDF, impression, Excel) — le tableau interne de
    `sessions/{id}` et le tableau non imprimé du détail de facture gardent, eux, leur
    colonne de livraison. Ouvrir une vente enregistrée en "Bon de livraison" → vérifier
    la mention "Facture à suivre" en bas de la facture (PDF/impression/Excel) ; ouvrir
    une facture classique → vérifier son absence.

## 2026-09-15 (suite 3) — prix personnalisé : majoration possible, pas seulement remise

- [ ] **Changement : le "prix personnalisé" à la vente peut désormais aussi bien
  majorer que réduire le prix catalogue d'une ligne** — jusqu'ici plafonné au prix
  catalogue (impossible de taper plus cher, seulement moins cher), le champ accepte
  maintenant n'importe quel prix positif (ex. catalogue à 500, saisir 400 comme 600).
  Toujours résolu en interne comme une remise "montant" classique
  (`VenteService::resoudreLignes()`), simplement autorisée à devenir négative pour CE
  cas précis (`ValideRemises::remiseValeurMin()`) — une remise classique (sélectionnée
  via "Remise (F)"/"Remise (%)") reste, elle, toujours interdite en négatif, aucun
  changement pour elle. Le ticket/la facture n'affichent toujours aucune "remise" sur
  ces lignes (juste le prix résultant), comme avant. Nécessite :
  ```
  php artisan migrate --force
  npm run build
  ```
  Une migration convertit `ligne_ventes.remise_ligne_valeur`/`remise_ligne_montant` de
  `INT UNSIGNED` à `INT` signé (`ALTER TABLE ... MODIFY COLUMN`, pas de `->change()` —
  doctrine/dbal non installé, même approche que
  `2026_09_02_130000_convertir_quantites_en_decimal.php`) — aucune donnée existante
  perdue, une remise déjà enregistrée (toujours positive) reste identique. `npm run
  build` nécessaire : le plafond au prix catalogue était appliqué en JS
  (`resources/js/app.js`, `prixPersonnaliseClamp()`), retiré. Aucune nouvelle
  permission (réutilise `vente.remise`, déjà existante).
  - [ ] **Tester après déploiement** : sur une ligne, choisir "Prix personnalisé" et
    taper un prix **supérieur** au catalogue (ex. catalogue 500 → saisir 600) →
    vérifier que le total de la ligne augmente bien en conséquence (600 × quantité),
    sans afficher de "remise" sur le ticket/la facture ; vérifier qu'un prix
    **inférieur** (ex. 400) fonctionne toujours comme avant. Vérifier qu'une remise
    classique ("Remise (F)"/"Remise (%)") reste bloquée à une valeur positive côté
    formulaire.

## 2026-09-15 (suite 2) — KPI de l'écran de clôture de session enrichis

- [ ] **Amélioration : l'écran de clôture (`sessions/cloturer.blade.php`) affiche
  désormais la même "story" financière que la page de session** — Fond de caisse,
  Total ventes (nombre), Montant vente (tous moyens de paiement confondus), Retour/avoir
  (montant + badge nombre), Entrée, Sortie, puis Total en caisse (fond vert plein, comme
  sur `sessions/show.blade.php`) — au lieu des deux seules cartes "Fond de caisse"/
  "Total de tous" d'avant. **Le montant comparé au comptage physique du tiroir reste
  uniquement les espèces** (règle 10, non négociable, non touchée) : "Total en caisse"
  et le "Théorique" affiché juste en dessous (utilisé pour le calcul de l'écart à la
  validation) restent le même chiffre espèces-uniquement qu'avant — seul "Montant vente"
  (tous moyens confondus) est une donnée nouvelle, purement informative. Déploiement
  standard, aucune migration.
  - [ ] **Tester après déploiement** : ouvrir l'écran de clôture d'une session avec au
    moins un retour et une entrée/sortie de caisse → vérifier que les 7 KPI s'affichent
    avec les bons montants, que "Total en caisse" correspond toujours exactement au
    "Théorique" du bloc de comparaison en bas de page (même chiffre, espèces uniquement).

## 2026-09-15 (suite) — vente enregistrée directement en bon de livraison (Facture/BL)

- [ ] **Nouveau : une vente peut désormais être enregistrée directement comme "Bon de
  livraison" au lieu de "Facture"**, via un sélecteur sur l'écran de vente (visible
  uniquement avec la nouvelle permission `vente.bon_livraison`) — les deux jouent le même
  rôle (stock, session, paiement, crédit identiques), seul le type diffère : une vente en
  bon de livraison est automatiquement et entièrement livrée dès sa création (un
  `BonLivraison` complet généré dans la même transaction), sans passer par le circuit
  manuel de livraison partielle existant (carte "Livrer des articles"). Elle n'apparaît
  donc jamais dans `/bons-livraison` (déjà entièrement livrée). Le menu "Vente → Facture"
  est renommé "Vente → Facture/BL" (même écran, mêmes règles pour les deux types).
  Nécessite :
  ```
  php artisan migrate --force
  php artisan db:seed --class=RolePermissionSeeder --force
  ```
  Une migration ajoute `ventes.type` (`facture` par défaut, `bon_livraison` sinon) —
  aucune donnée existante touchée (tout l'historique reste `facture`). Le seeder
  enregistre la nouvelle permission `vente.bon_livraison` — comme pour toute nouvelle
  permission, elle n'est **cochée pour aucun rôle existant** automatiquement (y compris
  Caissier, volontairement laissée à accorder à la carte pour éviter qu'un caissier
  émette des BL sans facturer par erreur/abus) : l'accorder manuellement sur `/roles`
  au(x) rôle(s) qui doivent pouvoir émettre un bon de livraison directement.
  - [ ] **Tester après déploiement** : accorder `vente.bon_livraison` à un rôle de test →
    sur l'écran de vente, vérifier que le sélecteur "Facture / Bon de livraison" apparaît
    (invisible sans la permission) ; finaliser une vente en "Bon de livraison" → vérifier
    que le ticket affiche "BON DE LIVRAISON" en titre, que la colonne "Type" de la liste
    des ventes de la session l'indique, qu'elle apparaît "livrée" à 100 % immédiatement
    (colonne Livraison), et qu'elle n'apparaît PAS dans `/bons-livraison`. Vérifier qu'une
    vente "Facture" classique (par défaut, y compris sans la permission) reste identique
    à avant.
- [ ] **Ajustement : le sélecteur "Facture / Bon de livraison" déplacé en haut de la
  colonne gauche**, avant le champ de recherche produit (`ventes/create.blade.php`) —
  visible en premier, avant de commencer à remplir le panier, au lieu d'être noyé plus
  bas près du bouton "Finaliser la vente". Déploiement standard, aucune migration.
- [ ] **Changement : le numéro d'un bon de livraison porte désormais le préfixe "BL-"**
  (ex. `BL-M1-C01-000033` au lieu de `M1-C01-000033`) — même compteur de séquence que
  les factures de cette caisse, seul le préfixe distingue visuellement le type au premier
  coup d'œil (ticket, liste des ventes, rapports). Une facture classique n'a toujours
  aucun préfixe. Déploiement standard, aucune migration (aucun numéro déjà en base
  n'est renuméroté).
  - [ ] **Tester après déploiement** : finaliser une vente en "Bon de livraison" →
    vérifier que le numéro commence bien par "BL-" partout où il s'affiche (ticket,
    liste des ventes de la session, ticket PDF) ; vérifier qu'une facture classique
    garde un numéro sans préfixe.

## 2026-09-15 — retour/remboursement client : session obligatoire + même jour + tiroir

- [ ] **Amélioration visuelle : "Total en caisse" ressort maintenant sur fond vert
  plein, texte blanc** (nouveau prop `solid` sur `x-components/kpi-card.blade.php`,
  rétrocompatible — tous les autres KPI de l'app restent inchangés). But : distinguer
  visuellement le chiffre le plus important de la session parmi les autres KPI.
  Déploiement standard, aucune migration.
- [ ] **Réorganisation : "Décomposition" et "Répartition par moyen de paiement" sur la
  même ligne** (décomposition à gauche avec des cartes réduites, répartition à droite)
  au lieu d'être empilées — gagne de la place verticale. Déploiement standard, aucune
  migration.
- [ ] **Réorganisation : les 6 KPI principaux du dashboard de session tiennent sur une
  seule ligne** — fond de caisse, nombre de ventes, ventes en attente, puis la story
  financière dans l'ordre : "Total vendu" (brut) → "Retour/avoir" (montant, avec le
  nombre de retours en badge dans le coin de la carte, affiché seulement s'il y en a
  au moins un) → "Total en caisse" (net réel du tiroir). "Retours" a été déplacé hors
  de la section "Décomposition" vers cette ligne. Colonnes flexibles (comme partout
  ailleurs sur cette page), s'adapte à n'importe quel nombre de cartes. Déploiement
  standard, aucune
  migration.
- [ ] **Correctif : la ligne "Espèces" de "Répartition par moyen de paiement" ne
  déduisait jamais les sorties de caisse** (`SessionCaisseController::paiementsParMoyen()`)
  — elle pouvait afficher un montant bien plus élevé que "Total en caisse" juste à
  côté (ex. "Espèces : 600" alors que "Total en caisse" affichait 150), laissant croire
  à tort qu'il restait cet argent dans le tiroir. Corrigé : les sorties (toujours en
  espèces, règle 19) sont maintenant déduites de cette ligne, les autres moyens de
  paiement restent inchangés (jamais concernés par une sortie). Déploiement standard,
  aucune migration.
  - [ ] **Tester après déploiement** : faire une vente en espèces, puis un retour
    remboursé (ou une sortie manuelle) → vérifier que "Espèces" dans la répartition
    diminue en conséquence et reste cohérent avec "Total en caisse".
- [ ] **Simplification : le dashboard de session n'affiche plus qu'un seul chiffre
  "caisse"**, libellé "Total en caisse" — c'est en réalité le solde théorique du tiroir
  (fond + espèces encaissées − sorties, remboursements d'avoir inclus), celui utilisé à
  la clôture. Retirés : le "Chiffre d'affaires" (brut, ne reflétait jamais les retours)
  et l'ancien "Total en caisse" de la décomposition (entrées seules, ne reflétait jamais
  les sorties) — les trois chiffres côte à côte créaient trop de confusion sur ce qu'il
  y a vraiment dans le tiroir. Déploiement standard, aucune migration.
  - [ ] **Tester après déploiement** : ouvrir une session → vérifier qu'un seul KPI
    "Total en caisse" apparaît (plus de "Chiffre d'affaires" ni de doublon dans la
    section "Décomposition") ; faire une vente puis un retour remboursé → vérifier que
    ce chiffre bouge bien en conséquence.
- [ ] **Correctif : le code client (`CLI-000001`) n'était jamais généré via la création
  rapide** (`ClientController::storeRapide()`, bouton "+" du sélecteur client — utilisé
  entre autres sur le formulaire de retour) — contrairement à la création complète
  (`store()`), qui appelle bien `genererCode()`. Le client se créait avec `code` vide.
  Corrigé, réutilise la même méthode. Déploiement standard, aucune migration.
- [ ] **Ajustement : "Rembourser maintenant" est maintenant le premier bouton radio**
  du formulaire de retour (avant "Créer un avoir pour ce client") — cohérent avec le
  fait que c'est désormais l'option par défaut. Déploiement standard, aucune migration.
- [ ] **Revenu sur : le lien "Avoirs clients" est de retour** (menu Vente et bouton du
  dashboard de session, retirés plus tôt dans la même journée) — le choix "Créer un
  avoir" reste possible au moment du retour (pas rendu automatique/obligatoire), donc
  l'écran `/remboursements-avoir` reste un point d'entrée utile pour rembourser plus
  tard un avoir déjà créé. Bouton visible uniquement sur une session **ouverte**
  (un remboursement exige désormais une session, règle 20). Déploiement standard,
  aucune migration.
- [ ] **Amélioration : le remboursement d'avoir généré depuis un retour apparaît
  maintenant distinctement** ("Remboursement avoir" au lieu de "Sortie" générique)
  dans l'historique des mouvements de la page de session (`sessions/show.blade.php`)
  — cohérent avec le même libellé déjà utilisé dans les rapports de caisse. Déploiement
  standard, aucune migration.
- [ ] **Correctif urgent : le modal de retour était cassé** (`ventes/ticket.blade.php`)
  — un commentaire JS contenait des guillemets droits (`"avoir"`) à l'intérieur de
  l'attribut HTML `x-data="..."` lui-même délimité par des guillemets doubles : le
  navigateur refermait l'attribut prématurément à ce guillemet, invalidant tout le
  reste de l'objet Alpine (boutons/calculs du retour ne fonctionnaient plus). Corrigé.
  Déploiement standard, aucune migration — **prioritaire**, affecte tous les retours.
- [ ] **Amélioration : moyen de paiement "Espèces" présélectionné** sur le formulaire de
  retour, en cohérence avec "Rembourser maintenant" coché par défaut — évite une étape
  de saisie pour le cas quasi systématique d'un remboursement immédiat au comptoir.
  Déploiement standard, aucune migration.
- [ ] **Correctif d'affichage : la carte "Répartition par moyen de paiement" débordait
  sur le bloc "Mouvement de caisse" en dessous** (`sessions/show.blade.php`) — `h-100`
  sur la carte, combiné à l'étirement par défaut du `.row` Bootstrap, produisait un
  chevauchement visuel. Retiré `h-100`, ajouté `align-items-start` sur la ligne qui
  contient Décomposition + Répartition. Déploiement standard, aucune migration.
- [ ] **Réorganisation : "Historique des mouvements de caisse" déplacé après la liste
  des ventes** (`sessions/show.blade.php`), en tableau (`<table>`) distinct de la liste
  des ventes au lieu d'un bloc `<div>` positionné avant — plus lisible, ne se confond
  plus visuellement avec la liste des ventes qui la précède désormais. Déploiement
  standard, aucune migration.
- [ ] **Changement : "Rembourser maintenant" est désormais coché par défaut** sur le
  formulaire de retour (au lieu de "Créer un avoir pour ce client") — cohérent avec le
  fait qu'un retour se fait maintenant forcément le jour même, en session, client
  présent. Le choix "Créer un avoir" reste disponible (tiroir court, client à crédit).
  Déploiement standard, aucune migration.
- [ ] **Retiré : les liens de navigation vers `/retours` et `/remboursements-avoir`**
  (menu "Vente" et boutons du dashboard de session) — les écrans restent fonctionnels
  mais ne sont plus mis en avant ; le chemin normal est désormais de rechercher le
  numéro de facture dans la liste des ventes puis d'utiliser le bouton "Retourner" sur
  son détail. Déploiement standard, aucune migration.

- [ ] **Changement de règle métier majeur (côté CLIENT uniquement, le fournisseur est
  inchangé)** : un retour client n'est désormais possible que **le jour même de la
  vente d'origine**, et exige une **session de caisse ouverte** (celle du caissier qui
  traite le retour) — passé ce jour, plus aucun retour, même avec une session ouverte.
  Le **remboursement d'un avoir client** exige lui aussi une session ouverte, et la
  part payée en espèces sort désormais du **tiroir de cette session** (plafonnée à son
  solde théorique), plus de la Caisse Générale. Revient sciemment sur le comportement
  "aucune session requise" mis en place le 2026-09-11 (voir CLAUDE.md, règles 18/20).
  - [ ] **Migration requise** : `2026_09_15_104412_add_session_caisse_id_to_retour_ventes_table`
    (ajoute `retour_ventes.session_caisse_id`, nullable, FK `session_caisses`).
    `remboursement_avoir_clients.session_caisse_id` existait déjà (colonne
    pré-provisionnée jamais utilisée jusqu'ici) — aucune migration nécessaire pour elle.
  - [ ] **Effet de bord accepté** : les retours créés **avant** cette migration
    (`session_caisse_id` nul) disparaissent du journal de caisse par caisse
    (`comptabilite.caisses-vente.show`/`rapports.mouvements-caisse`) — pas de
    rattachement rétroactif incertain à la caisse de la vente d'origine. Ils restent
    visibles partout ailleurs (ticket, fiche client, `/retours` si encore du jour).
  - [ ] **Tester après déploiement** :
    - Retour sur une vente du jour, session ouverte → doit réussir.
    - Retour sur une vente d'un jour précédent → bloqué, message clair
      ("le retour n'est plus possible après le jour de la vente"), même avec une
      session ouverte.
    - Retour sans session ouverte → bloqué, message clair.
    - Remboursement d'avoir avec un tiroir de session insuffisant → bloqué, message
      clair, aucune écriture créée.
    - Remboursement d'avoir avec tiroir suffisant → sortie de caisse visible dans le
      journal de la session et le rapport de caisse ("Remboursement avoir"), comptée
      dans "Total sorties" et le solde théorique du tiroir.
    - `/retours` : ne liste plus que les factures du jour.
    - Aucune régression côté fournisseur (`RetourAchat`, `ReglementFournisseur`,
      `RemboursementAvoirFournisseur`) : toujours hors session, toujours Caisse Générale.

- [ ] **Correctif d'affichage global : tous les tableaux de l'app ont désormais un fond
  blanc explicite** (`$table-bg: #fff` dans `resources/sass/app.scss`, même principe que
  `$card-bg` déjà en place) — un `<table>` Bootstrap est transparent par défaut, donc un
  tableau non enveloppé dans une carte (ou dont le fond parent varie) laissait
  transparaître le fond de page ($body-bg, blanc cassé chaud) au lieu d'un vrai blanc.
  Nécessite un rebuild des assets (`npm run build`) — déjà fait et vérifié dans ce
  commit, mais à re-vérifier si le pipeline de déploiement ne rejoue pas ce build.
- [ ] **Réorganisation de la liste des ventes de la page de session**
  (`sessions/show.blade.php`) : colonne "Livraison" déplacée avant "Retours" (au lieu
  d'après) ; le lien "Retour aux caisses" déplacé en bas de page, après le bloc
  "Historique des mouvements de caisse" (au lieu de juste après la liste des ventes, où
  il ne restait désormais que la pagination). Déploiement standard, aucune migration.
- [ ] **Changement : la colonne "Livraison" affiche une notation "livré/total"** (ex.
  "5/12", "10/10") au lieu d'un texte ("Entièrement livrée" / "X pièce(s)") — plus
  compact, cohérent avec une lecture rapide façon score. Couleur du badge inchangée
  (vert si entièrement livré, ambre sinon). Déploiement standard, aucune migration.

## 2026-09-11 — retour comptant, remboursement immédiat, écrans dédiés Retours/Avoirs

- [ ] **Nouveau : les retours apparaissent désormais dans le journal de caisse**
  (`rapports.mouvements-caisse` et `comptabilite.caisses-vente.show`, rapport dédié à
  une caisse toutes sessions confondues) — rattachés à leur caisse via la vente
  d'origine, libellés "Retour (avoir)", jamais avec un signe +/− (ne mouvementent
  jamais le tiroir, règle 18) et jamais comptés dans les totaux entrées/sorties/ventes
  (nouveau KPI "Retours" séparé). Les avoirs remboursés (`RemboursementAvoirClient`)
  restent **hors** de ces deux rapports — aucun lien structurel avec une caisse (règle
  20), toujours visibles seulement via `/remboursements-avoir`/la fiche client/la
  Trésorerie. Déploiement standard, aucune migration.
  - [ ] **Tester après déploiement** : ouvrir le rapport dédié d'une caisse ayant au
    moins un retour → vérifier qu'il apparaît dans le journal et dans le KPI "Retours",
    sans signe +/−, et que les autres totaux (ventes/entrées/sorties) ne bougent pas.
    Vérifier aussi le filtre "Type : Retour (avoir)" et les exports PDF/Excel.
- [ ] **Correctif : "Total ventes net" pouvait s'afficher négatif** sur la fiche d'un
  client n'ayant jamais rien acheté mais ayant reçu un avoir attribué depuis la vente
  comptant anonyme de quelqu'un d'autre (`Client::totalVentes()` déduisait TOUS les
  retours crédités à son compte, pas seulement ceux sur ses propres ventes). Corrigé +
  plafonné à 0. Déploiement standard, aucune migration.
- [ ] **Correctif de saisie : le champ "À retourner" du modal de retour n'empêchait pas
  réellement de dépasser le reste retournable** — l'attribut HTML `max` seul n'empêche
  pas de taper une valeur plus grande, seulement un léger style visuel. Clampé
  activement à la saisie. Le serveur refusait déjà un dépassement (aucune donnée
  invalide n'a pu être enregistrée), c'est uniquement l'expérience de saisie qui est
  corrigée. Déploiement standard, aucune migration.
- [ ] **Amélioration : liste "Ventes" de la fiche client réorganisée** dans un ordre de
  lecture plus logique (Numéro, Date, Magasin, Total, Réglé, Reste dû, Statut, Livraison,
  Retourné, Actions — les montants regroupés, plus de colonnes éparpillées) et la
  colonne "Retours" devient "Retourné" : affiche désormais la **quantité** retournée en
  notation `X/Y pièce(s)` (comme "Livraison"), y compris quand tout est retourné/livré
  (`10/10`, pas de texte "Entièrement…" séparé) — juste la couleur du badge change
  (rouge = tout retourné, vert = tout livré, ambre = partiel).
  Déploiement standard, aucune migration.
- [ ] **Correctif : le client crédité par un retour n'était affiché nulle part** sur le
  ticket de vente ni dans la liste `/retours` — ces deux écrans montraient toujours le
  client de la **vente** (toujours "Comptant" pour une vente anonyme), jamais celui du
  **retour** lui-même. Ajouté : "Crédité à …" sur chaque retour du ticket (lien vers sa
  fiche), et "Avoir crédité à …" sous "Comptant" dans `/retours`. Déploiement standard,
  aucune migration.
  - [ ] **Tester après déploiement** : faire un retour sur une vente comptant en
    l'attribuant à un client existant → vérifier que son nom apparaît bien sur le ticket
    (section "Retours") et dans la liste `/retours`.
- [ ] **Correctif d'affichage : les KPI de décomposition de la session de caisse
  tenaient sur 4 colonnes fixes** (`col-md-3`) — l'ajout du 5ᵉ KPI "Retours" faisait
  passer le dernier à la ligne suivante. Passé en colonnes flexibles (`col-md`, comme
  la première rangée de KPI au-dessus), s'adapte à n'importe quel nombre de cartes.
  Déploiement standard, aucune migration.
- [ ] **Nouveau : "Vente → Facture" mène directement à la session en cours** si le
  caissier connecté en a déjà une d'ouverte, au lieu de repasser par l'écran de
  sélection de caisse à chaque clic. Déploiement standard, aucune migration.
  - [ ] **Tester après déploiement** : avec une session ouverte, cliquer "Vente →
    Facture" dans le menu → doit atterrir directement sur `/sessions/{id}` de cette
    session (pas sur `/sessions`). Sans session ouverte, le lien doit rester
    `/sessions` comme avant.

- [ ] **Le bouton "Retourner" du ticket de vente était grisé sans client** — reliquat de
  l'ancienne règle "retour bloqué sans client" (voir entrée 2026-09-10 ci-dessous),
  oublié côté vue après la correction du service. Corrigé
  (`resources/views/ventes/ticket.blade.php`). Déploiement standard, aucune migration.
- [ ] **Nouveau : écran "Retours" (`/retours`, menu Vente → Retours)** — recherche une
  facture par numéro sans devoir déjà la connaître, puis ouvre sa fiche pour y faire le
  retour (symétrique de l'écran "Bon de livraison"). Déploiement standard, aucune
  migration.
- [ ] **Nouveau : remboursement immédiat optionnel au moment du retour** — deux boutons
  radio sur le formulaire de retour (si `client.reglement`, "Créer un avoir" / "Rembourser
  maintenant") qui enchaînent un remboursement d'avoir dans la foulée, sans repasser par
  la fiche client. Toujours deux écritures distinctes et immuables (retour puis
  remboursement) — ne change rien aux règles 18/20.
  Déploiement standard, aucune migration.
- [ ] **Nouveau : écran "Avoirs clients" (`/remboursements-avoir`, menu Vente → Avoirs
  clients)** — liste centralisée de tous les clients ayant un avoir disponible, avec
  remboursement direct depuis la liste (symétrique de l'écran de règlement des dettes).
  Déploiement standard, aucune migration.
- [ ] **Changement : le retour sur une vente comptant impose désormais de choisir ou
  créer le client réellement crédité**, au lieu de tout regrouper silencieusement sur
  "Client comptant" (revient sur l'entrée 2026-09-10 ci-dessous — plusieurs personnes
  différentes font des ventes comptant, un seul compte partagé les rendait impossibles
  à distinguer) — **sauf si le retour est remboursé tout de suite** (rien ne reste sur
  un compte, l'identification n'apporte alors rien : le sélecteur disparaît, "Client
  comptant" sert de simple compte de passage). "Client comptant" reste sélectionnable,
  pré-choisi par défaut, pour le cas où l'identité est vraiment inconnue. Déploiement
  standard, aucune migration.
  - [ ] **Tester après déploiement** : depuis `/retours`, retrouver une facture
    **comptant** et faire un retour → vérifier que le sélecteur "Client à créditer"
    apparaît (obligatoire), que le bouton "+" crée bien un nouveau client à la volée
    et le sélectionne, et qu'un retour sur une vente **avec** client ne montre pas ce
    sélecteur (aucun changement). Cocher "Rembourser tout de suite" **avant** de choisir
    un client → vérifier que le sélecteur disparaît et n'est plus obligatoire ; vérifier
    que
    l'avoir est bien remboursé dans le même geste ; vérifier `/remboursements-avoir`
    liste bien les clients à solde négatif et que "Rembourser" y fonctionne.
- [ ] **Correctif : la facture d'origine d'un avoir n'apparaissait nulle part dans
  "Ventes" sur la fiche du client crédité**, quand ce client n'était pas l'acheteur
  réel (vente comptant, retour attribué à un client précis) — seule "Historique du
  compte" plus bas la montrait, peu visible. La section "Ventes" inclut désormais
  aussi ces factures, avec un badge "Retour attribué" pour ne pas les confondre avec
  un achat réel du client (`ClientController::show()`). Déploiement standard, aucune
  migration.
  - [ ] **Tester après déploiement** : faire un retour sur une vente comptant en
    l'attribuant à un client existant → ouvrir la fiche de ce client → vérifier que la
    facture apparaît dans "Ventes" avec le badge "Retour attribué" et un lien
    fonctionnel vers le ticket.

## 2026-09-10 — retours déduits du "reste dû" + coût moyen pondéré par magasin

- [ ] **Correctif : un retour (client ou fournisseur) déjà enregistré déduit
  maintenant le "reste dû" affiché** sur la facture/le bon de commande
  concerné (`Vente::soldeDuReel()`, `CommandeAchat::resteDu()`) — jusqu'ici
  un retour créditait bien l'avoir du compte (règle 18), mais le "reste dû"
  affiché sur CE document précis ne bougeait jamais, ce qui pouvait laisser
  croire à une dette encore ouverte alors qu'elle était déjà couverte par le
  retour. Impacte aussi les KPI "Total ventes"/"Total achats" (fiches
  client/fournisseur, désormais nets des retours) et les exports PDF/Excel
  clients/fournisseurs/commandes (nouvelle colonne "Avoir"/"Retours").
  Déploiement standard, aucune migration (recalcul à la volée, aucune donnée
  historique modifiée).
  - [ ] **Tester après déploiement** : ouvrir une facture/un bon de commande
    ayant déjà un retour enregistré → vérifier que le "reste dû" en tient
    compte ; vérifier la nouvelle colonne "Avoir" sur les listes et exports
    clients/fournisseurs.
- [ ] **Nouveau : coût moyen pondéré (CMP) affiché sur l'état de stock**
  (`/stock`), export Excel et PDF — une seule valeur par produit, calculée
  sur les **magasins uniquement** (dépôts exclus, `Produit::coutMoyenPondere()`).
  Déploiement standard, aucune migration (donnée déjà stockée sur `Stock`,
  simple affichage supplémentaire).
- [ ] **Nouveau : filtre de recherche en direct sur l'état de stock**
  (préfixe du nom produit, filtre à chaque lettre tapée sans rechargement).
  Déploiement standard, aucune migration.

## 2026-09-10 — retour possible sur une vente comptant (client "Client comptant")

- [ ] **Changement de règle métier : une vente comptant (sans client identifié)
  peut désormais faire l'objet d'un retour** — jusqu'ici bloqué (`RetourVenteService`
  refusait, aucun compte à créditer). L'avoir est maintenant crédité sur un
  client singleton **"Client comptant"** (`Client.comptant_defaut`, même
  principe que `CompteTresorerie.type=caisse_generale`) plutôt que sur un
  compte inexistant ; la vente elle-même reste anonyme, seul le retour
  référence ce compte générique. Voir CLAUDE.md, section « Retours ».
  - [ ] **Migration requise** : `2026_09_10_151618_add_comptant_defaut_to_clients_table`
    (ajoute `clients.comptant_defaut`, booléen, défaut `false`).
  - [ ] **Seeder requis** : `ClientComptantDefautSeeder` (crée le client
    "Client comptant" s'il n'existe pas déjà — idempotent, `firstOrCreate`).
    Ajouté à `DatabaseSeeder`, mais à rejouer explicitement sur une base déjà
    en production (`php artisan db:seed --class=ClientComptantDefautSeeder`).
  - [ ] **Tester après déploiement** : enregistrer un retour sur une vente
    comptant existante → vérifier qu'aucune erreur ne survient, que l'avoir
    apparaît sur la fiche du client "Client comptant", et que ce client ne
    peut pas être supprimé depuis l'écran clients.

## 2026-09-09 — onglet "Bon de livraison" (liste globale)

- [ ] **Nouveau : liste globale des factures à livrer** (`/bons-livraison`, menu
  Vente → Bon de livraison). Déploiement standard, aucune migration. Deux
  changements de code à connaître :
  - `ventes/{vente}/ticket` n'exige plus la permission `vente.creer` au niveau
    de la route — le contrôle se fait maintenant dans
    `VenteController::ticket()` (`vente.creer` OU `vente.livrer`), pour qu'un
    utilisateur n'ayant que `vente.livrer` (livreur/magasinier) puisse ouvrir
    une facture et y enregistrer une livraison.
  - La liste couvre **toutes** les ventes non entièrement livrées (comptant
    comme crédit, sans restriction par type de client) — aucun impact sur les
    ventes déjà livrées à 100 %.
  **Tester après déploiement** : avec un utilisateur n'ayant que
  `vente.livrer` (pas `vente.creer`), vérifier l'accès à `/bons-livraison`,
  l'ouverture d'une facture depuis cette liste, et l'enregistrement d'une
  livraison — sans session de caisse ouverte. Vérifier aussi qu'un utilisateur
  avec seulement `vente.creer` garde l'accès normal au ticket (non-régression).
- [ ] **Rappel** : `vente.livrer` n'est actuellement coché pour aucun rôle sur
  ce déploiement — l'accorder sur `/roles` au(x) rôle(s) concerné(s) (ex.
  Caissier, ou un rôle dédié), sans quoi seul le Superadmin verra le nouvel
  onglet.

---

## 2026-09-07 — correctifs suite à l'audit de sécurité

- [ ] **Correctif : rôle « Gérant » seedé n'obtient plus `utilisateur.gerer`/
  `role.gerer` par défaut** (`database/seeders/RolePermissionSeeder.php`) —
  ces deux permissions sont réservées Superadmin (`config/permissions.php`,
  `superadmin_only`) car elles permettent de créer des comptes/rôles
  arbitraires ; le Gérant seedé les recevait pourtant intégralement via
  `syncPermissions(config('permissions.catalogue'))`. Corrigé pour les
  **nouvelles installations** uniquement (le seeder ne re-synchronise jamais
  un rôle déjà existant, pour ne pas écraser une personnalisation faite
  depuis `/roles`).
  - [ ] **Action manuelle requise sur toute installation existante** : en tant
    que Superadmin, ouvrir `/roles`, éditer le rôle **Gérant** et décocher
    `utilisateur.gerer` et `role.gerer` s'ils sont cochés. Aucune commande,
    aucune migration — juste ce clic en interface.
- [ ] **Correctif : blocage anti-brute-force du code de connexion, désormais
  aussi par compte (indépendant de l'IP)** (`AuthenticatedSessionController`)
  — le verrou existant (identifiant + IP, 5 tentatives/60 s) ne bloquait rien
  pour un attaquant changeant d'IP à chaque tentative. Nouveau verrou
  complémentaire par identifiant seul (15 tentatives/15 min, toutes IP
  confondues). Aucune configuration serveur requise (utilise le cache
  applicatif existant).
- [ ] **Correctif : ajustement/casse de stock désormais restreint au magasin
  du gérant** (`StockMouvementController::store()`) — un gérant ne peut plus
  ajuster/casser du stock d'un magasin qui n'est pas le sien (même garde que
  sur les ventes/sessions de caisse). Un transfert reste inchangé (vise par
  nature deux magasins).
- [ ] **Dépendances mises à jour (vulnérabilités connues corrigées)** :
  `guzzlehttp/guzzle` 7.14.1 → 7.15.5, `league/commonmark` 2.8.3 → 2.10.0
  (`composer audit` confirmait 16 advisories, dont un XSS et plusieurs déni
  de service sur commonmark ; 0 après mise à jour). Côté front,
  `npm audit fix` a nettoyé `nanoid`/`postcss` (0 vulnérabilité restante).
  Déploiement standard, plus :
  ```
  composer install --no-dev --optimize-autoloader
  npm ci && npm run build
  ```
  Aucune migration.
- [ ] **`SESSION_SECURE_COOKIE=true` à ajouter au `.env` serveur** (site déjà
  en HTTPS) — nouvelle variable documentée dans `.env.example` et dans la
  check-list `DEPLOIEMENT.md`, absente jusqu'ici : sans elle le cookie de
  session peut être transmis en clair.
- [ ] **Tester après déploiement** : connexion avec un compte Gérant existant
  (toujours fonctionnelle) ; vérifier dans `/roles` que Gérant n'a plus
  `utilisateur.gerer`/`role.gerer` après l'action manuelle ci-dessus ; tenter
  un ajustement de stock depuis un compte Gérant sur un magasin qui n'est pas
  le sien (URL forgée) → doit être refusé (403).

## 2026-09-04

- [ ] **Correctif : règlement de dette client, désormais uniquement par
  facture** — l'écran « Règlement client »
  (`/sessions/{session}/reglements/creer`) listait déjà les clients endettés
  mais un règlement saisi dessus n'était rattaché à aucune facture précise :
  le solde total du client baissait bien (dérivé des écritures, toujours
  juste), mais le « réglé »/« reste dû » de chaque facture individuelle ne
  bougeait jamais — impossible de savoir quelle facture avait été payée. Le
  bouton « Régler la dette totale » (règlement non ciblé) est retiré : le
  règlement se fait maintenant uniquement facture par facture, avec un
  contrôle qui empêche de saisir plus que le reste dû de CETTE facture
  (bloqué côté formulaire et rejeté côté serveur si dépassé). Déploiement
  standard, plus :
  ```
  npm run build
  ```
  Aucune migration (aucun changement de schéma). `npm run build` nécessaire
  car `resources/js/app.js` a aussi changé (voir point suivant).
  - [ ] **Correctif associé : tableau de bord caissier accessible à tout
    rôle ayant une session ouverte** — le dashboard caissier (avec la
    répartition Décomposition) ne s'affichait qu'aux utilisateurs ayant
    littéralement le rôle Spatie « Caissier » ; un Gérant/Superadmin qui
    ouvre lui-même une session de caisse (règle 6 : autorisé pour tous les
    rôles) ne voyait jamais ce tableau de bord. Corrigé
    (`DashboardController::__invoke()`) : affiché dès qu'une session ouverte
    existe pour l'utilisateur connecté, peu importe son rôle.
  - [ ] **Nouveau KPI « Règlements clients »** sur l'écran de session de
    caisse (`/sessions/{session}`, section Décomposition — PAS sur le
    dashboard) : total encaissé pour des dettes clients pendant cette
    session précise, tous moyens de paiement confondus.
  - [ ] Les règlements clients (part espèces) apparaissent désormais comme
    lignes distinctes dans le rapport `rapports/mouvements-caisse` et dans
    le rapport de session (`sessions/{id}/rapport`), filtrables par type.
  - [ ] **Tester après déploiement** : créer une vente à crédit pour un
    client, ouvrir « Encaisser un règlement » depuis une session (même
    ouverte par un autre caissier que celui qui a fait la vente — n'importe
    quelle session ouverte doit pouvoir encaisser n'importe quel client,
    testé et confirmé fonctionnel) → dérouler le client, régler sa facture
    d'un montant partiel → vérifier que « Réglé »/« Reste dû » de CETTE
    facture précise se met à jour (ticket + fiche client), que le KPI
    « Règlements clients » de la session affiche le bon total, et que la
    ligne apparaît dans les deux rapports. Vérifier qu'un montant supérieur
    au reste dû est refusé (bouton désactivé + rejet serveur si contourné).

- [ ] **UI : détail facture et écran de vente réorganisés** — sur
  `ventes/{vente}/ticket` (détail facture), les boutons Ticket caisse/
  Facture/PDF/Excel sont désormais alignés à droite sur la même ligne que
  Régler/Retourner/Signaler/Annuler (au lieu d'être isolés dans l'en-tête).
  Sur l'écran de vente (POS), le bouton « Dupliquer » d'une ligne du panier
  a été retiré et les cartes Récapitulatif/Client réduites pour laisser plus
  de place au tableau produits. Déploiement standard, plus :
  ```
  npm run build
  ```
  (déjà inclus dans la commande du point précédent si déployé en même
  temps). Aucune migration, aucune permission nouvelle.
  - [ ] **Tester après déploiement** : ouvrir le détail d'une facture,
    vérifier l'alignement des boutons ; ouvrir l'écran de vente, vérifier
    que le panier n'a plus d'icône dupliquer et que le tableau produits est
    bien plus large.

- [ ] **Correctif : destination de ligne d'achat désormais optionnelle sur
  un bon de commande standard** — jusqu'ici `magasin_destination_id` était
  obligatoire sur chaque ligne dès la création, alors qu'une commande
  classique (brouillon → validée → réceptionnée plus tard) n'a pas besoin de
  connaître sa destination à l'avance (elle se choisit de toute façon à
  nouveau à la réception, voir CLAUDE.md « Achat à crédit et comptes
  fournisseurs »). Désormais obligatoire uniquement pour le raccourci
  « Enregistrer et réceptionner immédiatement » (achat direct), qui crée la
  réception dans la même transaction. Nécessite :
  ```
  php artisan migrate
  ```
  (nouvelle colonne `commande_achats.type`, `commande` | `achat_direct`,
  défaut `commande` pour les commandes existantes — voir
  `2026_09_04_105029_add_type_to_commande_achats_table.php`).
  - [ ] **Correctif associé : numérotation des documents d'achat** —
    `ReceptionAchat` avait un numéro dérivé de la commande
    (`{numero_commande}-R{rang}`), remplacé par une série indépendante
    (`BA-000123`, comme `BC-000123`/`RF-000123`) via une nouvelle classe
    partagée `App\Support\NumeroDocument`. Le numéro d'un bon de commande
    auto-généré passe d'un tirage aléatoire à un numéro séquentiel basé sur
    son id réel (`BC-00001`, `BC-00002`…). Aucun numéro déjà attribué n'est
    modifié rétroactivement.
  - [ ] **Correctif associé : retour fournisseur bloqué sur une commande
    jamais réceptionnée** — une commande validée mais dont aucune réception
    n'a encore été enregistrée n'a réellement rien reçu ; le retour lève
    maintenant une erreur explicite au lieu de se comporter comme si tout
    avait été reçu (legacy). Distingue une commande legacy (mouvement de
    stock direct posé à la validation, avant les réceptions échelonnées)
    d'une commande récente encore en attente via
    `CommandeAchat::aDesMouvementsStockDirects()`.
  - [ ] **Tester après déploiement** : créer un bon de commande standard
    sans renseigner de destination sur les lignes → doit s'enregistrer ;
    utiliser « Enregistrer et réceptionner immédiatement » sans destination
    → doit être refusé. Réceptionner un bon de commande et vérifier que le
    numéro du bon d'achat est bien `BA-xxxxxx`. Tenter un retour fournisseur
    sur une commande validée mais jamais réceptionnée → doit être refusé
    avec un message explicite.

- [ ] **Coquille desktop** : aucune action — rien dans `desktop/` n'a changé
  pour cette session, pas de nouvelle version à builder/publier (voir
  `GUIDE-MISES-A-JOUR.md`, Cas 1 : changement Laravel/Blade pur).

## 2026-09-03 (suite)

- [ ] **Nouveau : N° de bon de livraison et N° de facture fournisseur sur une
  réception** — deux champs optionnels et indépendants ajoutés sur chaque
  réception (« Réceptionner » depuis la fiche, ou « Enregistrer et réceptionner
  immédiatement » à la création), pour noter séparément le numéro du bon de
  livraison remis à la livraison et celui de la facture (parfois remise en
  même temps, parfois séparément/plus tard selon le fournisseur) — affichés
  dans l'historique des réceptions, la facture PDF et l'export Excel.
  Déploiement standard, plus :
  ```
  php artisan migrate --force
  ```
  Deux migrations (`add_numero_facture_fournisseur_to_reception_achats_table`,
  `add_numero_bon_livraison_fournisseur_to_reception_achats_table`), colonnes
  nullables — aucune donnée existante touchée, aucune permission nouvelle.
  - [ ] **Tester après déploiement** : réceptionner un bon de commande en
    saisissant un numéro de bon de livraison et/ou un numéro de facture
    fournisseur → vérifier qu'ils apparaissent dans l'historique des
    réceptions de la fiche, sur la facture PDF et dans l'export Excel.
    Vérifier qu'une réception sans ces numéros (champs laissés vides) reste
    identique à avant.

## 2026-09-03

- [ ] **Nouveau : Réceptions échelonnées sur les bons de commande fournisseur**
  — le **bon de commande** (`CommandeAchat`, prix indicatifs, aucun impact
  stock/argent tant qu'il n'est pas reçu) peut désormais être **reçu en
  plusieurs fois** ; chaque réception est un **bon d'achat** (`ReceptionAchat`,
  immuable) qui capture la quantité réellement arrivée (partielle ou totale),
  le prix réellement facturé (peut différer de l'indicatif, alimente le CMP)
  et la destination réelle (éditable, pré-remplie avec le plan mais
  modifiable) — avec KPI commandé/reçu/reste à recevoir et montant
  indicatif/réel/écart sur `commande-achats/show`. Pour un **achat déjà
  effectué** (achat comptant chez le fournisseur, sans document envoyé au
  préalable), un raccourci « Enregistrer et réceptionner immédiatement » sur
  l'écran de création fait tout en une seule action (création + validation +
  réception complète, avec paiement). **Rétrocompatible sans migration de
  données** : seules les commandes créées à partir de maintenant utilisent ce
  nouveau système — une commande déjà validée avant ce déploiement continue
  de se comporter exactement comme avant, aucune donnée/mouvement historique
  touché. Déploiement standard, plus :
  ```
  php artisan migrate --force
  php artisan db:seed --class=RolePermissionSeeder --force
  npm run build
  ```
  Trois nouvelles migrations créent `reception_achats`/`ligne_reception_achats`
  et ajoutent une colonne nullable `reception_achat_id` à `paiement_achats`
  (aucune donnée existante touchée). Le seeder enregistre la nouvelle
  permission `achat.receptionner` — comme pour toute nouvelle permission,
  elle n'est **cochée pour aucun rôle existant** automatiquement : l'accorder
  manuellement sur `/roles` au(x) rôle(s) qui doivent pouvoir réceptionner
  (ex. Gérant). `npm run build` n'est utile ici que si le bundle JS/CSS n'a
  pas déjà été reconstruit depuis une session de travail précédente sur ce
  poste — aucun changement JS propre à cette fonctionnalité (tout est en
  Blade/Alpine inline), mais garder le réflexe.
  - [ ] **Vocabulaire à l'écran** : « Bon de commande » (le document,
    brouillon/validée, prix indicatifs) et « Bon d'achat » (chaque réception,
    l'événement réel) sont maintenant deux libellés distincts partout à
    l'écran — même entité `CommandeAchat`/route `commande-achats.*` en
    interne, aucun changement d'URL.
  - [ ] **Changement de comportement à connaître** : "Valider" un bon de
    commande ne mouvemente plus le stock ni la dette fournisseur — c'est
    désormais la réception ("Réceptionner" depuis la fiche, ou "Enregistrer
    et réceptionner immédiatement" depuis la création) qui le fait. Une
    commande validée mais pas encore réceptionnée affiche un bouton "Annuler"
    actif (rien à réconcilier) ; une fois au moins une réception enregistrée,
    "Annuler" est bloqué (corriger via un retour fournisseur à la place). Sur
    l'écran de création, le paiement ne se saisit plus qu'avec le raccourci
    "Enregistrer et réceptionner immédiatement" (visible seulement avec les
    permissions `achat.valider` **et** `achat.receptionner`) — un simple
    "Enregistrer en brouillon" n'accepte plus de paiement.
  - [ ] **Tester après déploiement** :
    - Sur la fiche d'un bon de commande validé, cliquer "Réceptionner" **sans
      rien saisir dans la section paiement** → doit réussir (c'était cassé
      avant cette correction : erreur "paiements.0.moyen_paiement_id est
      obligatoire"). Puis réceptionner avec un paiement partiel → dette
      correcte.
    - Créer un nouveau bon avec "Enregistrer et réceptionner immédiatement"
      (avec et sans paiement) → stock/CMP/dette mis à jour immédiatement en
      une seule action, aux destinations et prix saisis.
    - Créer un bon de commande brouillon → valider séparément depuis la
      fiche (vérifier qu'aucun stock ne bouge) → réceptionner une partie des
      lignes avec un prix et une destination différents de l'indicatif →
      stock augmenté au bon endroit, CMP recalculé au prix réel, dette
      limitée au reste dû de cette réception. Réceptionner le complément
      plus tard → KPI "reste à recevoir" à 0, taux 100 %.
    - Vérifier qu'un retour fournisseur sur une ligne reçue à prix réel
      différent de l'indicatif crédite l'avoir sur la base du prix
      réellement facturé.
    - Vérifier que la facture (écran, PDF, Excel) et la liste des bons de
      commande affichent bien le montant réel et un récapitulatif des
      réceptions pour un bon partiellement/totalement reçu.
    - Vérifier qu'une commande déjà validée **avant** ce déploiement affiche
      des montants et un bouton "Annuler" identiques à avant.

## 2026-09-02

- [ ] **Sécurité (critique) : limitation des tentatives de connexion** —
  déploiement standard, aucune migration. Rien à configurer : le verrou (5
  tentatives, 60s d'attente, par identifiant + IP) est actif dès le
  déploiement.
  - [ ] **Tester après déploiement** : saisir un mauvais code PIN 5-6 fois
    de suite sur `/login` → le message doit passer à "Trop de tentatives.
    Réessayez dans N secondes." ; vérifier qu'un autre identifiant peut se
    connecter normalement pendant ce temps.

- [ ] **Sécurité (haute) : IDOR sur les ventes en attente** — déploiement
  standard, aucune migration. Un Gérant d'un magasin ne peut plus modifier/
  reprendre une vente en attente d'un autre magasin en devinant son ID.

- [ ] **Sécurité (haute + moyenne, coquille desktop) : restriction de
  navigation** — se met à jour automatiquement (v1.0.9) comme d'habitude,
  aucune action côté serveur. La fenêtre principale refuse désormais toute
  navigation hors de l'adresse du serveur configuré, et l'aperçu PDF refuse
  une URL qui ne vient pas de ce même serveur.
  - [ ] **Tester après mise à jour** : vérifier qu'imprimer une facture/un
    devis/un bon d'achat fonctionne toujours normalement (aucune régression
    attendue, ces PDF viennent déjà du même serveur).

- [ ] **Nouveau : alerte d'abonnement + téléphone utilisateur + confidentialité
  des comptes privilégiés**. Déploiement standard, plus :
  ```
  php artisan migrate --force
  ```
  Une migration (`users.telephone`, nullable), aucune donnée existante
  touchée. Aucune permission à seeder.
  - [ ] **Tester après déploiement** : activer une formule de 5 jours ou
    moins pour voir le bandeau d'alerte apparaître sur le dashboard (lien
    "Renouveler maintenant" pour Superadmin/développeur uniquement).
  - [ ] Vérifier que "Abonnement" apparaît bien en dernier dans le sous-menu
    Administration (Superadmin/développeur), et n'apparaît plus dans la
    barre horizontale directement.
  - [ ] Vérifier qu'un Gérant (ou tout compte non Superadmin/développeur) ne
    voit plus les comptes Superadmin/développeur sur `/utilisateurs`.
  - [ ] Créer un utilisateur avec un numéro de téléphone pour confirmer le
    nouveau champ.

- [ ] **Nouveau : option "Remplacer" à l'activation** — case à cocher
  (visible seulement s'il reste des jours) sur le formulaire d'activation,
  pour un vrai changement d'offre (ex. repasser un client en Essai malgré
  des jours restants) au lieu du renouvellement additif par défaut.
  Déploiement standard, aucune migration.

- [ ] **Nouveau : Système d'abonnement** — passé une date d'expiration, tout
  compte autre que Superadmin/développeur est redirigé vers "Mon abonnement"
  (lien dans le menu utilisateur, après "Guide d'utilisation") sur **toutes**
  les routes. Déploiement standard, plus :
  ```
  php artisan migrate --force
  php artisan db:seed --class=FormuleAbonnementSeeder --force
  ```
  Trois nouvelles tables (`formule_abonnements`, `abonnement_activations`,
  `configuration_abonnements`), aucune donnée existante touchée. **Aucune
  permission à seeder** — l'accès à Gestion abonnement est en dur sur le
  rôle Superadmin (+ compte développeur), jamais délégable via `/roles`.
  - [ ] `FormuleAbonnementSeeder` crée 3 formules de départ (Essai/Mensuelle/
    Illimité, prix indicatifs à ajuster) — **modifiables** ensuite depuis
    Gestion abonnement (pas de bouton suppression pour l'instant, seulement
    activer/désactiver et en ajouter).
  - [ ] Définir `ABONNEMENT_DEVELOPPEUR_USERNAME` dans le `.env` du poste
    serveur (voir `.env.example`) **si** un compte support côté éditeur doit
    pouvoir agir sans passer par le Superadmin du client — sinon laisser
    vide, rien ne change.
  - [ ] **Tant qu'aucune activation n'est enregistrée, rien n'est bloqué**
    (comportement volontaire, voir le plan) — l'écran Gestion abonnement
    (menu horizontal, visible uniquement en Superadmin) sert à activer
    l'abonnement du client quand c'est voulu commercialement. Ne pas
    oublier de le faire, sinon l'app reste en accès libre indéfiniment.
  - [ ] **Tester après déploiement** : en Superadmin, créer une formule (ex.
    "30 jours"), l'activer, vérifier que jours restants/historique
    s'affichent sur Gestion abonnement **et** sur Mon abonnement (compte
    Caissier). Renseigner les coordonnées de contact. Vérifier qu'un compte
    Caissier/Gérant est bien redirigé vers Mon abonnement une fois la date
    de fin dépassée (peut se tester en activant une formule très courte),
    et que Superadmin garde un accès complet malgré l'expiration.

- [ ] **Nouveau : quantités décimales (ex. 1.5 mètre de câble)** — dernière et
  plus large des trois étapes d'ouverture hors zone FCFA (après devise et
  TVA). Toutes les colonnes de quantité transactionnelle (stock, mouvements
  de stock, lignes de vente/achat/devis/vente-en-attente, retours,
  inventaire, transferts, bons de livraison) passent de entier à
  `decimal(12,3)`. **`unite_ventes.facteur` reste volontairement entier**
  (multiplicateur d'un lot, ex. carton de 12 — pas une quantité). Aucune
  bascule dans Paramètres : la saisie décimale est **toujours autorisée**,
  décision prise avec l'utilisateur (une quantité entière saisie aujourd'hui
  se comporte à l'identique — 5 reste "5" à l'affichage, jamais "5.000").
  Déploiement standard, plus :
  ```
  php artisan migrate --force
  npm run build
  ```
  La migration modifie le type de 15 colonnes en SQL brut (`ALTER TABLE
  MODIFY COLUMN`, pas de `->change()` — doctrine/dbal n'est pas installé sur
  ce projet, voir le commentaire de la migration) ; **aucune donnée
  existante perdue ou tronquée** (testé migration + rollback + remigration
  sur les données réelles avant ce déploiement). `npm run build` est
  **indispensable** cette fois : la saisie décimale (virgule ou point
  acceptés, normalisés côté serveur) dépend de `resources/js/app.js`
  recompilé — sans ce rebuild, les écrans de vente/devis continueraient de
  tronquer toute quantité décimale tapée (comportement JS inchangé tant que
  le bundle n'est pas reconstruit).
  - [ ] **Tester après déploiement** : sur l'écran de vente, ajouter une
    ligne et taper une quantité avec virgule (ex. "1,5") → doit s'afficher
    "1.5" dans le panier et le total se recalculer correctement (ex. 1.5 ×
    280 F = 420 F) ; finaliser la vente et vérifier le ticket ("1.5" propre,
    pas "1.500") ; vérifier qu'une vente à quantité entière (ex. "3") reste
    identique à avant (affichage "3", pas "3.0"). Même test côté devis,
    achat (bon d'achat), transfert de stock, mouvement de stock manuel
    (casse/ajustement), et comptage d'inventaire.
  - [ ] Vérifier qu'un retour (client ou fournisseur) sur une ligne à
    quantité décimale plafonne bien au reste retournable (ex. vendu 1.5,
    déjà retourné 0.5 → max retournable 1.0 sur le formulaire).

- [ ] **Nouveau : TVA optionnelle sur la vente et le devis** — deuxième étape
  de l'ouverture hors zone FCFA (après la devise), même principe que côté
  achat : `taxe_id` optionnel par ligne (référentiel `Taxe` déjà existant,
  Administration → Taxes), TTC dérivé, jamais stocké. Déploiement standard,
  plus :
  ```
  php artisan migrate --force
  ```
  Deux nouvelles migrations (`ligne_ventes.taxe_id`, `ligne_devis.taxe_id`,
  toutes deux nullables), aucune donnée existante touchée — une vente/un
  devis sans taxe choisie se comporte exactement comme avant (0 F de taxe,
  mêmes montants qu'aujourd'hui). Aucune nouvelle permission (réutilise
  `taxe.gerer`, déjà seedé côté achat).
  - [ ] **Changement de comportement à connaître** : `Vente.total_net` (le
    montant réellement dû/encaissé/porté en dette) inclut désormais la taxe
    quand une ligne en porte une — la remise sur le total s'applique au
    montant TTC (HT + taxes), pas seulement au HT. Sans taxe configurée sur
    aucune ligne, ce calcul reste identique à avant.
  - [ ] Le sélecteur "Taxe" par ligne (écran de vente, devis) ne s'affiche
    que si au moins une taxe active existe — invisible par défaut pour tout
    client qui n'utilise pas cette fonctionnalité.
  - [ ] **Tester après déploiement** : créer une taxe (ex. "TVA", 18%) dans
    Administration → Taxes ; sur l'écran de vente, ajouter une ligne avec
    cette taxe → vérifier que le récapitulatif affiche "Total taxes" et que
    le ticket/la facture affichent la ventilation HT/Taxes/Net ; même
    vérification côté devis (création + transformation en vente, la taxe
    choisie doit être reprise telle quelle) ; vérifier qu'une vente sans
    taxe reste identique à avant (aucune ligne "Total taxes" affichée).

- [ ] **Nouveau : Devise configurable (affichage uniquement, pas de
  conversion)** — permet d'utiliser l'application hors zone FCFA (Euro,
  Dollar…). Un référentiel `Devise` (nom + abréviation) remplace le "F"
  codé en dur partout (tickets, factures, dashboard, écran de vente/achat/
  devis en direct via `window.DEVISE_ABREVIATION`). **Aucune conversion de
  montant** : changer la devise ne fait que changer le libellé affiché, les
  montants restent des entiers identiques. Déploiement standard, plus :
  ```
  php artisan migrate --force
  php artisan db:seed --class=DeviseSeeder --force
  ```
  Deux nouvelles migrations (table `devises`, colonne `parametres.devise_id`
  nullable), aucune donnée existante touchée. `DeviseSeeder` crée FCFA/Euro/
  Dollar et active FCFA par défaut si aucune devise n'est déjà configurée.
  - [ ] **Permission à seeder manuellement sur les installs existantes** :
    `devise.gerer` est nouvelle dans `config/permissions.php` — comme pour
    toute nouvelle permission, le rôle Gérant d'une install déjà en
    production ne la reçoit pas automatiquement (`RolePermissionSeeder` ne
    resynchronise que les rôles nouvellement créés). Accorder `devise.gerer`
    au rôle voulu depuis `/roles` si le Gérant doit pouvoir changer la
    devise (sinon seul Superadmin, qui a le bypass total, le peut).
  - [ ] **Tester après déploiement** : dans Administration → Devises,
    vérifier que FCFA/Euro/Dollar sont bien présents ; changer la devise
    active depuis Paramètres → vérifier que le nouveau symbole apparaît
    immédiatement (sans redéploiement) sur le dashboard, un ticket de vente
    existant, ET l'écran de vente en direct (totaux calculés en JS) ; remettre
    FCFA par défaut ensuite.

## 2026-09-01

- [ ] **Nouveau : Prix personnalisé à la vente** — le caissier peut taper un
  prix différent du catalogue sur une ligne (enregistré comme une remise
  "montant" classique, permission `vente.remise` déjà existante). Déploiement
  standard, plus :
  ```
  php artisan migrate --force
  ```
  Une seule migration (`add_prix_personnalise_to_ligne_ventes_table`), une
  colonne booléenne `default(false)` — aucune donnée existante touchée,
  aucune permission nouvelle, rien à cocher sur `/roles`.
  **Tester après déploiement** : sur une vente, choisir "Prix personnalisé"
  dans la colonne Remise du panier, taper un prix inférieur au catalogue →
  vérifier que le ticket/la facture affichent ce prix sans mention de remise.
  Egalement : `npm run build` (passe de style globale — fond blanc des
  cartes, bordure orange des champs, formulaires centrés).

- [ ] **Nouveau : Bon de livraison** (une vente peut être livrée en plusieurs
  fois, suivi ligne par ligne — voir `resources/views/ventes/ticket.blade.php`,
  carte "Livrer des articles"). Déploiement standard, plus :
  ```
  php artisan migrate --force
  php artisan db:seed --class=RolePermissionSeeder --force
  ```
  Les deux migrations créent `bon_livraisons`/`ligne_bon_livraisons` (aucune
  donnée existante touchée). Le seeder enregistre la nouvelle permission
  `vente.livrer` — comme pour toute nouvelle permission (voir
  `GUIDE-MISES-A-JOUR.md`), elle n'est **cochée pour aucun rôle existant**
  automatiquement : l'accorder manuellement sur `/roles` au(x) rôle(s) qui
  doivent pouvoir enregistrer une livraison (ex. Caissier).

- [ ] **Tester après déploiement** : sur une vente existante, enregistrer un
  bon de livraison partiel (carte "Livrer des articles" du ticket) → vérifier
  que le reste à livrer se met à jour, que le PDF individuel du bon
  s'imprime sans prix, et que le badge de statut de livraison apparaît bien
  dans la Facture, le rapport des ventes, la fiche client et le détail de
  session de caisse.

## 2026-08-28

- [ ] **Déployer le code sur le poste serveur** (copie/zip du projet, ou
  `git pull`), puis vider les caches :
  ```
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  ```
  Aucune migration nécessaire.

- [ ] **Coquille desktop** : rien à faire manuellement. Chaque poste (déjà en
  v1.0.7 ou avant) doit se mettre à jour tout seul vers **v1.0.8** au
  prochain démarrage, une fois `public/desktop-updates/` accessible depuis
  le réseau du poste serveur (déjà rempli dans ce dépôt). Vérifier ensuite
  via Fichier → Vérifier les mises à jour… sur un poste si besoin de forcer.

- [ ] **Tester après déploiement** : imprimer une facture, un devis, le
  bouton "Facture" du ticket — doit maintenant ouvrir le PDF réel dans une
  fenêtre de l'app, identique au bouton "Télécharger en PDF".

- [ ] **Nouveau : Guide d'utilisation** — page accessible via le menu
  utilisateur (à côté de "Se déconnecter") → `/guide`. Chaque section n'est
  visible que si l'utilisateur a la permission correspondante (un caissier
  ne voit pas Trésorerie/Administration, par ex.) — vérifier après
  déploiement avec un compte Caissier et un compte Gérant. Rien à seeder,
  juste le déploiement de code ci-dessus. À relire/compléter au fil de l'eau
  si des sections manquent.

- [ ] `RolePermissionSeeder` corrigé pour ne plus écraser les permissions
  personnalisées de Gérant/Caissier — **aucune action requise
  immédiatement**, ce correctif ne change de comportement qu'au **prochain**
  ajout de permission dans `config/permissions.php` (voir
  `GUIDE-MISES-A-JOUR.md`, section dédiée).
