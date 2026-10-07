<?php

namespace App\Http\Controllers;

use App\Models\Devise;
use App\Models\Parametre;
use App\Rules\TelephoneValide;
use App\Support\Telephone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;

class ParametreController extends Controller
{
    public function edit(): View
    {
        return view('parametres.edit', [
            'parametre' => Parametre::actuel(),
            'devises' => Devise::where('actif', true)->orderBy('nom')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Telephone::normaliserDansRequete($request, 'numero');

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'slogan' => ['nullable', 'string', 'max:255'],
            'couleur_primaire' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'numero' => ['nullable', 'string', 'max:50', new TelephoneValide],
            'pays' => ['required', Rule::in(array_keys(Telephone::PAYS))],
            'adresse' => ['nullable', 'string', 'max:255'],
            'duree_validite_devis_jours' => ['required', 'integer', 'min:1', 'max:365'],
            'devise_id' => ['nullable', 'exists:devises,id'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $parametre = Parametre::actuel();
        unset($donnees['logo']);
        $parametre->update($donnees);

        if ($request->hasFile('logo')) {
            $parametre->addMediaFromRequest('logo')->toMediaCollection('logo');
            Parametre::invaliderCache();
        }

        return redirect()->route('parametres.edit')->with('succes', 'Paramètres mis à jour.');
    }

    /**
     * Champ isolé du formulaire principal (update() ci-dessus) : validé/
     * enregistré indépendamment pour ne jamais réclamer nom/slogan/etc. juste
     * pour changer ce seul réglage.
     */
    public function mettreAJourDossierSauvegarde(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'dossier_sauvegarde' => ['nullable', 'string', 'max:500'],
        ]);

        $dossier = $donnees['dossier_sauvegarde'] ? rtrim($donnees['dossier_sauvegarde'], '\\/') : null;

        if ($dossier && (! is_dir($dossier) || ! is_writable($dossier))) {
            return back()->with('erreur', "Le dossier « {$dossier} » n'existe pas ou n'est pas accessible en écriture depuis le serveur.");
        }

        Parametre::actuel()->update(['dossier_sauvegarde' => $dossier]);

        return back()->with('succes', $dossier
            ? "Dossier de sauvegarde enregistré : chaque sauvegarde y sera désormais directement écrite (plus de téléchargement navigateur)."
            : 'Dossier de sauvegarde retiré : une sauvegarde sera de nouveau proposée en téléchargement.');
    }

    /**
     * Sauvegarde à la demande : dump mysqldump complet (voir executerMysqldump()).
     * Si un dossier de sauvegarde est configuré (Paramètres), le fichier y est
     * écrit directement et y reste (pas de téléchargement) — pratique sur une
     * installation locale où le serveur et le poste sont la même machine.
     * Sinon, comportement d'origine : téléchargement navigateur d'un fichier
     * temporaire, supprimé du serveur juste après l'envoi.
     */
    public function backup(): mixed
    {
        $connexion = config('database.connections.'.config('database.default'));

        if (($connexion['driver'] ?? null) !== 'mysql') {
            return back()->with('erreur', 'Sauvegarde uniquement disponible pour une base MySQL.');
        }

        $dossier = Parametre::actuel()->dossier_sauvegarde;

        try {
            $cheminFichier = $this->executerMysqldump($connexion, $dossier, 'sauvegarde-');
        } catch (ProcessExceptionInterface|\RuntimeException $e) {
            Log::error('Échec de la sauvegarde base de données : '.$e->getMessage());

            return back()->with('erreur', $this->messageErreurMysql($e, 'sauvegarde'));
        }

        if ($this->dossierValide($dossier)) {
            return back()->with('succes', "Sauvegarde enregistrée : {$cheminFichier}");
        }

        $reponse = response()->download($cheminFichier, basename($cheminFichier))->deleteFileAfterSend(true);

        // Un téléchargement de fichier ne recharge jamais la page : le spinner
        // générique du bouton (voir app.js) ne se réinitialiserait donc jamais
        // tout seul. Ce cookie, sondé côté client (document.cookie), sert de
        // signal de fin — httpOnly: false est indispensable, sinon le JS ne
        // peut pas le lire du tout (valeur par défaut de Cookie : true).
        $reponse->headers->setCookie(new Cookie(
            name: 'telechargement_pret',
            value: '1',
            expire: time() + 60,
            path: '/',
            httpOnly: false,
        ));

        return $reponse;
    }

    /**
     * Restauration depuis un fichier .sql importé : remplace intégralement le
     * contenu de la base actuelle (un export mysqldump standard contient ses
     * propres DROP TABLE/CREATE TABLE). Irréversible côté base — d'où la
     * sauvegarde de sécurité automatique juste avant, sur laquelle se
     * rabattre si le fichier importé s'avère mauvais.
     */
    public function restaurer(Request $request): RedirectResponse
    {
        $connexion = config('database.connections.'.config('database.default'));

        if (($connexion['driver'] ?? null) !== 'mysql') {
            return back()->with('erreur', 'Restauration uniquement disponible pour une base MySQL.');
        }

        if (! $request->hasFile('fichier')) {
            // PHP abandonne silencieusement tout le POST (donc $_FILES vide,
            // pas d'erreur de validation exploitable) quand post_max_size est
            // dépassé — CONTENT_LENGTH renseigné malgré tout permet de
            // distinguer ce cas d'un simple oubli de fichier.
            if ((int) $request->server('CONTENT_LENGTH', 0) > 0) {
                return back()->with('erreur', "Le fichier est trop volumineux pour ce serveur (limite post_max_size/upload_max_filesize de PHP). Augmentez ces limites dans php.ini, puis redémarrez le serveur web avant de réessayer.");
            }

            return back()->with('erreur', 'Aucun fichier de sauvegarde sélectionné.');
        }

        $request->validate([
            'fichier' => ['required', 'file', 'max:512000', 'extensions:sql'],
        ]);

        $dossier = ($parametre = Parametre::actuel())->dossier_sauvegarde;

        try {
            $sauvegardeSecurite = $this->executerMysqldump($connexion, $dossier, 'avant-restauration-');
        } catch (ProcessExceptionInterface|\RuntimeException $e) {
            Log::error('Restauration annulée, sauvegarde de sécurité impossible : '.$e->getMessage());

            return back()->with('erreur', "La restauration a été annulée : impossible de créer une sauvegarde de sécurité de la base actuelle avant de l'écraser. ".$this->messageErreurMysql($e, 'sauvegarde'));
        }

        $commande = [
            $this->resoudreCheminBinaire('mysql', config('database.mysql_path')),
            '-h', (string) $connexion['host'],
            '-P', (string) $connexion['port'],
            '-u', (string) $connexion['username'],
            (string) $connexion['database'],
        ];

        // Même principe que la sauvegarde : le mot de passe passe par l'env
        // du sous-processus (MYSQL_PWD), jamais en argument de ligne de
        // commande.
        $env = getenv() + ['MYSQL_PWD' => (string) $connexion['password']];
        $process = new Process($commande, env: $env);
        $process->setTimeout(null);
        // Fichier passé en entrée standard plutôt que chargé en mémoire —
        // un dump peut peser plusieurs centaines de Mo. getPathname() plutôt
        // que getRealPath() : sous Windows/Laragon, la résolution du chemin
        // réel du fichier temporaire uploadé peut échouer (retourne false),
        // ce que fopen() refuse ("Path cannot be empty") — getPathname()
        // renvoie le chemin tel que PHP l'a créé, sans tentative de résolution.
        $process->setInput(fopen($request->file('fichier')->getPathname(), 'r'));

        try {
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \RuntimeException($process->getErrorOutput() ?: 'mysql a échoué sans message d\'erreur.');
            }
        } catch (ProcessExceptionInterface|\RuntimeException $e) {
            Log::error('Échec de la restauration base de données : '.$e->getMessage());

            return back()->with('erreur', $this->messageErreurMysql($e, 'restauration')." La sauvegarde de sécurité de l'état précédent reste disponible : {$sauvegardeSecurite}.");
        }

        // Toute la base a potentiellement changé sous les pieds de
        // l'application : caches applicatifs (Parametre/Devise) et cache de
        // rôles/permissions Spatie invalidés pour ne rien servir de périmé.
        Parametre::invaliderCache();
        Devise::invaliderCache();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::flush();

        activity()
            ->causedBy($request->user())
            ->performedOn($parametre)
            ->withProperties(['sauvegarde_securite' => $sauvegardeSecurite])
            ->log('Base de données restaurée depuis un fichier importé.');

        return back()->with('succes', "Restauration terminée avec succès. Une sauvegarde de l'état précédent (avant restauration) a été conservée : {$sauvegardeSecurite}.");
    }

