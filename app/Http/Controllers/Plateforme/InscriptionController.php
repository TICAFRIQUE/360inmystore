<?php

namespace App\Http\Controllers\Plateforme;

use App\Http\Controllers\Controller;
use App\Models\Plateforme\AccesEspace;
use App\Models\Plateforme\Entreprise;
use App\Plateforme\AntiRobot;
use App\Plateforme\CreationEntreprise;
use App\Plateforme\LienAcces;
use App\Rules\TelephoneValide;
use App\Support\Telephone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Inscription en libre-service d'une entreprise (domaine principal
 * uniquement) : crée l'espace, sa base et le compte du propriétaire — voir
 * App\Plateforme\CreationEntreprise — puis conduit le propriétaire, déjà
 * connecté, dans son espace pour y choisir sa formule.
 *
 * Chaque inscription crée une base de données : le formulaire est donc
 * protégé contre l'envoi automatisé (AntiRobot), la répétition (limitation
 * par adresse IP sur la route, un espace par numéro de téléphone) et la
 * création en masse (plafonds quotidiens ci-dessous).
 */
class InscriptionController extends Controller
{
    /** Espaces qu'une même adresse IP peut créer par jour (bureau, cybercafé : plusieurs personnes derrière une IP). */
    private const MAX_PAR_IP_PAR_JOUR = 3;

    public function create(Request $request, AntiRobot $antiRobot): View
    {
        abort_unless(config('plateforme.inscription.ouverte'), 404);

        return view('plateforme.inscription', [
            'question' => $antiRobot->preparer($request),
            'domaine' => config('plateforme.domaine'),
        ]);
    }

    public function store(Request $request, AntiRobot $antiRobot, CreationEntreprise $creation, LienAcces $liens): RedirectResponse
    {
        abort_unless(config('plateforme.inscription.ouverte'), 404);

        // Contrôles anti-robot AVANT toute validation : un robot ne doit
        // rien apprendre du formulaire (adresse déjà prise, numéro connu…).
        match ($antiRobot->refus($request)) {
            'reponse' => throw ValidationException::withMessages(['reponse' => "La réponse à la question n'est pas la bonne."]),
            'piege', 'delai' => throw ValidationException::withMessages(['reponse' => "La vérification n'a pas abouti. Répondez à la nouvelle question et renvoyez le formulaire."]),
            null => null,
        };

        $request->merge(['sous_domaine' => strtolower(trim((string) $request->input('sous_domaine')))]);
        Telephone::normaliserDansRequete($request);

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'sous_domaine' => [
                'required', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::notIn(config('plateforme.inscription.adresses_reservees')),
                Rule::unique(Entreprise::class, 'sous_domaine'),
            ],
            'proprietaire_nom' => ['required', 'string', 'max:120'],
            'telephone_pays' => ['required', Rule::in(array_keys(Telephone::PAYS))],
            'telephone' => ['required', 'string', new TelephoneValide, Rule::unique(Entreprise::class, 'telephone')],
            'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash'],
            'mot_de_passe' => ['required', 'confirmed', Password::min(8)],
        ], [
            'sous_domaine.regex' => "L'adresse ne peut contenir que des lettres minuscules, des chiffres et des tirets.",
            'sous_domaine.not_in' => 'Cette adresse est réservée.',
            'sous_domaine.unique' => 'Cette adresse est déjà utilisée par un autre espace.',
            'telephone.unique' => 'Un espace existe déjà pour ce numéro de téléphone.',
        ], [
            'nom' => 'nom du commerce',
            'sous_domaine' => 'adresse de votre espace',
            'proprietaire_nom' => 'votre nom',
            'telephone' => 'téléphone',
            'username' => "nom d'utilisateur",
            'mot_de_passe' => 'mot de passe',
        ]);

        $this->refuserSiPlafondAtteint($request);

        try {
            $entreprise = $creation->creer([
                'nom' => $donnees['nom'],
                'sous_domaine' => $donnees['sous_domaine'],
                'proprietaire_nom' => $donnees['proprietaire_nom'],
                'telephone' => $donnees['telephone'],
                'pays' => $donnees['telephone_pays'],
                'username' => $donnees['username'],
                'mot_de_passe' => $donnees['mot_de_passe'],
                'ip' => $request->ip(),
            ]);
        } catch (RuntimeException) {
            // Détail technique déjà journalisé par CreationEntreprise.
            return back()->withInput($request->except('mot_de_passe', 'mot_de_passe_confirmation'))
                ->with('erreur', "Votre espace n'a pas pu être créé pour le moment. Réessayez dans quelques minutes.");
        }

        // Chaque sous-domaine a ses propres sessions : le propriétaire entre
        // dans son espace par un lien à usage unique, et y arrive connecté.
        return redirect()->away($liens->creer($entreprise, AccesEspace::INSCRIPTION));
    }

    /**
     * Plafonds quotidiens, vérifiés une fois le formulaire valide : global
     * (toutes adresses IP confondues) et par adresse IP.
     */
    private function refuserSiPlafondAtteint(Request $request): void
    {
        $aujourdhui = Entreprise::whereDate('created_at', today());

        $plafondAtteint = (clone $aujourdhui)->count() >= (int) config('plateforme.inscription.max_par_jour')
            || (clone $aujourdhui)->where('ip_inscription', $request->ip())->count() >= self::MAX_PAR_IP_PAR_JOUR;

        if ($plafondAtteint) {
            throw ValidationException::withMessages([
                'nom' => "Le nombre maximal d'inscriptions pour aujourd'hui est atteint. Réessayez demain.",
            ]);
        }
    }
}
