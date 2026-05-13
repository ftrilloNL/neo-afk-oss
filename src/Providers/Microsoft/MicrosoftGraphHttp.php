<?php declare(strict_types=1);

namespace App\Providers\Microsoft;

use App\Config;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Geteilter HTTP-Helper fuer Microsoft Graph: App-only Token via
 * Client-Credentials-Flow holen, cachen, Requests gegen graph.microsoft.com
 * mit Bearer-Auth ausfuehren.
 *
 * Wird von MicrosoftCalendarProvider + MicrosoftOooProvider geteilt — beide
 * brauchen denselben App-Token-Lebenszyklus.
 */
final class MicrosoftGraphHttp
{
    private ?string $cachedToken = null;
    private ?int $tokenExpiresAt = null;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http = new Client(['timeout' => 10]),
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $this->getAppToken(),
            'Accept' => 'application/json',
        ]);

        try {
            return $this->http->request($method, 'https://graph.microsoft.com/v1.0' . $path, $options);
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "Graph {$method} {$path} fehlgeschlagen: " . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    private function getAppToken(): string
    {
        if ($this->cachedToken !== null
            && $this->tokenExpiresAt !== null
            && $this->tokenExpiresAt > time() + 60
        ) {
            return $this->cachedToken;
        }

        $tenant = $this->config->get('OAUTH_TENANT_ID');
        try {
            $response = $this->http->request(
                'POST',
                "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
                [
                    'form_params' => [
                        'client_id' => $this->config->get('OAUTH_CLIENT_ID'),
                        'client_secret' => $this->config->get('OAUTH_CLIENT_SECRET'),
                        'scope' => 'https://graph.microsoft.com/.default',
                        'grant_type' => 'client_credentials',
                    ],
                ],
            );
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Graph App-Token-Request fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || !isset($data['access_token'])) {
            throw new \RuntimeException('Graph App-Token-Response ohne access_token');
        }

        $this->cachedToken = (string) $data['access_token'];
        $this->tokenExpiresAt = time() + (int) ($data['expires_in'] ?? 3600);
        return $this->cachedToken;
    }
}
