# Guide — que faire en cas de mise à jour

Aide-mémoire rapide pour l'architecture **1 poste serveur + plusieurs postes
caisse** (voir `INSTALLATION-LOCALE.md` pour l'installation initiale et
`desktop/README.md` pour le détail technique de la coquille desktop).

Rappel de l'architecture : le projet Laravel complet (Laragon, MySQL, code) ne
vit **que sur le poste serveur**. Les postes caisse n'ont que la coquille
desktop (`G-Stock Quincaillerie Setup X.Y.Z.exe`), sans PHP/MySQL, connectée
au poste serveur par le réseau local. **Dans tous les cas, ton geste se
limite au poste serveur — jamais besoin de toucher les postes caisse un par
un.**

---

## Cas 1 — Changement du code métier (Laravel/Blade)

Le cas courant : nouvelle fonctionnalité, correction de bug, évolution des
règles de gestion. **Uniquement sur le poste serveur** :

1. Remplacer les fichiers du projet par la nouvelle version (nouveau
   zip/copie, ou `git pull` si le poste serveur est géré en git).
2. `composer install` si des dépendances PHP ont changé.
3. `php artisan migrate --force` si de nouvelles migrations.
4. `npm run build` si des assets front (CSS/JS) ont changé.
5. Vider les caches :
   ```
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

→ **Rien à faire sur les postes caisse** : ils affichent la nouvelle version
dès la prochaine page chargée (au pire un Ctrl+R, déjà dans le menu Fichier
de la coquille).

### Nouvelle permission ajoutée (`config/permissions.php`)

En plus des étapes ci-dessus, sur le poste serveur :
```
php artisan db:seed --class=RolePermissionSeeder --force
```
Sûr à relancer : les permissions déjà en base ne sont jamais dupliquées, et
depuis le garde-fou `wasRecentlyCreated` dans `RolePermissionSeeder`, les
rôles Gérant/Caissier (et tout rôle créé à la volée) ne sont **jamais**
réinitialisés s'ils existent déjà — seule la nouvelle permission devient
disponible à cocher manuellement sur `/roles`, rien n'est coché
automatiquement.

## Cas 2 — Changement de la coquille desktop (`desktop/`)

Rare : uniquement si le code de la coquille elle-même change (écran de
config, comportement de la fenêtre, etc.) — jamais pour une évolution de
l'application Laravel.

1. Monter le numéro de version dans `desktop/package.json` (`"version"`).
2. `cd desktop && npm run dist`.
3. Copier ces 3 fichiers depuis `desktop/dist/` vers
   `public/desktop-updates/` **sur le poste serveur**, en écrasant les
   anciens :
   - `G-Stock Quincaillerie Setup X.Y.Z.exe`
   - `latest.yml`
   - `G-Stock Quincaillerie Setup X.Y.Z.exe.blockmap`

→ **Rien à faire sur les postes caisse non plus** : chaque poste (y compris
le serveur, s'il utilise aussi la coquille) détecte et installe la mise à
jour tout seul au prochain démarrage, ou immédiatement via
**Fichier → Vérifier les mises à jour…**.

`public/desktop-updates/` n'est pas versionné dans git (voir `.gitignore`) —
à recréer/recopier à chaque déploiement du projet sur un poste serveur.

---

## Aide-mémoire express

| Qui a changé ? | Où j'agis | Ce que je fais | Postes caisse |
|---|---|---|---|
| Code Laravel/Blade | Poste serveur | `composer install` + `migrate` + `npm run build` + vider caches | Rien |
| Coquille `desktop/` | Poste serveur | `npm run dist` puis copier 3 fichiers dans `public/desktop-updates/` | Rien (auto-update) |

## En cas de souci

- Poste caisse affiche « Serveur injoignable » → vérifier que Laragon tourne
  sur le poste serveur et que les deux postes sont sur le même réseau.
- Sauvegarde/restauration (Paramètres) échoue avec « mysqldump/mysql
  introuvable » → auto-détecté normalement sur une installation Laragon
  standard (`C:\laragon\bin\mysql\*\bin\`) ; sinon renseigner `MYSQLDUMP_PATH`
  et `MYSQL_PATH` dans le `.env` du poste serveur avec le chemin complet vers
  ces deux exécutables.
- Problèmes classiques d'installation (MySQL, virtual host, superadmin,
  tâche planifiée) → voir `INSTALLATION-LOCALE.md` §7 (tableau de
  dépannage).
- Détail technique de la coquille (rôles, config, auto-update) →
  `desktop/README.md`.
