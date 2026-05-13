<?php declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditLogRepository;
use App\Models\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Read-only Audit-Log-Anzeige fuer HR. Listet die letzten Schreib-Operationen
 * auf users + absences (siehe AuditLogRepository::log).
 *
 * Bewusst nur HR-sichtbar (HrMiddleware) — Audit-Log kann sensible Daten
 * im Payload enthalten (z.B. Stammdaten-Diffs).
 */
final class AuditController
{
    public function __construct(
        private readonly AuditLogRepository $audit,
        private readonly UserRepository $users,
        private readonly Twig $view,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $callerId = (int) $_SESSION['user_id'];
        $caller = $this->users->findById($callerId);

        $params = $request->getQueryParams();
        $filters = [
            'action' => $this->cleanQuery($params['action'] ?? null),
            'user_id' => isset($params['user_id']) && $params['user_id'] !== '' ? (int) $params['user_id'] : null,
            'from' => $this->validDate($params['from'] ?? null),
            'to' => $this->validDate($params['to'] ?? null),
        ];

        $entries = $this->audit->listWithFilters($filters);
        // Payload (JSON-String aus DB) zu Array decodieren — Twig macht dann pretty-print.
        foreach ($entries as &$e) {
            if (!empty($e['payload']) && is_string($e['payload'])) {
                $decoded = json_decode($e['payload'], true);
                $e['payload_decoded'] = is_array($decoded) ? $decoded : null;
            } else {
                $e['payload_decoded'] = null;
            }
        }
        unset($e);

        $allActions = $this->audit->listDistinctActions();
        $allUsers = $this->users->listAll();

        return $this->view->render($response, 'hr/audit/index.twig', [
            'user' => $caller,
            'active_nav' => 'hr-audit',
            'entries' => $entries,
            'all_actions' => $allActions,
            'all_users' => $allUsers,
            'filters' => $filters,
        ]);
    }

    private function cleanQuery(?string $value): ?string
    {
        $v = trim((string) ($value ?? ''));
        return $v === '' ? null : $v;
    }

    private function validDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
