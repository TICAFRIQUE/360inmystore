# 360inmystore Desktop

Coquille desktop (Electron) pour 360inmystore. Ce n'est **pas** une
réécriture de l'application — le code Laravel/Blade/Alpine dans le reste du
dépôt est inchangé et continue de tourner via Laragon comme décrit dans
`INSTALLATION-LOCALE.md`. Cette app n'est qu'une fenêtre native qui affiche
cette même application, à la place d'un onglet de navigateur.

Un seul et même exécutable sert les deux rôles possibles d'un poste, choisi au
premier lancement (menu Fichier → Paramètres du poste pour changer ensuite) :

- **Poste serveur** : démarre Laragon (comme `scripts/demarrer.bat`) puis
  ouvre l'application (`APP_URL` du poste, ex. `http://360_inmystore.test`).
  À installer sur le poste qui héberge réellement Laragon/MySQL.
- **Poste caisse** : se connecte directement à l'adresse réseau du poste
  serveur (ex. `http://192.168.1.10`), sans rien installer d'autre en local.
  Aucune logique métier ni base de données locale sur ce poste — uniquement
  une fenêtre pointée sur le serveur, cohérent avec la contrainte « MVP
  connecté, pas de synchronisation ni de base locale » de `CLAUDE.md`.

## Développement

```bash
cd desktop
npm install
npm start
```

## Build (installateur Windows)

```bash
cd desktop
npm run dist
```

Génère un installateur NSIS dans `desktop/dist/` :
- `360inmystore Setup X.Y.Z.exe` — l'installateur.
- `latest.yml` — le fichier de version consulté par la mise à jour automatique.
- `360inmystore Setup X.Y.Z.exe.blockmap` — utilisé par la mise à jour
  automatique pour ne télécharger que ce qui a changé.

## Mise à jour automatique

Chaque poste (serveur ou caisse) vérifie automatiquement les mises à jour à son
démarrage, en interrogeant `<adresse du poste serveur>/desktop-updates/` — la
même adresse que celle déjà saisie dans l'écran de configuration, donc aucune
config supplémentaire n'est nécessaire. C'est un dossier public standard servi
par Apache (Laragon) sur le poste serveur, pas un service séparé.

**Pour publier une nouvelle version de la coquille** (rare — uniquement si le
code de `desktop/` change, pas pour les mises à jour de l'application Laravel
elle-même, qui ne passent jamais par ce mécanisme) :

1. Monter le numéro de version dans `desktop/package.json` (`"version"`).
2. `npm run dist`.
3. Copier ces 3 fichiers depuis `desktop/dist/` vers
   `public/desktop-updates/` **sur le poste serveur** (écraser les anciens) :
   - `360inmystore Setup X.Y.Z.exe`
   - `latest.yml`
   - `360inmystore Setup X.Y.Z.exe.blockmap`
4. C'est tout — chaque poste (y compris le serveur lui-même, s'il utilise
   aussi la coquille) télécharge et propose l'installation au prochain
   démarrage, sans rien réinstaller manuellement. « Fichier → Vérifier les
   mises à jour… » permet de forcer la vérification sans attendre.

`public/desktop-updates/` n'est pas versionné dans git (voir `.gitignore`) —
ce sont des artefacts de build, pas du code source.

## Ce qui n'est pas géré ici

- Le planificateur horaire (`schedule:run`, alertes stock/sessions) reste
  configuré via `scripts/creer-tache-planifiee.ps1` sur le poste serveur,
  indépendamment de cette app — ne pas dupliquer cette tâche sur les postes
  caisse.
- L'installation de Laragon, la base MySQL, les migrations : toujours via
  `scripts/installer.bat` (voir `INSTALLATION-LOCALE.md`), une seule fois,
  sur le poste serveur.
