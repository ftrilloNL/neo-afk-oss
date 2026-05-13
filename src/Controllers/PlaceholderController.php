<?php declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Rendert „Coming soon"-Stub fuer noch unimplementierte Routes.
 * Wird in Code-Commit-3+ durch echte Controller ersetzt (AntragController, KrankController etc.).
 */
final class PlaceholderController
{
    public function __construct(
        private readonly Connection $db,
        private readonly Twig $view,
    ) {
    }

    public function antragNeu(Request $request, Response $response): Response
    {
        return $this->render($response, 'antrag', 'Urlaub beantragen', 'Form fuer Datums-Auswahl, Genehmiger-Picker und Live-Tage-Berechnung folgt.');
    }

    public function krankNeu(Request $request, Response $response): Response
    {
        return $this->render($response, 'krank', 'Krankmeldung erfassen', 'Sofort-wirksame Erfassung mit automatischer Mail an HR folgt.');
    }

    public function genehmigungen(Request $request, Response $response): Response
    {
        return $this->render($response, 'genehmigungen', 'Meine Genehmigungen', 'Liste offener Antraege wo du Genehmiger:in bist.');
    }

    public function hr(Request $request, Response $response): Response
    {
        return $this->render($response, 'hr', 'HR-Auswertung', 'Filterbare Sicht aller Abwesenheiten inkl. Krank-Detail (nur fuer HR).');
    }

    private function render(Response $response, string $activeNav, string $title, string $note): Response
    {
        $userId = $_SESSION['user_id'];
        $user = $this->db->dbal()->fetchAssociative('SELECT * FROM users WHERE id = ?', [$userId]);

        return $this->view->render($response, 'coming-soon.twig', [
            'user' => $user,
            'active_nav' => $activeNav,
            'title' => $title,
            'note' => $note,
        ]);
    }
}
