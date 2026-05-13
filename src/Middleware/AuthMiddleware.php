<?php declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Schuetzt Routes vor Zugriff durch nicht-eingeloggte User.
 * Anwendung: $app->get(...)->add(AuthMiddleware::class)
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        if (!isset($_SESSION['user_id'])) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        return $handler->handle($request);
    }
}
