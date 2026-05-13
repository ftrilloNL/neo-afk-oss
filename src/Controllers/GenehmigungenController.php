<?php declare(strict_types=1);

namespace App\Controllers;

use App\Models\AbsenceRepository;
use App\Models\UserRepository;
use App\Services\ApprovalService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class GenehmigungenController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AbsenceRepository $absences,
        private readonly ApprovalService $approval,
        private readonly Twig $view,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = (int) $_SESSION['user_id'];
        $user = $this->users->findById($userId);
        if ($user === null) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $pending = $this->absences->listPendingForGenehmiger($userId);

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        return $this->view->render($response, 'genehmigungen/index.twig', [
            'user' => $user,
            'active_nav' => 'genehmigungen',
            'pending' => $pending,
            'pending_approvals_count' => count($pending),
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
        ]);
    }

    public function approve(Request $request, Response $response, array $args): Response
    {
        return $this->processAction($response, (int) ($args['id'] ?? 0), 'approve', '');
    }

    public function reject(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $comment = trim((string) ($body['begruendung'] ?? ''));
        if ($comment === '') {
            $_SESSION['flash_error'] = 'Bitte Begründung für die Ablehnung angeben.';
            return $response->withHeader('Location', '/genehmigungen')->withStatus(302);
        }
        return $this->processAction($response, (int) ($args['id'] ?? 0), 'reject', $comment);
    }

    private function processAction(Response $response, int $absenceId, string $action, string $comment): Response
    {
        $userId = (int) $_SESSION['user_id'];
        $absence = $this->absences->findById($absenceId);
        if ($absence === null) {
            $_SESSION['flash_error'] = 'Antrag nicht gefunden.';
            return $response->withHeader('Location', '/genehmigungen')->withStatus(302);
        }

        try {
            $this->approval->processInAppAction($userId, $absence, $action, $comment);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', '/genehmigungen')->withStatus(302);
        }

        $_SESSION['flash_success'] = $action === 'approve'
            ? 'Antrag genehmigt. Antragsteller:in wurde per Mail informiert.'
            : 'Antrag abgelehnt. Antragsteller:in wurde per Mail informiert.';
        return $response->withHeader('Location', '/genehmigungen')->withStatus(302);
    }
}
