<?php declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Holt User-Profilbilder aus Microsoft Graph (eigenes Bild via Delegated User.Read)
 * und cached sie unter var/avatars/{user-id}.jpg.
 *
 * Wird beim SSO-Callback aufgerufen mit dem User-Access-Token. App-only-Fetch fuer
 * andere User waere ein groesserer Setup (User.Read.All Application Permission) und
 * ist aktuell nicht implementiert.
 *
 * Best-effort: jeder Fehler wird stillschweigend geschluckt — der Login soll nicht
 * scheitern wenn Graph mal hakt oder kein Photo gesetzt ist.
 */
final class AvatarService
{
    public function __construct(
        private readonly string $storagePath,
        private readonly ClientInterface $http = new Client(['timeout' => 10]),
    ) {
    }

    public function avatarPath(int $userId): string
    {
        return $this->storagePath . '/' . $userId . '.jpg';
    }

    public function exists(int $userId): bool
    {
        $path = $this->avatarPath($userId);
        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Fetch /me/photo/$value mit User-Access-Token, speichere unter
     * var/avatars/{user-id}.jpg. Bei 404 (kein Photo in M365 gesetzt) wird
     * eine vorhandene alte Datei geloescht, damit das UI dann Initials zeigt.
     */
    public function fetchAndStore(int $userId, string $userAccessToken): void
    {
        try {
            $response = $this->http->request(
                'GET',
                'https://graph.microsoft.com/v1.0/me/photo/$value',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $userAccessToken,
                        'Accept' => 'image/jpeg',
                    ],
                ],
            );
        } catch (GuzzleException $e) {
            if (str_contains($e->getMessage(), '404')) {
                // Kein Photo in M365 — alte Datei wegraeumen.
                $this->delete($userId);
            }
            // Andere Fehler silent — Login soll nicht scheitern.
            return;
        }

        $body = (string) $response->getBody();
        if (strlen($body) === 0) {
            $this->delete($userId);
            return;
        }

        $path = $this->avatarPath($userId);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $body);
        chmod($tmp, 0644);
        rename($tmp, $path);
    }

    private function delete(int $userId): void
    {
        $path = $this->avatarPath($userId);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