    /**
     * Exécute mysqldump et écrit son résultat dans le dossier de sauvegarde
     * configuré (Paramètres) s'il est valide, sinon dans un fichier temporaire
     * (jamais gardé entièrement en mémoire). Réutilisée par backup() et par
     * la sauvegarde de sécurité automatique de restaurer() ci-dessus — un
     * seul endroit qui sait construire cette commande.
     *
     * @return string chemin complet du fichier .sql créé.
     *
     * @throws ProcessExceptionInterface|\RuntimeException si mysqldump échoue.
     */
    private function executerMysqldump(array $connexion, ?string $dossier, string $prefixe): string
    {
        $nomFichier = $prefixe.now()->format('Y-m-d-His').'.sql';
        $cheminFichier = $this->dossierValide($dossier)
            ? rtrim($dossier, '\\/').DIRECTORY_SEPARATOR.$nomFichier
            : tempnam(sys_get_temp_dir(), 'backup_');

        $commande = [
            $this->resoudreCheminBinaire('mysqldump', config('database.mysqldump_path')),
            '-h', (string) $connexion['host'],
            '-P', (string) $connexion['port'],
            '-u', (string) $connexion['username'],
            '--single-transaction',
            '--routines',
            (string) $connexion['database'],
        ];

        // Symfony Process remplace tout l'environnement du sous-processus quand
        // $env est fourni : sans PATH/SystemRoot hérités, mysqldump.exe échoue
        // à initialiser Winsock sous Windows. On fusionne donc avec l'environnement
        // courant plutôt que de le remplacer.
        $env = getenv() + ['MYSQL_PWD' => (string) $connexion['password']];
        $process = new Process($commande, env: $env);
        $process->setTimeout(null);

        $handle = fopen($cheminFichier, 'w');
        $process->run(function (string $type, string $buffer) use ($handle) {
            if ($type === Process::OUT) {
                fwrite($handle, $buffer);
            }
        });
        fclose($handle);

        if (! $process->isSuccessful()) {
            @unlink($cheminFichier);
            throw new \RuntimeException($process->getErrorOutput() ?: 'mysqldump a échoué sans message d\'erreur.');
        }

        return $cheminFichier;
    }

