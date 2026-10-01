# Déploiement — 360inmystore

Guide de mise en production sur le sous-domaine `g-stock.maxisujets.net`
(hébergement mutualisé cPanel), build et déploiement automatisés via GitHub
Actions + rsync sur SSH.

> Ce guide couvre le déploiement **distant** (hébergement mutualisé,
> accessible depuis internet). Pour une installation **locale chez un
> client** (Laragon, sur site, sans hébergement distant), voir
> `INSTALLATION-LOCALE.md` — une procédure différente, sans lien avec ce
> guide.

---

## 1. Vue d'ensemble

```
push sur main
   │
   ▼
GitHub Actions (job "test")   : installe les dépendances, lance les tests
   │
   ▼
GitHub Actions (job "deploy") : composer install --no-dev, npm run build
   │
   ▼
rsync via SSH → /home1/maxisgwd/repositories/g-stock-vaisselle
   │
   ▼
SSH : migrate --force, config/route/view:cache, storage:link
```

Le code + `vendor/` + les assets compilés (`public/build/`) sont construits
**dans GitHub Actions**, jamais sur le serveur — le mutualisé n'a ni Node.js
ni de version de Composer garantie compatible. Seuls les fichiers finis sont
envoyés par rsync.

Le dépôt est déployé dans `/home1/maxisgwd/repositories/g-stock-vaisselle`,
**pas** directement dans le document root par défaut du sous-domaine
(`/home1/maxisgwd/g-stock.maxisujets.net`) : Laravel ne doit jamais être
servi depuis sa racine (ça exposerait `.env`, `app/`, les migrations). Le
document root du sous-domaine est repointé vers le sous-dossier `public/` de
ce dépôt (§4) — l'URL reste `g-stock.maxisujets.net`.

---

## 2. Secrets GitHub (environnement `PRODUCTION`)

Déjà en place (réutilisés depuis un autre projet sur le même serveur) dans
**Settings > Environments > PRODUCTION** :

| Secret | Valeur attendue |
|---|---|
| `SERVER_HOST` | hôte SSH du serveur |
| `SERVER_USER` | utilisateur cPanel (`maxisgwd`) |
| `SERVER_PATH` | `/home1/maxisgwd/repositories/g-stock-vaisselle` |
| `SSH_PRIVATE_KEY` | clé privée dont la clé publique est dans `~/.ssh/authorized_keys` sur le serveur |

À ajouter si absent (le workflow y a une valeur par défaut de repli à `22`
sinon) :

- `SSH_PORT` — port SSH du serveur, si différent de 22.

Cette clé SSH sert à **GitHub Actions pour se connecter au serveur**
(rsync + commandes artisan à distance) — un usage différent de la clé
« cPanel clone depuis GitHub » évoquée plus tôt dans la conversation ; cette
dernière n'est plus utilisée puisqu'on ne passe plus par Git Version
Control côté cPanel. Réutiliser la même clé/le même compte SSH que l'autre
projet déployé sur ce serveur est correct : c'est le même serveur, le même
utilisateur — le risque à éviter était de partager une clé **entre serveurs/
comptes différents**, pas entre projets d'un même compte.

