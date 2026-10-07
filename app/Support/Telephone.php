<?php

namespace App\Support;

use App\Models\Parametre;
use Illuminate\Http\Request;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * Seul endroit qui connaît les règles des numéros de téléphone (voir
 * CLAUDE.md — Téléphone) : pays proposés, validation selon le plan de
 * numérotation de chaque pays (libphonenumber), forme enregistrée et forme
 * affichée. Tout numéro est enregistré au format international E.164
 * (« +2250700000001 ») — une seule écriture par numéro, quel que soit le pays
 * ou la façon dont il a été tapé — et affiché via formater() / le helper
 * telephone().
 */
final class Telephone
{
    /** Pays utilisé tant que le commerce n'a pas réglé le sien (Paramètres). */
    public const PAYS_DEFAUT = 'CI';

    private const INDICATIF_COTE_D_IVOIRE = 225;

    /**
     * Pays proposés dans le sélecteur d'indicatif : toute l'Afrique (code ISO
     * => nom français). Un numéro d'un autre pays reste accepté s'il est tapé
     * en entier avec son « + » (fournisseur à l'étranger, par exemple).
     */
    public const PAYS = [
        'ZA' => 'Afrique du Sud', 'DZ' => 'Algérie', 'AO' => 'Angola', 'BJ' => 'Bénin', 'BW' => 'Botswana',
        'BF' => 'Burkina Faso', 'BI' => 'Burundi', 'CM' => 'Cameroun', 'CV' => 'Cap-Vert', 'CF' => 'Centrafrique',
        'KM' => 'Comores', 'CG' => 'Congo', 'CI' => "Côte d'Ivoire", 'DJ' => 'Djibouti', 'EG' => 'Égypte',
        'ER' => 'Érythrée', 'SZ' => 'Eswatini', 'ET' => 'Éthiopie', 'GA' => 'Gabon', 'GM' => 'Gambie',
        'GH' => 'Ghana', 'GN' => 'Guinée', 'GQ' => 'Guinée équatoriale', 'GW' => 'Guinée-Bissau', 'KE' => 'Kenya',
        'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libye', 'MG' => 'Madagascar', 'MW' => 'Malawi',
        'ML' => 'Mali', 'MA' => 'Maroc', 'MU' => 'Maurice', 'MR' => 'Mauritanie', 'MZ' => 'Mozambique',
        'NA' => 'Namibie', 'NE' => 'Niger', 'NG' => 'Nigeria', 'UG' => 'Ouganda', 'CD' => 'RD Congo',
        'RW' => 'Rwanda', 'ST' => 'Sao Tomé-et-Principe', 'SN' => 'Sénégal', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone',
        'SO' => 'Somalie', 'SD' => 'Soudan', 'SS' => 'Soudan du Sud', 'TZ' => 'Tanzanie', 'TD' => 'Tchad',
        'TG' => 'Togo', 'TN' => 'Tunisie', 'ZM' => 'Zambie', 'ZW' => 'Zimbabwe',
    ];

    /** @var array<int, array{code: string, nom: string, indicatif: string, exemple: string}>|null */
    private static ?array $options = null;

    /**
     * Pays du commerce (Paramètres), qui fixe l'indicatif proposé par défaut
     * dans tous les formulaires.
     */
    public static function paysParDefaut(): string
    {
        // rescue() : ce réglage est lu partout, y compris là où la table des
        // paramètres n'existe pas encore (installation en cours, tests
        // unitaires sans base) — on retombe alors sur le pays par défaut.
        $pays = rescue(fn () => Parametre::actuel()->pays, null, false);

        return is_string($pays) && isset(self::PAYS[$pays]) ? $pays : self::PAYS_DEFAUT;
    }

    /**
     * Numéro au format E.164, ou null s'il est vide ou invalide pour le pays
     * donné. Un numéro tapé avec son « + » est interprété tel quel, quel que
     * soit le pays choisi.
     */
    public static function normaliser(?string $saisie, ?string $pays = null): ?string
    {
        $numero = self::analyser($saisie, $pays);

        return $numero ? self::util()->format($numero, PhoneNumberFormat::E164) : null;
    }

    /**
     * true si la valeur est déjà un numéro international valide (« +… ») —
     * c'est-à-dire exactement ce que normaliser() produit.
     */
    public static function estE164Valide(?string $valeur): bool
    {
        return is_string($valeur) && str_starts_with($valeur, '+') && self::normaliser($valeur) === $valeur;
    }

