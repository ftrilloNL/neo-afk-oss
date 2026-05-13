<?php declare(strict_types=1);

namespace App\Controllers;

use App\Auth\MicrosoftOAuth;
use App\Config;
use App\Database\Connection;
use App\Services\AvatarService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function __construct(
        private readonly MicrosoftOAuth $oauth,
        private readonly Connection $db,
        private readonly AvatarService $avatars,
        private readonly Config $config,
    ) {
    }

    public function login(Request $request, Response $response): Response
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;
        $url = $this->oauth->authorizationUrl($state);
        return $response->withHeader('Location', $url)->withStatus(302);
    }

    public function callback(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $code = $params['code'] ?? null;
        $state = $params['state'] ?? null;
        $expectedState = $_SESSION['oauth_state'] ?? null;
        unset($_SESSION['oauth_state']);

        if (!is_string($code) || !is_string($state) || $state !== $expectedState) {
            return $this->redirectWithError($response, 'state_mismatch');
        }

        try {
            $userInfo = $this->oauth->exchangeCode($code);
        } catch (\Throwable $e) {
            return $this->redirectWithError($response, 'token_exchange_failed');
        }

        if ($userInfo['oid'] === '' || $userInfo['email'] === '') {
            return $this->redirectWithError($response, 'incomplete_token');
        }

        $userId = $this->upsertUser($userInfo);

        // Avatar best-effort holen — Login soll nicht scheitern wenn Graph hakt.
        try {
            $this->avatars->fetchAndStore($userId, (string) $userInfo['access_token']);
        } catch (\Throwable $e) {
            error_log('AuthController: Avatar-Fetch fehlgeschlagen: ' . $e->getMessage());
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;

        return $response->withHeader('Location', '/')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }
        session_destroy();
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    private function redirectWithError(Response $response, string $error): Response
    {
        return $response
            ->withHeader('Location', '/?auth_error=' . urlencode($error))
            ->withStatus(302);
    }

    /**
     * Legt User an oder updated existierenden Datensatz.
     *
     * @param array{oid: string, email: string, name: string} $userInfo
     */
    private function upsertUser(array $userInfo): int
    {
        $conn = $this->db->dbal();

        // 1. Schon verknuepft? Suche per entra_oid.
        $existing = $conn->fetchAssociative(
            'SELECT id FROM users WHERE entra_oid = ?',
            [$userInfo['oid']]
        );
        if ($existing !== false) {
            $conn->update('users', [
                'email' => $userInfo['email'],
                'display_name' => $userInfo['name'],
            ], ['id' => $existing['id']]);
            return (int) $existing['id'];
        }

        // 2. Pre-Created? Wenn die HR den User vorab angelegt hat, gibt's nur
        //    eine Zeile mit passender Email aber entra_oid IS NULL. Verlinken.
        $preCreated = $conn->fetchAssociative(
            'SELECT id FROM users WHERE entra_oid IS NULL AND LOWER(email) = LOWER(?)',
            [$userInfo['email']]
        );
        if ($preCreated !== false) {
            $conn->update('users', [
                'entra_oid' => $userInfo['oid'],
                'display_name' => $userInfo['name'],
            ], ['id' => $preCreated['id']]);
            return (int) $preCreated['id'];
        }

        // 3. Komplett neu — Bootstrap-Logik fuer den allerersten Login.
        $isFirstUser = ((int) $conn->fetchOne('SELECT COUNT(*) FROM users')) === 0;
        $conn->insert('users', [
            'entra_oid' => $userInfo['oid'],
            'email' => $userInfo['email'],
            'display_name' => $userInfo['name'],
            'jahresanspruch' => $this->config->org()['default_jahresanspruch'],
            'resturlaub_aktuell' => $this->config->org()['default_jahresanspruch'],
            'resturlaub_vorjahr' => 0,
            'ist_aktiv' => 1,
            'ist_genehmiger' => $isFirstUser ? 1 : 0,
            'ist_hr' => $isFirstUser ? 1 : 0,
        ]);

        return (int) $conn->lastInsertId();
    }
}
