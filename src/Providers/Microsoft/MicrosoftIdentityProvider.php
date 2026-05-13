<?php declare(strict_types=1);

namespace App\Providers\Microsoft;

use App\Auth\JwksVerifier;
use App\Config;
use App\Providers\Contracts\IdentityInfo;
use App\Providers\Contracts\IdentityProvider;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use League\OAuth2\Client\Provider\GenericProvider;

/**
 * Microsoft-Entra-ID-Identity-Provider. Authorization-Code-Flow gegen die
 * v2.0-Endpoints, ID-Token-Verifikation gegen die tenant-spezifischen JWKS.
 *
 * Bewusst kein dediziertes Microsoft-Provider-Package (z.B. thenetworg/oauth2-azure),
 * weil deren transitive firebase/php-jwt-Dependency mit Security-Advisories belegt
 * ist. GenericProvider mit hardcodierten Microsoft-v2-Endpunkten ist schlanker und
 * dependency-frei.
 */
final class MicrosoftIdentityProvider implements IdentityProvider
{
    private GenericProvider $oauth;
    private JwksVerifier $jwks;

    public function __construct(
        Config $config,
        private readonly ClientInterface $http = new HttpClient(['timeout' => 10]),
    ) {
        $tenant = $config->get('OAUTH_TENANT_ID');
        $clientId = $config->get('OAUTH_CLIENT_ID');
        $this->oauth = new GenericProvider([
            'clientId' => $clientId,
            'clientSecret' => $config->get('OAUTH_CLIENT_SECRET'),
            'redirectUri' => $config->get('OAUTH_REDIRECT_URI'),
            'urlAuthorize' => "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize",
            'urlAccessToken' => "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
            'urlResourceOwnerDetails' => 'https://graph.microsoft.com/v1.0/me',
            'scopes' => 'openid profile email offline_access User.Read',
        ]);
        $this->jwks = new JwksVerifier(
            jwksUrl: "https://login.microsoftonline.com/{$tenant}/discovery/v2.0/keys",
            acceptedIssuers: ["https://login.microsoftonline.com/{$tenant}/v2.0"],
            expectedAudience: $clientId,
            http: $http,
        );
    }

    public function name(): string
    {
        return 'microsoft';
    }

    public function authorizationUrl(string $state): string
    {
        // prompt=select_account zwingt den Microsoft-Account-Picker auch dann zu
        // erscheinen, wenn der User schon mit anderen Accounts im Browser eingeloggt
        // ist — sonst nimmt MS automatisch den aktiven Account ohne Rueckfrage.
        return $this->oauth->getAuthorizationUrl([
            'state' => $state,
            'prompt' => 'select_account',
        ]);
    }

    public function exchangeCode(string $code): IdentityInfo
    {
        $token = $this->oauth->getAccessToken('authorization_code', ['code' => $code]);
        $values = $token->getValues();
        $idToken = (string) ($values['id_token'] ?? '');
        if ($idToken === '') {
            throw new \RuntimeException('OAuth-Response ohne id_token');
        }
        $claims = $this->jwks->verify($idToken);

        return new IdentityInfo(
            externalOid: (string) ($claims['oid'] ?? ''),
            email: (string) ($claims['email'] ?? $claims['preferred_username'] ?? ''),
            displayName: (string) ($claims['name'] ?? ''),
            accessToken: $token->getToken(),
        );
    }

    public function fetchAvatar(string $accessToken): ?string
    {
        try {
            $response = $this->http->request('GET', 'https://graph.microsoft.com/v1.0/me/photo/$value', [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            ]);
            $body = (string) $response->getBody();
            return $body !== '' ? $body : null;
        } catch (GuzzleException) {
            return null;
        }
    }
}
