<?php declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Database\Connection;
use App\Providers\Contracts\IdentityInfo;
use App\Providers\Contracts\IdentityProvider;
use App\Services\AvatarService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function __construct(
        private readonly IdentityProvider $identity,
        private readonly Connection $db,
        private readonly AvatarService $avatars,
        private readonly Config $config,
    ) {
    }

    public function login(Request $request, Response $response): Response
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;
        $url = $this->identity->authorizationUrl($state);
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
            $userInfo = $this->identity->exchangeCode($code);
        } catch (\Throwable $e) {
            return $this->redirectWithError($response, 'token_exchange_failed');
        }

        if ($userInfo->externalOid === '' || $userInfo->email === '') {
            return $this->redirectWithError($response, 'incomplete_token');
        }

        $userId = $this->upsertUser($userInfo);

        // Avatar best-effort — Login soll nicht scheitern wenn das Photo-Endpoint hakt.
        try {
            $photoBytes = $this->identity->fetchAvatar($userInfo->accessToken);
            if ($photoBytes !== null) {
                $this->avatars->storeBytes($userId, $photoBytes);
            }
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

    private function upsertUser(IdentityInfo $info): int
    {
        $conn = $this->db->dbal();
        $providerName = $this->identity->name();

        // 1. Schon verknuepft? Suche per (provider, external_oid).
        $existing = $conn->fetchAssociative(
            'SELECT id FROM users WHERE external_provider = ? AND external_oid = ?',
            [$providerName, $info->externalOid]
        );
        if ($existing !== false) {
            $conn->update('users', [
                'email' => $info->email,
                'display_name' => $info->displayName,
            ], ['id' => $existing['id']]);
            return (int) $existing['id'];
        }

        // 2. Pre-Created via HR? external_oid IS NULL und Email matched → verlinken.
        $preCreated = $conn->fetchAssociative(
            'SELECT id FROM users WHERE external_oid IS NULL AND LOWER(email) = LOWER(?)',
            [$info->email]
        );
        if ($preCreated !== false) {
            $conn->update('users', [
                'external_oid' => $info->externalOid,
                'external_provider' => $providerName,
                'display_name' => $info->displayName,
            ], ['id' => $preCreated['id']]);
            return (int) $preCreated['id'];
        }

        // 3. Komplett neu — Bootstrap fuer den allerersten Login.
        $isFirstUser = ((int) $conn->fetchOne('SELECT COUNT(*) FROM users')) === 0;
        $conn->insert('users', [
            'external_oid' => $info->externalOid,
            'external_provider' => $providerName,
            'email' => $info->email,
            'display_name' => $info->displayName,
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
