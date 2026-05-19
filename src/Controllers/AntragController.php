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
use Symfony\Component\Translation\Translator;

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
        private readonly Translator $translator,
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
            return $this->redirectWithError($response, $this->translator->trans('flash.antrag.required_fields'));
        }
        try {
            $start = new \DateTimeImmutable($startStr);
            $end = new \DateTimeImmutable($endStr);
        } catch (\Exception) {
            return $this->redirectWithError($response, $this->translator->trans('flash.common.invalid_date'));
        }
        if ($end < $start) {
            return $this->redirectWithError($response, $this->translator->trans('flash.common.end_before_start'));
        }
        if (!in_array($halbtagStart, ['ganztag', 'nachmittag'], true)) {
            $halbtagStart = 'ganztag';
        }
        if (!in_array($halbtagEnde, ['ganztag', 'vormittag'], true)) {
            $halbtagEnde = 'ganztag';
        }
        if ($genehmigerId === $userId) {
            return $this->redirectWithError($response, $this->translator->trans('flash.antrag.self_approver'));
        }

        $tageGezaehlt = $this->werktage->compute($start, $end, $halbtagStart, $halbtagEnde);
        if ($tageGezaehlt <= 0) {
            return $this->redirectWithError($response, $this->translator->trans('flash.common.no_workdays'));
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
            $vars = [
                '%tage%' => number_format($tageGezaehlt, 1, ',', '.'),
                '%verfuegbar%' => number_format($verfuegbar, 1, ',', '.'),
            ];
            // DB-stored reason: writer's active locale. Pragmatic compromise --
            // the value is read-only after write, so this is intentionally NOT
            // re-translated on display (UI-only i18n scope, Epic AFK-1).
            $this->absences->update($absenceId, [
                'status' => 'abgelehnt',
                'begruendung_ablehnung' => $this->translator->trans('flash.antrag.auto_rejected.reason_stored', $vars),
            ]);
            $this->audit->log(
                $userId,
                'absence.auto_rejected',
                'absence',
                $absenceId,
                ['reason' => 'insufficient_resturlaub']
            );
            $_SESSION['flash_error'] = $this->translator->trans('flash.antrag.auto_rejected', $vars);
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

        $_SESSION['flash_success'] = $this->translator->trans('flash.antrag.submitted');
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
            $_SESSION['flash_error'] = $this->translator->trans('flash.antrag.edit.not_authorized');
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        if (in_array($absence['status'], ['storniert', 'abgelehnt'], true)) {
            $_SESSION['flash_error'] = $this->translator->trans(
                'flash.edit.terminal_status_antrag',
                ['%status%' => $this->translator->trans('status.' . $absence['status'])]
            );
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
            // editService produces already-translated messages (it injects Translator).
            $_SESSION['flash_error'] = $result['error'] ?? $this->translator->trans('flash.edit.failed');
            return $response->withHeader('Location', '/antrag/' . $absenceId . '/edit')->withStatus(302);
        }

        $_SESSION['flash_success'] = $result['success'] ?? $this->translator->trans('flash.antrag.updated');
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    private function redirectWithError(Response $response, string $msg): Response
    {
        $_SESSION['flash_error'] = $msg;
        return $response->withHeader('Location', '/antrag/neu')->withStatus(302);
    }
}
