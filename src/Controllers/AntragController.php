<?php declare(strict_types=1);

namespace App\Controllers;

use App\Models\AbsenceRepository;
use App\Models\AuditLogRepository;
use App\Models\UserRepository;
use App\Services\AbsenceEditService;
use App\Services\ApprovalService;
use App\Services\WerktageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AntragController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AbsenceRepository $absences,
        private readonly WerktageService $werktage,
        private readonly ApprovalService $approval,
        private readonly AuditLogRepository $audit,
        private readonly AbsenceEditService $editService,
        private readonly Twig $view,
    ) {
    }

    public function neu(Request $request, Response $response): Response
    {
        $userId = (int) $_SESSION['user_id'];
        $user = $this->users->findById($userId);
        if ($user === null) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        return $this->view->render($response, 'antrag/neu.twig', [
            'user' => $user,
            'active_nav' => 'antrag',
            'genehmiger_options' => $this->users->listGenehmiger(),
            'today' => date('Y-m-d'),
            'flash_error' => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Live-Vorschau der Werktage als HTML-Snippet — wird von HTMX im Form aufgerufen.
     * Nutzt `art`-Query-Param (urlaub/krank) um zu entscheiden ob ein Resturlaub-
     * Vergleich angezeigt wird.
     */
    public function previewTage(Request $request, Response $response): Response
    {
        $userId = (int) $_SESSION['user_id'];
        $user = $this->users->findById($userId);

        $params = $request->getQueryParams();
        $startStr = trim((string) ($params['startdatum'] ?? ''));
        $endStr = trim((string) ($params['enddatum'] ?? ''));
        $halbtagStart = (string) ($params['halbtag_start'] ?? 'ganztag');
        $halbtagEnde = (string) ($params['halbtag_ende'] ?? 'ganztag');
        $art = (string) ($params['art'] ?? 'urlaub');

        if ($startStr === '' || $endStr === '') {
            return $this->view->render($response, 'partials/tage-preview.twig', ['state' => 'incomplete']);
        }
        try {
            $start = new \DateTimeImmutable($startStr);
            $end = new \DateTimeImmutable($endStr);
        } catch (\Exception) {
            return $this->view->render($response, 'partials/tage-preview.twig', ['state' => 'invalid']);
        }
        if ($end < $start) {
            return $this->view->render($response, 'partials/tage-preview.twig', ['state' => 'invalid_range']);
        }
        if (!in_array($halbtagStart, ['ganztag', 'nachmittag'], true)) {
            $halbtagStart = 'ganztag';
        }
        if (!in_array($halbtagEnde, ['ganztag', 'vormittag'], true)) {
            $halbtagEnde = 'ganztag';
        }

        $tage = $this->werktage->compute($start, $end, $halbtagStart, $halbtagEnde);

        $verfuegbar = null;
        $ueberzogen = false;
        if ($art === 'urlaub' && $user !== null) {
            $verfuegbar = (float) $user['resturlaub_aktuell'] + (float) $user['resturlaub_vorjahr'];
            $ueberzogen = $tage > $verfuegbar;
        }

        return $this->view->render($response, 'partials/tage-preview.twig', [
            'state' => 'ok',
            'tage' => $tage,
            'verfuegbar' => $verfuegbar,
            'ueberzogen' => $ueberzogen,
            'art' => $art,
        ]);
    }

    public function submit(Request $request, Response $response): Response
    {
        unset($_SESSION['flash_error']);
        $userId = (int) $_SESSION['user_id'];
        $user = $this->users->findById($userId);
        if ($user === null) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $startStr = trim((string) ($body['startdatum'] ?? ''));
        $endStr = trim((string) ($body['enddatum'] ?? ''));
        $halbtagStart = (string) ($body['halbtag_start'] ?? 'ganztag');
        $halbtagEnde = (string) ($body['halbtag_ende'] ?? 'ganztag');
        $genehmigerId = (int) ($body['genehmiger_id'] ?? 0);
        $notiz = trim((string) ($body['notiz'] ?? ''));

        if ($startStr === '' || $endStr === '' || $genehmigerId === 0) {
            return $this->redirectWithError($response, 'Bitte alle Pflichtfelder ausfüllen.');
        }
        try {
            $start = new \DateTimeImmutable($startStr);
            $end = new \DateTimeImmutable($endStr);
        } catch (\Exception) {
            return $this->redirectWithError($response, 'Ungültiges Datum.');
        }
        if ($end < $start) {
            return $this->redirectWithError($response, 'Enddatum darf nicht vor Startdatum liegen.');
        }
        if (!in_array($halbtagStart, ['ganztag', 'nachmittag'], true)) {
            $halbtagStart = 'ganztag';
        }
        if (!in_array($halbtagEnde, ['ganztag', 'vormittag'], true)) {
            $halbtagEnde = 'ganztag';
        }
        if ($genehmigerId === $userId) {
            return $this->redirectWithError($response, 'Du kannst dich nicht selbst als Genehmiger:in eintragen.');
        }

        $tageGezaehlt = $this->werktage->compute($start, $end, $halbtagStart, $halbtagEnde);
        if ($tageGezaehlt <= 0) {
            return $this->redirectWithError($response, 'Der Zeitraum enthält keine Werktage.');
        }

        // Google: ein einziges ooo_text-Field, das in beide Spalten gespiegelt wird.
        // Microsoft: getrennte ooo_internal + ooo_external.
        $unifiedOoo = trim((string) ($body['ooo_text'] ?? ''));
        if ($unifiedOoo !== '') {
            $oooInternal = $unifiedOoo;
            $oooExternal = $unifiedOoo;
        } else {
            $oooInternal = trim((string) ($body['ooo_internal'] ?? ''));
            $oooExternal = trim((string) ($body['ooo_external'] ?? ''));
        }

        $absenceId = $this->absences->insert([
            'user_id' => $userId,
            'art' => 'urlaub',
            'startdatum' => $start->format('Y-m-d'),
            'enddatum' => $end->format('Y-m-d'),
            'halbtag_start' => $halbtagStart,
            'halbtag_ende' => $halbtagEnde,
            'tage_gezaehlt' => $tageGezaehlt,
            'status' => 'beantragt',
            'genehmiger_id' => $genehmigerId,
            'notiz' => $notiz !== '' ? $notiz : null,
            'ooo_internal' => $oooInternal !== '' ? $oooInternal : null,
            'ooo_external' => $oooExternal !== '' ? $oooExternal : null,
        ]);

        $verfuegbar = (float) $user['resturlaub_aktuell'] + (float) $user['resturlaub_vorjahr'];
        if ($tageGezaehlt > $verfuegbar) {
            // Automatische Ablehnung — kein Approval-Round-Trip noetig
            $this->absences->update($absenceId, [
                'status' => 'abgelehnt',
                'begruendung_ablehnung' => sprintf(
                    'Automatische Ablehnung: Resturlaub nicht ausreichend (%s Tage beantragt, %s Tage verfügbar).',
                    number_format($tageGezaehlt, 1, ',', '.'),
                    number_format($verfuegbar, 1, ',', '.')
                ),
            ]);
            $this->audit->log(
                $userId,
                'absence.auto_rejected',
                'absence',
                $absenceId,
                ['reason' => 'insufficient_resturlaub']
            );
            $_SESSION['flash_error'] = sprintf(
                'Antrag automatisch abgelehnt: %s Tage beantragt, nur %s verfügbar.',
                number_format($tageGezaehlt, 1, ',', '.'),
                number_format($verfuegbar, 1, ',', '.')
            );
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $this->audit->log(
            $userId,
            'absence.created',
            'absence',
            $absenceId,
            ['art' => 'urlaub', 'tage_gezaehlt' => $tageGezaehlt]
        );

        $this->approval->requestApproval($absenceId);

        $_SESSION['flash_success'] = 'Urlaubsantrag eingereicht. Genehmiger:in wird per Mail informiert.';
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        unset($_SESSION['flash_error']);
        $userId = (int) $_SESSION['user_id'];
        $absenceId = (int) ($args['id'] ?? 0);
        $user = $this->users->findById($userId);
        $absence = $this->absences->findById($absenceId);
        if ($user === null || $absence === null) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        if ($absence['art'] !== 'urlaub') {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $isOwner = (int) $absence['user_id'] === $userId;
        $isHr = (bool) $user['ist_hr'];
        if (!$isOwner && !$isHr) {
            $_SESSION['flash_error'] = 'Du darfst diesen Antrag nicht bearbeiten.';
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        if (in_array($absence['status'], ['storniert', 'abgelehnt'], true)) {
            $_SESSION['flash_error'] = sprintf('Antrag ist %s und kann nicht bearbeitet werden.', $absence['status']);
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $applicant = $isOwner ? $user : $this->users->findById((int) $absence['user_id']);

        return $this->view->render($response, 'antrag/edit.twig', [
            'user' => $user,
            'applicant' => $applicant,
            'absence' => $absence,
            'is_hr_edit' => $isHr && !$isOwner,
            'active_nav' => $isHr && !$isOwner ? 'hr' : 'antrag',
            'today' => date('Y-m-d'),
            'flash_error' => null,
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        unset($_SESSION['flash_error']);
        $userId = (int) $_SESSION['user_id'];
        $absenceId = (int) ($args['id'] ?? 0);

        $body = (array) ($request->getParsedBody() ?? []);
        $result = $this->editService->editUrlaub($absenceId, $userId, $body);

        if (!$result['ok']) {
            $_SESSION['flash_error'] = $result['error'] ?? 'Bearbeitung fehlgeschlagen.';
            return $response->withHeader('Location', '/antrag/' . $absenceId . '/edit')->withStatus(302);
        }

        $_SESSION['flash_success'] = $result['success'] ?? 'Antrag aktualisiert.';
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    private function redirectWithError(Response $response, string $msg): Response
    {
        $_SESSION['flash_error'] = $msg;
        return $response->withHeader('Location', '/antrag/neu')->withStatus(302);
    }
}
