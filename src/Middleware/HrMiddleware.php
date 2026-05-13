<?php declare(strict_types=1);

namespace App\Middleware;

use App\Models\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Schuetzt HR-Routes. Setzt voraus dass AuthMiddleware schon davor ausgefuehrt wurde
 * (= $_SESSION['user_id'] gesetzt).
 */
final class HrMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId === null) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $user = $this->users->findById((int) $userId);
        if ($user === null || empty($user['ist_hr'])) {
            $_SESSION['flash_error'] = 'Kein Zugriff: HR-Rolle erforderlich.';
            $response = new SlimResponse();
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        return $handler->handle($request);
    }
}
