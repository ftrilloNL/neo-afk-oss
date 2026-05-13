<?php declare(strict_types=1);

namespace App\Controllers;

use App\Services\AvatarService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Liefert das gespeicherte JPEG eines Users aus var/avatars/. Liefert 404 wenn
 * der User kein Avatar in M365 gesetzt hat (oder es noch nicht gefetched wurde).
 *
 * AuthMiddleware-geschuetzt: nur eingeloggte User koennen Avatars sehen.
 */
final class AvatarController
{
    public function __construct(private readonly AvatarService $avatars)
    {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($args['id'] ?? 0);
        if (!$this->avatars->exists($userId)) {
            return $response->withStatus(404);
        }

        $path = $this->avatars->avatarPath($userId);
        $body = (string) file_get_contents($path);

        $response = $response
            ->withHeader('Content-Type', 'image/jpeg')
            ->withHeader('Cache-Control', 'private, max-age=86400');
        $response->getBody()->write($body);
        return $response;
    }
}