    private function dossierValide(?string $dossier): bool
    {
        return $dossier && is_dir($dossier) && is_writable($dossier);
    }

    /**
     * Message affiché à l'utilisateur, adapté selon que l'exécutable
     * (mysqldump/mysql) est introuvable (cas fréquent sur une installation
     * Laragon dont mysqldump/mysql.exe n'est pas sur le PATH global Windows)
     * ou une autre erreur (identifiants invalides, base injoignable...).
     */
    private function messageErreurMysql(\Throwable $e, string $action): string
    {
        $executable = $action === 'sauvegarde' ? 'mysqldump' : 'mysql';
        $variable = $action === 'sauvegarde' ? 'MYSQLDUMP_PATH' : 'MYSQL_PATH';

        $introuvable = str_contains($e->getMessage(), 'introuvable')
            || str_contains($e->getMessage(), 'not found')
            || str_contains($e->getMessage(), 'not recognized');

        if (! $introuvable) {
            return "La {$action} a échoué : {$executable} n'est pas disponible ou a rencontré une erreur. Vérifiez sa présence sur le serveur.";
        }

        return "La {$action} a échoué : {$executable} est introuvable sur ce serveur. Sur une installation Laragon, renseignez son chemin complet (ex. C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\{$executable}.exe) dans {$variable} du fichier .env, puis réessayez.";
    }

    /**
     * Résout le chemin d'un exécutable MySQL (mysqldump ou mysql) : la config
     * explicite (MYSQLDUMP_PATH/MYSQL_PATH, .env) prime si renseignée ; sinon
     * tente le dossier Laragon standard (C:\laragon\bin\mysql\*\bin\ — le
     * numéro de version change à chaque mise à jour de Laragon, d'où le glob)
     * sur une installation Windows ; sinon retombe sur le simple nom, résolu
     * via le PATH s'il y figure (comportement d'origine, marche en général
     * sur un vrai serveur Linux où l'exécutable est bien sur le PATH).
     */
    private function resoudreCheminBinaire(string $executable, ?string $configure): string
    {
        if ($configure) {
            return $configure;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $trouves = glob("C:\\laragon\\bin\\mysql\\*\\bin\\{$executable}.exe");
            if (! empty($trouves)) {
                // Le plus récent en cas de plusieurs versions installées côte
                // à côte (mise à jour Laragon sans suppression de l'ancienne).
                rsort($trouves);

                return $trouves[0];
            }
        }

        return $executable;
    }
}
