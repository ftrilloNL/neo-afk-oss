<?php declare(strict_types=1);

namespace App\Providers\Contracts;

/**
 * OAuth2-Identitaetsprovider fuer Browser-SSO. Eine Implementierung pro
 * unterstuetztem Backend (Microsoft Entra ID, Google Identity).
 *
 * Lifecycle: AuthController ruft authorizationUrl() im /login-Handler,
 * exchangeCode() im /auth/callback-Handler. Anschliessend wird ggf.
 * fetchAvatar() best-effort aufgerufen.
 */
interface IdentityProvider
{
    /**
     * Eindeutiger Provider-Bezeichner — wird in users.external_provider
     * persistiert und in der UI fuer Login-Buttons genutzt.
     */
    public function name(): string;

    /**
     * URL fuer den Browser-Redirect zum Authorize-Endpoint.
     */
    public function authorizationUrl(string $state): string;

    /**
     * Tauscht den OAuth-Code gegen Access-Token + ID-Token. Validiert die
     * id_token-Signatur gegen die JWKS des Providers und checkt iss/aud/exp.
     *
     * @throws \RuntimeException bei Token-Exchange- oder Signatur-Fehlern
     */
    public function exchangeCode(string $code): IdentityInfo;

    /**
     * Holt das User-Photo des frisch eingeloggten Users. Best-effort: bei
     * Fehlern (kein Photo, API down) null zurueckgeben statt werfen.
     * Returns rohe Photo-Bytes (typisch JPEG/PNG) oder null.
     */
    public function fetchAvatar(string $accessToken): ?string;
}
