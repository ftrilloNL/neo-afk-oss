<?php declare(strict_types=1);

namespace App\Middleware;

use App\Services\Csrf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Prueft das CSRF-Token bei state-changing Requests (POST/PUT/PATCH/DELETE).
 *
 * Token wird aus Body-Param `_csrf` gelesen und gegen die Session verglichen.
 * Bei Fehlschlag → 403 mit kurzem Hinweis (keine Slim-Error-Seite, damit
 * Angreifer keine Stacktrace sehen koennen).
 *
 * Bewusst NICHT angewendet auf /approval/{token}: dort ist der URL-Token selbst
 * die Autorisierung. Magic-Link kommt aus E-Mail, der User hat keine
 * vorgaengige Session.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $handler->handle($request);
        }

        $body = (array) $request->getParsedBody();
        $submitted = isset($body[$this->csrf->fieldName()]) ? (string) $body[$this->csrf->fieldName()] : null;

        if (!$this->csrf->validate($submitted)) {
            $response = new SlimResponse(403);
            $response->getBody()->write(
                'CSRF-Token ungültig oder abgelaufen. Bitte die Seite neu laden und erneut absenden.',
            );
            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        return $handler->handle($request);
    }
}
