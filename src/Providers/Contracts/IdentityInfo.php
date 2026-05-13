<?php declare(strict_types=1);

namespace App\Providers\Contracts;

/**
 * Vom IdentityProvider nach erfolgreichem Code-Exchange zurueckgegebene
 * normalisierte User-Identitaet. Bewusst provider-unabhaengig — der
 * Aufrufer (AuthController) speichert nur diese Felder.
 *
 * externalOid:
 *  - Microsoft: oid-Claim aus dem id_token (Entra ID Object-ID)
 *  - Google: sub-Claim aus dem id_token (Google numerischer User-ID-String)
 *
 * accessToken: Bei Login eingenommener Delegated-Access-Token. Wird typisch
 * fuer Avatar-Fetch unmittelbar nach Login genutzt. Nicht persistiert.
 */
final class IdentityInfo
{
    public function __construct(
        public readonly string $externalOid,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $accessToken,
    ) {
    }
}
