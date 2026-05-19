<?php declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserRepository;
use App\Services\ApprovalService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Symfony\Component\Translation\Translator;

/**
 * Magic-Link-Approval-Endpunkt. Bewusst public (kein AuthMiddleware) — der Token
 * selbst ist die Autorisierung. Genehmiger:in muss nicht eingeloggt sein.
 */
final class ApprovalController
{
    public function __construct(
        private readonly ApprovalService $approval,
        private readonly UserRepository $users,
        private readonly Twig $view,
        private readonly Translator $translator,
    ) {
    }

    public function landing(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $found = $this->approval->lookupValidToken($token);
        if ($found === null) {
            return $this->view->render($response->withStatus(410), 'approval/error.twig', [
                'reason' => $this->translator->trans('approval.error.token_invalid'),
            ]);
        }

        $applicant = $this->users->findById((int) $found['absence']['user_id']);

        return $this->view->render($response, 'approval/landing.twig', [
            'absence' => $found['absence'],
            'applicant' => $applicant,
            'action' => $found['token']['action'],
            'token' => $token,
        ]);
    }

    public function action(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $found = $this->approval->lookupValidToken($token);
        if ($found === null) {
            return $this->view->render($response->withStatus(410), 'approval/error.twig', [
                'reason' => $this->translator->trans('approval.error.token_invalid'),
            ]);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $rejectComment = trim((string) ($body['begruendung'] ?? ''));

        try {
            $this->approval->processTokenAction($found['token'], $found['absence'], $rejectComment);
        } catch (\Throwable $e) {
            return $this->view->render($response->withStatus(500), 'approval/error.twig', [
                'reason' => $this->translator->trans('approval.error.processing_failed', ['%detail%' => $e->getMessage()]),
            ]);
        }

        $applicant = $this->users->findById((int) $found['absence']['user_id']);
        return $this->view->render($response, 'approval/done.twig', [
            'absence' => $found['absence'],
            'applicant' => $applicant,
            'action' => $found['token']['action'],
        ]);
    }
}
