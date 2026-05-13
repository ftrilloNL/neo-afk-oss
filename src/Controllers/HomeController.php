<?php declare(strict_types=1);

namespace App\Controllers;

use App\Models\AbsenceRepository;
use App\Models\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class HomeController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AbsenceRepository $absences,
        private readonly Twig $view,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user_id'] ?? null;
        $authError = $request->getQueryParams()['auth_error'] ?? null;

        if ($userId === null) {
            return $this->view->render($response, 'login.twig', [
                'auth_error' => $authError,
            ]);
        }

        $user = $this->users->findById((int) $userId);
        if ($user === null) {
            session_destroy();
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $absences = $this->absences->listForUser((int) $userId);
        $pendingApprovalsCount = $this->absences->countPendingForGenehmiger((int) $userId);
        $krankTageYtd = $this->absences->sumKrankTageForUserAndYear((int) $userId, (int) date('Y'));

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        return $this->view->render($response, 'home.twig', [
            'user' => $user,
            'active_nav' => 'home',
            'absences' => $absences,
            'pending_approvals_count' => $pendingApprovalsCount,
            'krank_tage_ytd' => $krankTageYtd,
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
        ]);
    }
}
