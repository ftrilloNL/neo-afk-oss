<?php declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Read-only Team-Uebersicht fuer alle eingeloggten User. Zeigt Avatar, Name,
 * Email, Telefon (sofern in master_data gepflegt) und Rollen-Pills.
 *
 * Bewusst flache Darstellung — keine hierarchische Org-Chart (haetten wir
 * aktuell auch kein Datenmodell fuer). Bei 10 MAs ist eine simple Karten-Liste
 * eh ausreichend.
 */
final class TeamController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Twig $view,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $callerId = (int) $_SESSION['user_id'];
        $caller = $this->users->findById($callerId);
        $team = $this->users->listTeam();

        return $this->view->render($response, 'team/index.twig', [
            'user' => $caller,
            'active_nav' => 'team',
            'team' => $team,
        ]);
    }
}
