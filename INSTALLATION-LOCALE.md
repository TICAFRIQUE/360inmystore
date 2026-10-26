# Installation locale chez un client

Guide d'installation **sur site**, chez un client (Laragon, poste Windows,
sans hébergement distant). Différent du déploiement cPanel décrit dans
`DEPLOIEMENT.md` — ne pas confondre les deux.

Une fois installée, l'application fonctionne **sans connexion internet au
quotidien** : tout tourne en local (Apache/MySQL/PHP via Laragon), aucune vue
ne charge de ressource externe (pas de CDN, pas d'envoi d'e-mail). Internet
n'est nécessaire qu'**une seule fois**, à l'installation, pour télécharger les
dépendances (`composer install`, `npm install`) — sauf si elles ont été
préparées à l'avance (voir §5).

---

## 1. Prérequis

- **Poste Windows 10/11**, avec droits administrateur (nécessaire pour que
  Laragon puisse écrire dans le fichier `hosts` et créer les virtual hosts).
- **Connexion internet**, au moins le temps de l'installation (voir §5 pour
  s'en passer complètement).
- Si plusieurs caisses doivent utiliser l'application simultanément : les
  postes doivent être sur le **même réseau local** (Wi-Fi/câble du magasin) —
  pas besoin d'internet, juste un réseau local entre les postes.

## 2. Logiciel à installer sur le poste client

- **Laragon** (édition *Full*, qui inclut déjà PHP 8.3+, MySQL, Apache,
  Node.js et Composer) : https://laragon.org/download/
