# Plateforme SaaS — 360inmystore (branche `multi-tenant`)

Cette branche est la plateforme en ligne : un site vitrine, une inscription en
libre-service, un espace par entreprise cliente, et l'espace de l'éditeur pour
piloter le tout. Elle est **toujours multi-client** — il n'y a aucun
interrupteur à régler.

La version installée (un commerce par déploiement, hébergement mutualisé ou
VPS, personnalisable client par client) vit sur la branche `mono_client`.

| | `mono_client` | `multi-tenant` (cette branche) |
|---|---|---|
| Usage | Un commerce par déploiement | Tous les clients sur une plateforme |
| Bases de données | Une seule | Une pour la plateforme + une par entreprise |
| Adresse | Celle du site | `{espace}.{domaine}` par entreprise ; le domaine nu pour la plateforme |
| Abonnement | Activé à la main dans l'installation | Formule choisie par le client ; suivi dans l'espace éditeur |
| Code sur mesure par client | Oui | Non — une seule version pour tous |

---

## 1. Le parcours d'un client

1. Il arrive sur le **site vitrine** (`360inmystore.com`) : présentation et
   tarifs — les tarifs sont les formules que vous gérez (§ 4).
2. **Créer un compte** : nom du commerce, adresse de son espace, son nom, son
   téléphone, un nom d'utilisateur et un mot de passe.
3. Son espace est créé (base de données dédiée) et il y arrive **déjà
   connecté**, sur le **choix de sa formule**.
4. Il choisit : la formule gratuite (l'essai, s'il en existe une) ou une
   formule payante. Son espace s'ouvre aussitôt.

Tant qu'aucune formule n'est en cours — jamais choisie, ou expirée — l'espace
se réduit à la page des formules.

## 2. Votre espace d'éditeur

Connectez-vous sur le domaine principal (`/login`) avec votre compte :

- **Tableau de bord** : nombre de clients, nouvelles inscriptions,
  abonnements en cours, formules non choisies, abonnements expirés, échéances
  de la semaine, montant encaissé.
- **Entreprises** : tous vos clients. Pour chacun :
  - *Activer un abonnement* — un client vous a payé en direct (espèces,
    Mobile Money…) ou vous lui offrez des jours : le paiement est inscrit au
    registre et son espace est prolongé ;
  - *Ouvrir l'espace (maintenance)* — vous entrez dans son espace sans son mot
    de passe ; un bandeau le rappelle à l'écran et l'accès est inscrit dans
    son journal d'activité ;
  - *Suspendre / Réactiver* — plus personne ne peut s'y connecter ; ses
    données sont conservées.
- **Paiements** : le registre de tous les paiements (en ligne, enregistrés à
  la main, simulés ; réussis ou refusés), filtrable et exportable.
- **Formules d'abonnement** : vos tarifs.

Les écrans de gestion d'un commerce (ventes, stock…) n'existent pas sur le
domaine principal : sa base ne contient les données d'aucun commerce.

## 3. Paiement — SIMULATION

Aucun prestataire de paiement n'est branché. Quand un client « paie » une
formule depuis son espace, **rien n'est encaissé** : son abonnement est
prolongé gratuitement. C'est rappelé en permanence sur votre tableau de bord,
et ces paiements simulés sont exclus des montants « encaissés ».

En attendant un vrai prestataire, la voie normale est : le client vous paie en
direct, et vous utilisez **Entreprises → Activer un abonnement**.

Pour brancher un prestataire : écrire une classe qui implémente
`App\Plateforme\Paiement\PasserellePaiement` et la déclarer dans
`PlateformeServiceProvider`. Le reste ne change pas.

## 4. Formules et tarifs

Tout se règle dans **Formules d'abonnement** :

- nom, prix, durée (ou « sans limite de durée ») ;
- phrase d'accroche, liste de ce que la formule inclut (une ligne par
  élément), mise en avant sur la page des tarifs ;
- *Retirer* une formule l'enlève de la page des tarifs sans toucher aux
  clients qui l'ont déjà souscrite.

Une formule **à 0 F est l'essai gratuit** : un client ne peut la prendre
qu'une fois, au démarrage de son espace. Sans formule gratuite, il doit payer
pour entrer. Sans aucune formule, personne ne peut entrer.

## 5. Hébergement

1. **Sous-domaines génériques** : un enregistrement DNS `*.votre-domaine` et
   un hôte virtuel qui envoie tous les sous-domaines vers le dossier `public`
   (avec un certificat HTTPS générique `*.votre-domaine`).
2. **Droit de créer des bases** pour le compte MySQL de l'application :

   ```sql
   GRANT ALL PRIVILEGES ON `ims\_%`.* TO 'utilisateur'@'localhost';
   GRANT CREATE ON *.* TO 'utilisateur'@'localhost';
   ```

3. **Processus en ligne de commande** autorisés (`proc_open`) : la préparation
   d'un espace lance `php artisan` dans un processus séparé.

Un hébergement mutualisé cPanel ne remplit généralement pas ces conditions :
prévoir un VPS. (La branche `mono_client`, elle, tourne en mutualisé.)

## 6. Mise en place

