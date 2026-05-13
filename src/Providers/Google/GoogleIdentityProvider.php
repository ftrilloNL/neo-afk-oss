<?php declare(strict_types=1);

namespace App\Providers\Google;

use App\Auth\JwksVerifier;
use App\Config;
use App\Providers\Contracts\IdentityInfo;
use App\Providers\Contracts\IdentityProvider;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use League\OAuth2\Client\Provider\GenericProvider;

/**
 * Google-Identity-Provider fuer Browser-SSO (Authorization-Code-Flow).
 * Nutzt nur die nicht-sensitiven Login-Scopes — fuer Calendar/Mail/OOO
 * laeuft alles via Service-Account-DWD, nicht ueber den User-Login-Token.
 *
 * ID-Token-Verifikation: JWKS unter https://www.googleapis.com/oauth2/v3/certs.
 * Erwarteter Issuer: https://accounts.google.com (oder accounts.google.com).
 *
 * Optional: GOOGLE_WORKSPACE_DOMAIN als hosted-domain-Check (hd-Claim) gegen
 * Login mit privaten Gmail-Accounts.
 */
final class GoogleIdentityProvider implements IdentityProvider
{
    private GenericProvider $oauth;
    private JwksVerifier $jwks;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http = new HttpClient(['timeout' => 10]),
    ) {
        $clientId = $config->get('OAUTH_CLIENT_ID');
        $this->oauth = new GenericProvider([
            'clientId' => $clientId,
            'clientSecret' => $config->get('OAUTH_CLIENT_SECRET'),
            'redirectUri' => $config->get('OAUTH_REDIRECT_URI'),
            'urlAuthorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'urlAccessToken' => 'https://oauth2.googleapis.com/token',
            'urlResourceOwnerDetails' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scopes' => 'openid email profile',
        ]);
        $this->jwks = new JwksVerifier(
            jwksUrl: 'https://www.googleapis.com/oauth2/v3/certs',
            acceptedIssuers: ['https://accounts.google.com', 'accounts.google.com'],
            expectedAudience: $clientId,
            http: $http,
        );
    }

    public function name(): string
    {
        return 'google';
    }

    public function authorizationUrl(string $state): string
    {
        $params = [
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ];
        // Optional: hd-param zwingt den Account-Picker nur Accounts der Workspace-Domain
        // anzuzeigen. Bei Setup-Wizard noch nicht gesetzt — dann offen lassen.
        $domain = (string) $this->config->get('GOOGLE_WORKSPACE_DOMAIN', '');
        if ($domain !== '') {
            $params['hd'] = $domain;
        }
        return $this->oauth->getAuthorizationUrl($params);
    }

    public function exchangeCode(string $code): IdentityInfo
    {
        $token = $this->oauth->getAccessToken('authorization_code', ['code' => $code]);
        $values = $token->getValues();
        $idToken = (string) ($values['id_token'] ?? '');
        if ($idToken === '') {
            throw new \RuntimeException('Google OAuth-Response ohne id_token');
        }
        $claims = $this->jwks->verify($idToken);

        // Optionaler Domain-Check — wenn GOOGLE_WORKSPACE_DOMAIN gesetzt, muessen
        // sich Logins von dieser Workspace-Domain ausweisen (hd-Claim).
        $expectedDomain = (string) $this->config->get('GOOGLE_WORKSPACE_DOMAIN', '');
        if ($expectedDomain !== '') {
            $hd = (string) ($claims['hd'] ?? '');
            if ($hd !== $expectedDomain) {
                throw new \RuntimeException(sprintf(
                    'Login von fremder Workspace-Domain abgelehnt: hd=%s, erwartet=%s',
                    $hd,
                    $expectedDomain,
                ));
            }
        }

        return new IdentityInfo(
            externalOid: (string) ($claims['sub'] ?? ''),
            email: (string) ($claims['email'] ?? ''),
            displayName: (string) ($claims['name'] ?? ''),
            accessToken: $token->getToken(),
        );
    }

    public function fetchAvatar(string $accessToken): ?string
    {
        // Google legt die Photo-URL als picture-Claim ins ID-Token. Wir koennten
        // den dort lesen, aber der Provider-Contract ist accessToken-basiert.
        // Pragmatisch: People API mit dem Login-Token abfragen.
        try {
            $response = $this->http->request(
                'GET',
                'https://people.googleapis.com/v1/people/me?personFields=photos',
                ['headers' => ['Authorization' => 'Bearer ' . $accessToken]],
            );
            $data = json_decode((string) $response->getBody(), true);
            $url = $data['photos'][0]['url'] ?? null;
            if (!is_string($url) || $url === '') {
                return null;
            }
            $img = $this->http->request('GET', $url);
            $body = (string) $img->getBody();
            return $body !== '' ? $body : null;
        } catch (GuzzleException) {
            return null;
        }
    }
}
