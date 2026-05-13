<?php declare(strict_types=1);

namespace App\Controllers;

use App\Models\AbsenceRepository;
use App\Models\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class HrController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AbsenceRepository $absences,
        private readonly Twig $view,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = (int) $_SESSION['user_id'];
        $user = $this->users->findById($userId);

        $params = $request->getQueryParams();
        $filters = [
            'art' => $this->normalize($params['art'] ?? null, ['urlaub', 'krank']),
            'status' => $this->normalize($params['status'] ?? null, ['beantragt', 'aktiv', 'abgelehnt', 'storniert']),
            'user_id' => isset($params['user_id']) && $params['user_id'] !== '' ? (int) $params['user_id'] : null,
            'from' => $this->validDate($params['from'] ?? null),
            'to' => $this->validDate($params['to'] ?? null),
        ];

        $absences = $this->absences->listAllWithFilters($filters);
        $allUsers = $this->users->listAllActive();

        // Aggregate-Stats fuer das Filter-Result
        $totalTage = 0.0;
        $totalUrlaub = 0.0;
        $totalKrank = 0.0;
        foreach ($absences as $a) {
            $tage = (float) $a['tage_gezaehlt'];
            $totalTage += $tage;
            if ($a['art'] === 'urlaub') {
                $totalUrlaub += $tage;
            } else {
                $totalKrank += $tage;
            }
        }

        return $this->view->render($response, 'hr/index.twig', [
            'user' => $user,
            'active_nav' => 'hr',
            'absences' => $absences,
            'all_users' => $allUsers,
            'filters' => $filters,
            'total_tage' => $totalTage,
            'total_urlaub' => $totalUrlaub,
            'total_krank' => $totalKrank,
            'count' => count($absences),
        ]);
    }

    private function normalize(?string $value, array $allowed): ?string
    {
        if ($value === null || $value === '' || !in_array($value, $allowed, true)) {
            return null;
        }
        return $value;
    }

    private function validDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
