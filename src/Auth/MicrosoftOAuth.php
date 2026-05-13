<?php declare(strict_types=1);

namespace App\Auth;

use App\Config;
use League\OAuth2\Client\Provider\GenericProvider;

/**
 * Microsoft-Entra-ID-OAuth2-Wrapper auf Basis von league/oauth2-client.
 *
 * Bewusst kein dediziertes Microsoft-Provider-Package (z.B. thenetworg/oauth2-azure),
 * weil deren transitive firebase/php-jwt-Dependency mit Security-Advisories belegt ist.
 * GenericProvider mit hardcodierten Microsoft-v2-Endpunkten ist schlanker und
 * dependency-frei.
 */
final class MicrosoftOAuth
{
    private GenericProvider $provider;

    public function __construct(
        Config $config,
        private readonly JwksVerifier $jwks,
    ) {
        $tenant = $config->get('OAUTH_TENANT_ID');
        $this->provider = new GenericProvider([
            'clientId' => $config->get('OAUTH_CLIENT_ID'),
            'clientSecret' => $config->get('OAUTH_CLIENT_SECRET'),
            'redirectUri' => $config->get('OAUTH_REDIRECT_URI'),
            'urlAuthorize' => "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize",
            'urlAccessToken' => "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
            'urlResourceOwnerDetails' => 'https://graph.microsoft.com/v1.0/me',
            'scopes' => 'openid profile email offline_access User.Read',
        ]);
    }

    public function authorizationUrl(string $state): string
    {
        // prompt=select_account zwingt den Microsoft-Account-Picker auch dann zu
        // erscheinen, wenn der User schon mit anderen Accounts im Browser eingeloggt
        // ist — sonst nimmt MS automatisch den aktiven Account ohne Rueckfrage.
        return $this->provider->getAuthorizationUrl([
            'state' => $state,
            'prompt' => 'select_account',
        ]);
    }

    /**
     * Tausche OAuth-Code gegen User-Info aus dem id_token. Verifiziert die
     * id_token-Signatur gegen Microsoft-JWKS plus Standard-Claims (iss/aud/exp).
     *
     * @return array{access_token: string, oid: string, email: string, name: string}
     */
    public function exchangeCode(string $code): array
    {
        $token = $this->provider->getAccessToken('authorization_code', ['code' => $code]);
        $values = $token->getValues();
        $idToken = (string) ($values['id_token'] ?? '');
        if ($idToken === '') {
            throw new \RuntimeException('OAuth-Response ohne id_token');
        }
        $claims = $this->jwks->verify($idToken);

        return [
            'access_token' => $token->getToken(),
            'oid' => (string) ($claims['oid'] ?? ''),
            'email' => (string) ($claims['email'] ?? $claims['preferred_username'] ?? ''),
            'name' => (string) ($claims['name'] ?? ''),
        ];
    }
}