**Vérifier au préalable** que le compte cPanel a bien SSH activé et que la
clé publique correspondante est dans `~/.ssh/authorized_keys` (normalement
déjà fait puisque réutilisé de l'autre projet).

---

## 3. Créer le dossier de déploiement et le `.env` (une seule fois)

Avant le premier déploiement automatique, préparer le dossier cible en SSH
ou via le Terminal cPanel :

```bash
mkdir -p /home1/maxisgwd/repositories/g-stock-vaisselle
cd /home1/maxisgwd/repositories/g-stock-vaisselle
```

Le tout premier `rsync` du workflow va y déposer les fichiers (le `.env`
n'en fait jamais partie, il est exclu du rsync — voir §1). Il faut donc le
créer manuellement ici, une fois :

1. Copier le contenu de `.env.production.example` (dans le dépôt) dans un
   nouveau fichier `.env` à cet emplacement sur le serveur.
2. Remplir : `APP_URL=https://g-stock.maxisujets.net`,
   `DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` (§5), `MAIL_*` (SMTP réel — voir
   cPanel > Comptes de messagerie, ou un service externe type
   Brevo/Mailgun).
3. Générer la clé d'application :
   ```bash
   php artisan key:generate
   ```

Ce `.env` ne sera plus jamais écrasé par les déploiements suivants (exclu du
rsync, et rsync ne touche pas aux fichiers absents de la source qui sont
explicitement exclus).

---

## 4. Configurer le sous-domaine (document root)

cPanel > **Domaines** > éditer **`g-stock.maxisujets.net`** :

- **Document Root** = `repositories/g-stock-vaisselle/public` (chemin
  relatif au dossier personnel `/home1/maxisgwd/`).

Le site reste accessible sur `g-stock.maxisujets.net` — seul le dossier
physique servi change, ce qui garde `.env` et le code applicatif hors de
portée du web.

---

## 5. Base de données

cPanel > **MySQL Databases** :

1. Créer une base (ex. `360inmystore`) — cPanel préfixera automatiquement par le
   nom du compte (ex. `maxisgwd_360inmystore`).
2. Créer un utilisateur MySQL dédié, mot de passe fort.
3. L'associer à la base avec **tous les privilèges**.
4. Renseigner ces valeurs dans le `.env` du serveur (§3).

---

## 6. Premier déploiement

Une fois §2 à §5 en place :

```bash
git push origin main
```

Ou depuis GitHub : **Actions > Déploiement > Run workflow**.

Le job `test` fait tourner la suite de tests, puis `deploy` :
1. Installe les dépendances de prod (`composer install --no-dev`) et
   construit les assets (`npm run build`).
2. Envoie tout par rsync vers `/home1/maxisgwd/repositories/g-stock-vaisselle`
   (en excluant `.env`, les logs, les sessions/vues en cache, et
   `storage/app/public/` — c'est là que vivent les images produits
   uploadées : il ne faut **jamais** que le déploiement les efface).
3. Se connecte en SSH pour lancer migrations, mise en cache config/route/vue,
   et recréer le lien `storage:link`.

---

## 7. Tâche planifiée (scheduler Laravel)

cPanel > **Cron Jobs** > ajouter, toutes les minutes :

```
* * * * * cd /home1/maxisgwd/repositories/g-stock-vaisselle && php artisan schedule:run >> /dev/null 2>&1
```

Fait tourner les alertes horaires (sessions ouvertes trop longtemps, stock
sous seuil — voir `routes/console.php`).

---

## 8. Checklist de vérification post-déploiement

- [ ] Le site répond en HTTPS sur `https://g-stock.maxisujets.net` (activer
      **AutoSSL** dans cPanel si ce n'est pas déjà fait).
- [ ] `APP_DEBUG=false` confirmé dans le `.env` serveur (sinon les erreurs
      exposent la stack trace au lieu des pages `errors/*.blade.php`).
- [ ] `SESSION_SECURE_COOKIE=true` confirmé dans le `.env` serveur (site en
      HTTPS ci-dessus) — sinon le cookie de session peut être transmis en
      clair.
- [ ] Connexion avec un compte de test réussie.
- [ ] Une vente de test complète fonctionne (session → panier → paiement →
      ticket).
- [ ] Les images de produits s'affichent (`public/storage` est bien un lien
      symbolique vers `storage/app/public`).
- [ ] Un e-mail de test part réellement (création d'un utilisateur) —
      confirme que `MAIL_*` est correctement configuré.
- [ ] Le cron tourne (`Cron Jobs` > vérifier les logs, ou attendre l'heure
      pleine et checker le journal d'activité / les notifications).
- [ ] Un second déploiement (petit commit anodin) ne supprime pas les
      images produits déjà uploadées ni le `.env`.

---

## Déploiements suivants

Chaque push sur `main` déclenche automatiquement tests → build → rsync →
migrations/caches. Rien de manuel à refaire côté serveur.