    /**
     * Forme affichée : « +225 07 00 00 00 01 ». Une valeur qui n'est pas un
     * numéro reconnu (ancienne saisie libre) est rendue telle quelle, jamais
     * masquée ni tronquée.
     */
    public static function formater(?string $valeur): string
    {
        $valeur = trim((string) $valeur);
        $numero = self::analyser($valeur);

        if (! $numero) {
            return $valeur;
        }

        return $numero->getCountryCode() === self::INDICATIF_COTE_D_IVOIRE
            ? '+225 '.self::formeNationale($numero)
            : self::util()->format($numero, PhoneNumberFormat::INTERNATIONAL);
    }

    /**
     * Décompose une valeur enregistrée pour préremplir le champ de saisie
     * (voir x-champ-telephone) : pays à présélectionner + numéro sans
     * indicatif. Un numéro d'un pays absent du sélecteur reste affiché en
     * entier, avec son « + ».
     *
     * @return array{pays: string, numero: string}
     */
    public static function decomposer(?string $valeur): array
    {
        $valeur = trim((string) $valeur);
        $numero = self::analyser($valeur);
        $pays = $numero ? self::util()->getRegionCodeForNumber($numero) : null;

        if (! $numero) {
            return ['pays' => self::paysParDefaut(), 'numero' => $valeur];
        }

        if (! isset(self::PAYS[$pays])) {
            return ['pays' => self::paysParDefaut(), 'numero' => self::formater($valeur)];
        }

        return ['pays' => $pays, 'numero' => self::formeNationale($numero)];
    }

    /**
     * Remplace, dans la requête, le numéro tapé par sa forme E.164 quand il
     * est valide pour le pays choisi (champ « {champ}_pays » du sélecteur).
     * Un numéro invalide est laissé tel quel : la règle TelephoneValide le
     * refuse ensuite, et le formulaire réaffiche ce qui a été tapé.
     */
    public static function normaliserDansRequete(Request $request, string $champ = 'telephone'): void
    {
        $saisie = $request->input($champ);

        if (! is_string($saisie) || trim($saisie) === '') {
            return;
        }

        $pays = $request->input($champ.'_pays');
        $normalise = self::normaliser($saisie, is_string($pays) ? $pays : null);

        if ($normalise !== null) {
            $request->merge([$champ => $normalise]);
        }
    }

    /**
     * Terme de recherche ramené à ses seuls chiffres : « 07 00-00 » retrouve
     * un numéro enregistré « +2250700000001 ». Chaîne vide si le terme ne
     * contient aucun chiffre (recherche par nom).
     */
    public static function pourRecherche(string $terme): string
    {
        return preg_replace('/\D+/', '', $terme) ?? '';
    }

    /**
     * Options du sélecteur de pays, triées par nom : indicatif et exemple de
     * numéro mobile (affiché en indication dans le champ) viennent de
     * libphonenumber, jamais d'une liste recopiée à la main.
     *
     * @return array<int, array{code: string, nom: string, indicatif: string, exemple: string}>
     */
    public static function options(): array
    {
        if (self::$options !== null) {
            return self::$options;
        }

        $options = [];
        foreach (self::PAYS as $code => $nom) {
            $exemple = self::util()->getExampleNumberForType($code, PhoneNumberType::MOBILE);

            $options[] = [
                'code' => $code,
                'nom' => $nom,
                'indicatif' => '+'.self::util()->getCountryCodeForRegion($code),
                'exemple' => $exemple ? self::formeNationale($exemple) : '',
            ];
        }

        return self::$options = $options;
    }

    private static function analyser(?string $saisie, ?string $pays = null): ?PhoneNumber
    {
        $saisie = trim((string) $saisie);

        if ($saisie === '') {
            return null;
        }

        // Un numéro commençant par « + » porte déjà son pays : inutile d'aller
        // lire le pays du commerce (c'est le cas de tout numéro enregistré,
        // donc de chaque affichage dans une liste).
        $region = str_starts_with($saisie, '+') ? null : ($pays ?: self::paysParDefaut());

        try {
            $numero = self::util()->parse($saisie, $region);
        } catch (NumberParseException) {
            return null;
        }

        return self::util()->isValidNumber($numero) ? $numero : null;
    }

    private static function formeNationale(PhoneNumber $numero): string
    {
        $national = self::util()->format($numero, PhoneNumberFormat::NATIONAL);

        // Côte d'Ivoire : numéros à 10 chiffres, écrits et dictés par paires
        // (« 07 00 00 00 01 ») — libphonenumber les regroupe en 2-2-2-4, une
        // forme que personne n'utilise sur place.
        if ($numero->getCountryCode() === self::INDICATIF_COTE_D_IVOIRE) {
            return trim(chunk_split(preg_replace('/\D+/', '', $national), 2, ' '));
        }

        return $national;
    }

    private static function util(): PhoneNumberUtil
    {
        return PhoneNumberUtil::getInstance();
    }
}