- Lancer Laragon **en tant qu'administrateur** au moins la toute première
  fois (clic droit → Exécuter en tant qu'administrateur) : sans ça, Laragon
  ne peut pas écrire l'entrée nécessaire dans `C:\Windows\System32\drivers\etc\hosts`
  et le virtual host ne sera jamais accessible par son nom.
- Dans **Laragon → Préférences → Général**, vérifier que ces deux options
  sont cochées (cochées par défaut normalement) :
  - **Créer automatiquement des hôtes virtuels**
  - **Tout démarrer automatiquement**

## 3. Copier le projet

Copier tout le dossier du projet **directement** dans `C:\laragon\www\` :

```
C:\laragon\www\360_inmystore\
```

⚠️ **Le dossier doit être directement sous `www\`, jamais imbriqué dans un
sous-dossier** (ex. `C:\laragon\www\clients\360_inmystore` ne
fonctionnera pas) — Laragon ne détecte automatiquement que les dossiers de
premier niveau pour générer leur virtual host.

## 4. Scripts à exécuter

Tout se passe dans le **Terminal Laragon** (clic droit sur l'icône Laragon →
Terminal) — jamais l'invite de commandes Windows classique, qui ne connaît
pas `php`/`composer`/`npm`/`mysql`.

```
cd C:\laragon\www\360_inmystore
scripts\installer.bat
```

`installer.bat` demande 4 informations (URL souhaitée, nom de la base,
utilisateur/mot de passe MySQL — Entrée pour garder les valeurs par défaut si
MySQL local sans mot de passe), puis exécute automatiquement, dans l'ordre :

1. `composer install --no-dev` (dépendances PHP)
2. Configuration du `.env` (via `scripts\configurer-env.ps1`)
3. Création de la base de données MySQL
4. `php artisan migrate --force`
5. `php artisan db:seed --force` (référentiel : rôles, permissions, unités,
   taxes, moyens de paiement — pas de données de démo en production)
6. `npm install` + `npm run build` (assets front)
7. `php artisan storage:link`
8. Mise en cache config/routes/vues
9. Tâche planifiée Windows (`scripts\creer-tache-planifiee.ps1`), qui exécute
   `php artisan schedule:run` chaque minute **sans fenêtre visible**, pour les
   alertes horaires (stock sous seuil, sessions de caisse restées ouvertes)
10. Création du compte **superadmin** (nom, identifiant, code PIN — saisi
    deux fois, en aveugle) : le script **boucle tant que ce compte n'est pas
    réellement créé**, il ne peut plus se terminer en silence sur un échec

À la fin, redémarrer Apache dans Laragon (**Stop All** puis **Start All**)
pour qu'il détecte le nouveau dossier et génère son virtual host.

### Au quotidien

Mettre un raccourci de **`scripts\demarrer-silencieux.vbs`** sur le bureau du
client (clic droit sur le fichier → Envoyer vers → Bureau, créer un
raccourci) : double-clic le matin, aucune fenêtre visible (ni Laragon, ni
invite de commande) — Apache démarre en tâche de fond si besoin, puis le
navigateur s'ouvre directement sur l'application.

`scripts\demarrer.bat` reste disponible en mode visible (fenêtre + messages)
pour déboguer depuis le Terminal Laragon si jamais ça ne s'ouvre pas — c'est
la version que `demarrer-silencieux.vbs` exécute en coulisses.

### Mettre à jour vers une nouvelle version

**Jamais `installer.bat`** sur une installation qui tourne : il réécrit le
`.env`, change la clé de l'application (tout le monde est déconnecté, ce qui
était chiffré devient illisible) et fait créer un nouveau superadmin. Depuis le
Terminal Laragon :

```
cd C:\laragon\www\360_inmystore
scripts\mettre-a-jour.bat
```

Le script s'arrête de lui-même si l'application n'est pas encore installée. Il :

1. récupère la nouvelle version (`git pull` ; si le dossier n'est pas un dépôt
   git, il demande de confirmer que la nouvelle version a déjà été copiée
   par-dessus — sans écraser `.env` ni `storage`) ;
2. **sauvegarde la base** avant toute modification (s'il n'y arrive pas, il
   demande s'il faut continuer sans) ;
3. met l'application en maintenance, efface les caches de l'ancienne version ;
4. installe les dépendances, compile les styles ;
5. passe les migrations, puis `app:apres-mise-a-jour` (permissions nouvelles,
   miniatures de photos) ;
6. remet les caches et rouvre l'application.

En cas d'erreur après l'étape 3, l'application reste en maintenance (rien n'est
à moitié servi) : corriger, puis relancer le script, ou `php artisan up` pour
simplement la rouvrir. Il se relance sans risque : une étape déjà faite ne
change plus rien.

### Alternative : application desktop native (multi-poste réseau)

Pour donner une vraie icône/fenêtre d'application (au lieu d'un raccourci
`.vbs` + onglet de navigateur), et pour équiper plusieurs postes caisse en
réseau local qui se connectent tous au même poste serveur : voir
`desktop/README.md`. C'est une coquille Electron, pas une réécriture — le
poste serveur continue de tourner sur Laragon exactement comme décrit
ci-dessus (`installer.bat` reste l'étape d'installation initiale), les
postes caisse n'installent ni PHP ni MySQL, juste cette coquille pointée sur
l'adresse réseau du serveur.

⚠️ **Ne jamais pointer un poste caisse sur l'IP brute du serveur**
(ex. `http://192.168.1.10`). Les virtual hosts Laragon sont **basés sur le
nom** (`360_inmystore.test`), pas sur l'IP : Apache ne reconnaît le
projet que si la requête arrive avec ce nom, sinon il retombe sur sa page
par défaut, qui liste tous les dossiers de `C:\laragon\www\` (« Index of / »)
— symptôme déjà rencontré. Pour qu'un poste caisse résolve ce nom vers le
poste serveur :

1. Sur le poste caisse, ouvrir le Bloc-notes **en tant qu'administrateur**,
   ouvrir `C:\Windows\System32\drivers\etc\hosts`.
2. Ajouter une ligne (IP réelle du poste serveur) :
   ```
   192.168.1.10    360_inmystore.test
   ```
3. Dans la coquille (Fichier → Paramètres du poste), utiliser
   `http://360_inmystore.test` comme adresse — jamais l'IP brute.

Réserver l'IP du poste serveur côté routeur (bail DHCP fixe) évite d'avoir à
refaire cette entrée `hosts` si l'IP change.

## 5. Installer sans connexion internet du tout

Si le poste client n'a pas internet, ou pour ne pas en dépendre le jour de
l'installation : préparer `vendor/`, `node_modules/` et `public/build/` **à
l'avance**, sur une machine connectée avec la même version de PHP/Node
(`composer install`, puis `npm install && npm run build`), et copier ces
dossiers avec le reste du projet. `installer.bat` s'exécute alors sans jamais
solliciter internet.

## 6. Vérifications post-installation

- [ ] Laragon : pastilles **Apache** et **MySQL** vertes.
- [ ] Virtual host généré :
      `dir C:\laragon\etc\apache2\sites-enabled\auto.360_inmystore.test.conf`
      → doit exister, `DocumentRoot` doit pointer vers `...\public`.
- [ ] Entrée dans le fichier hosts :
      `findstr /i "360_inmystore" C:\Windows\System32\drivers\etc\hosts`
- [ ] `.env` → `APP_URL` correspond **exactement** au nom du dossier + `.test`
      (`findstr APP_URL C:\laragon\www\360_inmystore\.env`).
- [ ] Page accessible dans le navigateur, connexion avec le compte superadmin
      réussie.
- [ ] Tâche planifiée active sans fenêtre qui clignote :
      `Get-ScheduledTaskInfo -TaskName "360inmystore_Schedule"`
      (`LastTaskResult : 0` après une exécution).

## 7. Dépannage — problèmes déjà rencontrés

| Symptôme | Cause probable | Solution |
|---|---|---|
| `ERROR 2003 Can't connect to MySQL server ... (10061)` pendant l'install | MySQL de Laragon pas démarré, souvent un autre MySQL (WampServer, XAMPP...) qui occupe déjà le port 3306 | `netstat -ano \| findstr :3306` pour identifier le conflit ; changer le port MySQL dans Laragon (Préférences → Services & Ports) si besoin |
| Navigateur : "site inaccessible" | Dossier du projet imbriqué (pas directement sous `www\`), OU Apache pas redémarré après la copie, OU entrée `hosts` jamais écrite (Laragon pas lancé en admin) | Vérifier la structure du dossier, faire Stop All/Start All, relancer Laragon en administrateur si l'entrée hosts manque |
| Le compte superadmin ne se retrouve pas en base après l'install | Les deux saisies du code PIN (masquées) ne correspondaient pas, ou identifiant déjà pris — `installer.bat` continuait avant sans le signaler (corrigé : il boucle maintenant jusqu'au succès) | Rejouer uniquement : `php artisan app:creer-superadmin` |
| Code de connexion du superadmin oublié | Le compte Superadmin ne se gère pas depuis l'écran des utilisateurs : personne ne peut lui régénérer un code depuis l'application | `php artisan app:reinitialiser-code superadmin` (demande le nouveau code deux fois, saisie masquée) — fonctionne aussi pour n'importe quel autre compte |
| Une fenêtre noire s'ouvre et se referme chaque minute | Ancienne version de la tâche planifiée (avant le fix du wrapper `.vbs` silencieux) | `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\creer-tache-planifiee.ps1 -PhpExe (Get-Command php).Source -ArtisanPath "C:\laragon\www\360_inmystore\artisan"` |
