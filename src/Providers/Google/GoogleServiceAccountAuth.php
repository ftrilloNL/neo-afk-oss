<?php declare(strict_types=1);

namespace App\Providers\Google;

use App\Config;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service-Account-Auth mit Domain-Wide Delegation. Signiert ein JWT als
 * Service-Account (iss=client_email, sub=user-to-impersonate), tauscht es
 * gegen einen access_token von Google's OAuth-Endpoint, cached den Token
 * pro Subject in-memory.
 *
 * Setup: Workspace-Admin muss den Service-Account-Client unter
 * admin.google.com → Security → API controls → Domain-wide Delegation
 * mit den noetigen Scopes whitelisten. Siehe docs/google-workspace-setup.md.
 *
 * Der Service-Account-JSON-Key (von der GCP-Console) liegt unter
 * var/secrets/google-service-account.json (vom Setup-Wizard geschrieben,
 * chmod 0600).
 */
final class GoogleServiceAccountAuth
{
    /** @var array<string, array{token: string, expires_at: int}> */
    private array $cache = [];

    public function __construct(
        private readonly Config $config,
        private readonly string $keyFilePath,
        private readonly ClientInterface $http = new Client(['timeout' => 10]),
    ) {
    }

    /**
     * Holt einen access_token fuer den Service-Account, der den angegebenen
     * User impersoniert (sub-Claim im JWT). Token wird pro (subject, scope)-Paar
     * in-memory gecached.
     *
     * @param list<string> $scopes z.B. ['https://www.googleapis.com/auth/calendar']
     */
    public function getAccessToken(string $impersonateEmail, array $scopes): string
    {
        $scopeStr = implode(' ', $scopes);
        $cacheKey = $impersonateEmail . '|' . $scopeStr;
        $now = time();

        if (isset($this->cache[$cacheKey]) && $this->cache[$cacheKey]['expires_at'] > $now + 60) {
            return $this->cache[$cacheKey]['token'];
        }

        $key = $this->loadKey();
        $assertion = $this->buildAssertion($key, $impersonateEmail, $scopeStr, $now);

        try {
            $response = $this->http->request('POST', 'https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                'Google Service-Account Token-Exchange fehlgeschlagen: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || !isset($data['access_token'])) {
            throw new \RuntimeException('Google Token-Response ohne access_token');
        }

        $token = (string) $data['access_token'];
        $this->cache[$cacheKey] = [
            'token' => $token,
            'expires_at' => $now + (int) ($data['expires_in'] ?? 3600),
        ];
        return $token;
    }

    /**
     * @return array{client_email: string, private_key: string, token_uri: string}
     */
    private function loadKey(): array
    {
        if (!is_file($this->keyFilePath)) {
            throw new \RuntimeException(
                "Kein Service-Account-Key unter {$this->keyFilePath}. "
                . 'Setup via /setup-Wizard oder JSON-Key manuell ablegen.'
            );
        }
        $raw = (string) file_get_contents($this->keyFilePath);
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['client_email'], $data['private_key'])) {
            throw new \RuntimeException('Service-Account-Key-Datei ist kein valides JSON oder unvollstaendig.');
        }
        return [
            'client_email' => (string) $data['client_email'],
            'private_key' => (string) $data['private_key'],
            'token_uri' => (string) ($data['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        ];
    }

    /**
     * @param array{client_email: string, private_key: string, token_uri: string} $key
     */
    private function buildAssertion(array $key, string $impersonate, string $scope, int $now): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => $key['client_email'],
            'sub' => $impersonate,
            'scope' => $scope,
            'aud' => $key['token_uri'],
            'exp' => $now + 3600,
            'iat' => $now,
        ];
        $segments = [
            $this->base64UrlEncode((string) json_encode($header)),
            $this->base64UrlEncode((string) json_encode($payload)),
        ];
        $signingInput = implode('.', $segments);

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Service-Account-Key konnte JWT nicht signieren (RSA-SHA256).');
        }
        $segments[] = $this->base64UrlEncode($signature);
        return implode('.', $segments);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
