<?php declare(strict_types=1);

namespace App\Services;

use App\Config;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use PHPMailer\PHPMailer\OAuthTokenProvider;

/**
 * Liefert XOAUTH2-Auth-Strings fuer PHPMailer-SMTP-Auth gegen Office365.
 *
 * Persistierter Refresh-Token in var/secrets/smtp-refresh-token (initial via
 * bin/setup-smtp-oauth.php gesetzt, danach selbstrotierend). Access-Token wird
 * pro Request in-memory gecached und kurz vor Ablauf neu geholt. Wenn Microsoft
 * einen neuen Refresh-Token mitschickt (rolling rotation bei Public-Client-Flow),
 * wird er sofort persistiert.
 *
 * Wirft \RuntimeException wenn kein Refresh-Token vorhanden ist oder das Refresh
 * fehlschlaegt — Aufrufer muss dann bin/setup-smtp-oauth.php neu durchlaufen.
 */
final class SmtpOAuthTokenProvider implements OAuthTokenProvider
{
    private ?string $cachedAccessToken = null;
    private ?int $tokenExpiresAt = null;

    public function __construct(
        private readonly Config $config,
        private readonly string $tokenFile,
        private readonly string $userEmail,
        private readonly ClientInterface $http = new Client(['timeout' => 10]),
    ) {
    }

    public function getOauth64(): string
    {
        $accessToken = $this->getAccessToken();
        $authString = "user={$this->userEmail}\x01auth=Bearer {$accessToken}\x01\x01";
        return base64_encode($authString);
    }

    public function getAccessToken(): string
    {
        if ($this->cachedAccessToken !== null
            && $this->tokenExpiresAt !== null
            && $this->tokenExpiresAt > time() + 60
        ) {
            return $this->cachedAccessToken;
        }

        $refreshToken = $this->loadRefreshToken();
        $tenant = $this->config->get('OAUTH_TENANT_ID');

        // Public-Client-Flow (Mobile/Desktop-Plattform in der App-Registrierung):
        // KEIN client_secret, sonst AADSTS700025. Der refresh_token wurde via
        // Device-Code-Flow geholt und gehoert konsistent zum Public-Client-Mode.
        $params = [
            'client_id' => $this->config->get('OAUTH_CLIENT_ID'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => 'https://outlook.office.com/SMTP.Send offline_access',
        ];

        try {
            $response = $this->http->request(
                'POST',
                "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
                ['form_params' => $params],
            );
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                'SMTP-Token-Refresh fehlgeschlagen. Eventuell ist der Refresh-Token abgelaufen '
                . '(>90 Tage ohne Nutzung) — bin/setup-smtp-oauth.php erneut ausfuehren. '
                . 'Original-Fehler: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || !isset($data['access_token'])) {
            throw new \RuntimeException('SMTP-Token-Response ohne access_token');
        }

        $this->cachedAccessToken = (string) $data['access_token'];
        $this->tokenExpiresAt = time() + (int) ($data['expires_in'] ?? 3600);

        // Rolling rotation: wenn ein neuer Refresh-Token mitkommt, sofort persistieren.
        if (isset($data['refresh_token']) && $data['refresh_token'] !== $refreshToken) {
            $this->saveRefreshToken((string) $data['refresh_token']);
        }

        return $this->cachedAccessToken;
    }

    public function saveRefreshToken(string $token): void
    {
        $dir = dirname($this->tokenFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        // Atomic write: erst nach .tmp, dann rename — kein Halbzustand bei Crash.
        $tmp = $this->tokenFile . '.tmp';
        file_put_contents($tmp, $token);
        chmod($tmp, 0600);
        rename($tmp, $this->tokenFile);
    }

    private function loadRefreshToken(): string
    {
        if (!is_file($this->tokenFile)) {
            throw new \RuntimeException(
                "Kein SMTP-Refresh-Token unter {$this->tokenFile}. "
                . 'Erstmaliges Setup: bin/setup-smtp-oauth.php ausfuehren.',
            );
        }
        $content = trim((string) file_get_contents($this->tokenFile));
        if ($content === '') {
            throw new \RuntimeException("SMTP-Refresh-Token-Datei {$this->tokenFile} ist leer.");
        }
        return $content;
    }
}
