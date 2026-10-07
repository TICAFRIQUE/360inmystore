<?php

namespace App\Plateforme;

use Illuminate\Http\Request;

/**
 * Protection du formulaire d'inscription contre les robots, sans service
 * externe (voir PLATEFORME.md, « Inscription et protection contre les robots ») :
 *
 *  - champ piège : invisible pour une personne, rempli par un robot qui
 *    renseigne tous les champs ;
 *  - délai minimal : un robot envoie le formulaire dans la seconde ;
 *  - question simple (addition) dont la réponse n'est connue que de la
 *    session, donc impossible à rejouer d'un envoi à l'autre.
 *
 * Ces trois contrôles arrêtent les robots génériques, pas une attaque écrite
 * exprès pour ce formulaire : la limitation par adresse IP et le plafond
 * quotidien (voir InscriptionController) bornent ce cas, en attendant la
 * vérification du numéro par SMS.
 */
class AntiRobot
{
    public const CHAMP_PIEGE = 'site_web';

    private const CLE = 'inscription.anti_robot';

    /**
     * À appeler à l'affichage du formulaire : pose une nouvelle question et
     * renvoie son énoncé.
     */
    public function preparer(Request $request): string
    {
        $a = random_int(2, 9);
        $b = random_int(1, 9);

        $request->session()->put(self::CLE, ['reponse' => $a + $b, 'affiche_a' => now()->timestamp]);

        return "Combien font {$a} + {$b} ?";
    }

    /**
     * Motif de refus, ou null si l'envoi ressemble à celui d'une personne.
     * La question est consommée dans tous les cas : chaque envoi exige un
     * nouvel affichage du formulaire.
     *
     * @return 'piege'|'delai'|'reponse'|null
     */
    public function refus(Request $request): ?string
    {
        $etat = $request->session()->pull(self::CLE);

        if (filled($request->input(self::CHAMP_PIEGE))) {
            return 'piege';
        }

        if (! is_array($etat) || now()->timestamp - $etat['affiche_a'] < (int) config('plateforme.inscription.delai_minimal')) {
            return 'delai';
        }

        if ((string) $request->input('reponse') !== (string) $etat['reponse']) {
            return 'reponse';
        }

        return null;
    }
}