Rien de spécifique à la plateforme n'est à écrire dans `.env` : le domaine est
celui de `APP_URL`, et `DB_DATABASE` est la base de la plateforme.

```
php artisan migrate --force
php artisan db:seed --class=ReferentielSeeder --force
php artisan app:creer-superadmin         # votre compte d'éditeur
php artisan config:cache
```

Puis créez vos formules depuis votre espace.

### En local

Chrome et Edge résolvent tout seuls `*.localhost`. Avec `APP_URL=http://localhost`
et une base réservée à la plateforme dans `DB_DATABASE` :

```
php artisan serve
```

La plateforme répond sur `http://localhost:8000`, un espace sur
`http://ma-boutique.localhost:8000`.

Conseil : gardez **un dossier par branche** (par exemple avec `git worktree`),
chacun avec son propre `.env` — la base de la version installée et celle de la
plateforme ne doivent pas être la même.

## 7. Mises à jour

Les migrations doivent passer sur la plateforme **et** sur chaque entreprise :

```
php artisan migrate --force
php artisan entreprises:executer -- migrate --force
```

`entreprises:executer` lance la commande pour chaque entreprise active, l'une
après l'autre, et se termine en erreur si une seule échoue (`--toutes` avant
`--` pour inclure les entreprises suspendues). Le planificateur
(`schedule:run`) ne tourne que sur la plateforme : il lance lui-même les
alertes de chaque entreprise.

### Sauvegardes

Le planificateur sauvegarde chaque soir la base de la plateforme (23 h 30) puis celle
de chaque entreprise (23 h 40), dans `storage/app/sauvegardes/{base}` ; les 10 plus
récentes sont gardées. Il faut donc que `schedule:run` tourne chaque minute, et que
`mysqldump` et `mysql` soient accessibles (sinon, `MYSQLDUMP_PATH` et `MYSQL_PATH`
dans `.env`). Pour lancer une sauvegarde à la main :

```
php artisan app:sauvegarder                                  # la plateforme
php artisan entreprises:executer --toutes app:sauvegarder    # chaque entreprise
```

La restauration d'une entreprise se fait depuis votre espace : Entreprises →
« Sauvegardes et restauration ». L'état actuel est sauvegardé juste avant. Ce dossier
est sur le serveur lui-même : copiez-le régulièrement ailleurs (autre disque, autre
machine), une panne du serveur emporterait sinon les sauvegardes avec les bases.

### Code de connexion oublié

```
php artisan app:reinitialiser-code superadmin          # votre compte d'éditeur
```

Pour le compte d'un client qui a perdu le sien, la même commande se lance dans
son espace, désigné par son sous-domaine (PowerShell, puis variable à retirer) :

```
$env:PLATEFORME_ENTREPRISE = 'sous-domaine'
php artisan app:reinitialiser-code nom-utilisateur
Remove-Item Env:PLATEFORME_ENTREPRISE
```

Le nouveau code est demandé deux fois, saisie masquée, et l'action est tracée
dans l'espace concerné.

Un push sur GitHub ne déploie rien (voir `.github/workflows/deploy.yml`) ; le
déploiement automatisé existant vise l'hébergement de la version installée et
refuse cette branche.

## 8. Inscription et protection contre les robots

Chaque inscription crée une base de données ; le formulaire est donc protégé
sans dépendre d'un service externe :

- champ piège invisible, délai minimal de remplissage, question simple dont la
  réponse n'est valable qu'une fois ;
- 10 envois par heure et par adresse IP ;
- 3 espaces par jour et par adresse IP, 50 par jour au total ;
- un seul espace par numéro de téléphone ; adresses réservées refusées
  (`www`, `admin`, `api`…).

Ces protections arrêtent les robots génériques. Elles ne prouvent pas que le
numéro saisi appartient à la personne : cela demandera un code envoyé par SMS
ou WhatsApp.

Réglages facultatifs (variables d'environnement, toutes avec une valeur par
défaut) : `PLATEFORME_INSCRIPTION_OUVERTE`, `PLATEFORME_INSCRIPTIONS_MAX_PAR_JOUR`,
`PLATEFORME_PREFIXE_BASE`, `PLATEFORME_PHP`.

## 9. Faire évoluer les deux branches

Le métier (vente, stock, caisse, comptes) est le même des deux côtés. Pour
qu'il le reste :

- une correction ou une évolution du métier se fait dans `mono_client` ;
- puis `mono_client` est fusionnée dans `multi-tenant` ;
- **jamais l'inverse** : aucun code de plateforme ne doit arriver dans la
  version installée.

## 10. Ce qui reste à faire avant une mise en vente

- Vrai prestataire de paiement (§ 3).
- Vérification du téléphone par code SMS/WhatsApp à l'inscription.
- Les comptes créés par un propriétaire pour ses employés reçoivent encore un
  code à 4 chiffres : faible sur Internet.
- Sauvegardes automatiques de toutes les bases, hors du serveur.
- Suppression définitive d'un espace (aujourd'hui : suspension seulement).
- Limites par formule (nombre de magasins, d'utilisateurs, de caisses) : les
  formules ne diffèrent aujourd'hui que par leur prix et leur durée.
