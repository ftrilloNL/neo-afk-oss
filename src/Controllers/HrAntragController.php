<?php declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\I18n\LocalizedDate;
use App\Models\AbsenceRepository;
use App\Models\AuditLogRepository;
use App\Models\UserRepository;
use App\Providers\Contracts\CalendarProvider;
use App\Providers\Contracts\OooProvider;
use App\Services\MailService;
use App\Services\ResturlaubService;
use App\Services\WerktageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Symfony\Component\Translation\Translator;

final class HrAntragController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AbsenceRepository $absences,
        private readonly WerktageService $werktage,
        private readonly CalendarProvider $calendar,
        private readonly OooProvider $ooo,
        private readonly MailService $mail,
        private readonly ResturlaubService $resturlaub,
        private readonly AuditLogRepository $audit,
        private readonly Connection $db,
        private readonly Twig $view,
        private readonly Translator $translator,
        private readonly LocalizedDate $dates,
    ) {
    }

    public function neu(Request $request, Response $response): Response
    {
        $hrUser = $this->users->findById((int) $_SESSION['user_id']);

        $flashError = $_SESSION['flash_error'] ?? null;
        $form = $_SESSION['hr_antrag_form'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['hr_antrag_form']);

        return $this->view->render($response, 'hr/antrag/neu.twig', [
            'user' => $hrUser,
            'active_nav' => 'hr',
            'all_users' => $this->users->listAllActive(),
            'today' => date('Y-m-d'),
            'flash_error' => $flashError,
            'form' => $form,
        ]);
    }

    /**
     * HTMX-Snippet: Werktage + Resturlaub des ausgewählten Mitarbeiters.
     * for_user_id wird aus dem Formular mitgeschickt.
     */
    public function previewTage(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        $forUserId = isset($params['fuer_user_id']) && $params['fuer_user_id'] !== ''
            ? (int) $params['fuer_user_id']
            : null;
        $user = $forUserId !== null ? $this->users->findById($forUserId) : null;

        $startStr = trim((string) ($params['startdatum'] ?? ''));
        $endStr = trim((string) ($params['enddatum'] ?? ''));
        $halbtagStart = (string) ($params['halbtag_start'] ?? 'ganztag');
        $halbtagEnde = (string) ($params['halbtag_ende'] ?? 'ganztag');

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
        if ($user !== null) {
            $verfuegbar = (float) $user['resturlaub_aktuell'] + (float) $user['resturlaub_vorjahr'];
            $ueberzogen = $tage > $verfuegbar;
        }

        return $this->view->render($response, 'partials/tage-preview.twig', [
            'state' => 'ok',
            'tage' => $tage,
            'verfuegbar' => $verfuegbar,
            'ueberzogen' => $ueberzogen,
            'art' => 'urlaub',
        ]);
    }

    public function submit(Request $request, Response $response): Response
    {
        unset($_SESSION['flash_error']);
        $hrUserId = (int) $_SESSION['user_id'];

        $body = (array) ($request->getParsedBody() ?? []);

        $fuerUserId = (int) ($body['fuer_user_id'] ?? 0);
        if ($fuerUserId === 0) {
            return $this->redirectWithError($response, $this->translator->trans('flash.hr.select_employee'), $body);
        }
        $targetUser = $this->users->findById($fuerUserId);
        if ($targetUser === null || !(bool) $targetUser['ist_aktiv']) {
            return $this->redirectWithError($response, $this->translator->trans('flash.hr.employee_not_found'), $body);
        }

        $startStr = trim((string) ($body['startdatum'] ?? ''));
        $endStr = trim((string) ($body['enddatum'] ?? ''));
        $halbtagStart = (string) ($body['halbtag_start'] ?? 'ganztag');
        $halbtagEnde = (string) ($body['halbtag_ende'] ?? 'ganztag');
        $notiz = trim((string) ($body['notiz'] ?? ''));
        $sendNotification = !empty($body['send_notification']);

        if ($startStr === '' || $endStr === '') {
            return $this->redirectWithError($response, $this->translator->trans('flash.hr.start_end_required'), $body);
        }
        try {
            $start = new \DateTimeImmutable($startStr);
            $end = new \DateTimeImmutable($endStr);
        } catch (\Exception) {
            return $this->redirectWithError($response, $this->translator->trans('flash.common.invalid_date'), $body);
        }
        if ($end < $start) {
            return $this->redirectWithError($response, $this->translator->trans('flash.common.end_before_start'), $body);
        }
        if (!in_array($halbtagStart, ['ganztag', 'nachmittag'], true)) {
            $halbtagStart = 'ganztag';
        }
        if (!in_array($halbtagEnde, ['ganztag', 'vormittag'], true)) {
            $halbtagEnde = 'ganztag';
        }

        $tageGezaehlt = $this->werktage->compute($start, $end, $halbtagStart, $halbtagEnde);
        if ($tageGezaehlt <= 0) {
            return $this->redirectWithError($response, $this->translator->trans('flash.common.no_workdays'), $body);
        }

        $verfuegbar = (float) $targetUser['resturlaub_aktuell'] + (float) $targetUser['resturlaub_vorjahr'];
        if ($tageGezaehlt > $verfuegbar) {
            return $this->redirectWithError($response, $this->translator->trans(
                'flash.hr.antrag.insufficient_resturlaub',
                [
                    '%tage%' => number_format($tageGezaehlt, 1, ',', '.'),
                    '%verfuegbar%' => number_format($verfuegbar, 1, ',', '.'),
                ]
            ), $body);
        }

        $unifiedOoo = trim((string) ($body['ooo_text'] ?? ''));
        if ($unifiedOoo !== '') {
            $oooInternal = $unifiedOoo;
            $oooExternal = $unifiedOoo;
        } else {
            $oooInternal = trim((string) ($body['ooo_internal'] ?? ''));
            $oooExternal = trim((string) ($body['ooo_external'] ?? ''));
        }

        // 1. Kalender-Event anlegen (seiteneffekt, kann scheitern — noch nix committed)
        $eventStart = $start;
        $eventEnd = $end->modify('+1 day');
        $subject = $this->translator->trans('calendar.subject.urlaub', ['%name%' => $targetUser['display_name']]);
        $eventId = $this->calendar->createEvent($subject, $eventStart, $eventEnd, true);

        // 2. DB-Transaction: Absence einfügen + Resturlaub abbuchen + Audit atomar
        $dbal = $this->db->dbal();
        $dbal->beginTransaction();
        try {
            $absenceId = $this->absences->insert([
                'user_id' => $fuerUserId,
                'art' => 'urlaub',
                'startdatum' => $start->format('Y-m-d'),
                'enddatum' => $end->format('Y-m-d'),
                'halbtag_start' => $halbtagStart,
                'halbtag_ende' => $halbtagEnde,
                'tage_gezaehlt' => $tageGezaehlt,
                'status' => 'aktiv',
                'genehmiger_id' => null,
                'notiz' => $notiz !== '' ? $notiz : null,
                'kalender_event_id' => $eventId,
                'ooo_internal' => $oooInternal !== '' ? $oooInternal : null,
                'ooo_external' => $oooExternal !== '' ? $oooExternal : null,
            ]);
            $deduction = $this->resturlaub->deductFromUser($targetUser, $tageGezaehlt);
            $this->audit->log(
                $hrUserId,
                'absence.hr_created',
                'absence',
                $absenceId,
                [
                    'fuer_user_id' => $fuerUserId,
                    'art' => 'urlaub',
                    'tage_gezaehlt' => $tageGezaehlt,
                    'vorjahr_used' => $deduction['vorjahr_used'],
                    'aktuell_used' => $deduction['aktuell_used'],
                ]
            );
            $dbal->commit();
        } catch (\Throwable $e) {
            $dbal->rollBack();
            // Kompensierendes Delete des gerade angelegten Calendar-Events
            try {
                $this->calendar->deleteEvent($eventId);
            } catch (\Throwable $cleanupErr) {
                error_log(sprintf(
                    'HrAntragController: compensating delete of event %s fehlgeschlagen: %s',
                    $eventId,
                    $cleanupErr->getMessage(),
                ));
            }
            throw $e;
        }

        // 3. Auto-OOO — nur wenn Urlaub heute oder bereits laufend startet (wie ApprovalService)
        $today = new \DateTimeImmutable('today');
        if ($start <= $today && ($oooInternal !== '' || $oooExternal !== '')) {
            $renderOoo = fn (string $plain): string =>
                '<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>';
            $finalInt = $oooInternal !== '' ? $oooInternal : $oooExternal;
            $finalExt = $oooExternal !== '' ? $oooExternal : $oooInternal;
            try {
                $this->ooo->setAutoReply(
                    (string) $targetUser['email'],
                    $start,
                    $end,
                    $renderOoo($finalInt),
                    $renderOoo($finalExt),
                );
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'HrAntragController: Auto-OOO fuer %s fehlgeschlagen: %s',
                    $targetUser['email'],
                    $e->getMessage(),
                ));
            }
        }

        // 4. Info-Mail an Mitarbeiter:in (best-effort, nur wenn HR-Checkbox gesetzt)
        if ($sendNotification) {
            try {
                $this->mail->send(
                    (string) $targetUser['email'],
                    sprintf(
                        'Urlaub von HR erfasst — %s bis %s',
                        $this->dates->monthDay($start),
                        $this->dates->short($end),
                    ),
                    'mails/hr-erfassung-notif.twig',
                    [
                        'target' => $targetUser,
                        'art' => 'urlaub',
                        'startdatum' => $start,
                        'enddatum' => $end,
                        'tage' => $tageGezaehlt,
                        'notiz' => $notiz,
                    ]
                );
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'HrAntragController: Info-Mail an %s fehlgeschlagen: %s',
                    $targetUser['email'],
                    $e->getMessage(),
                ));
            }
        }

        $_SESSION['flash_success'] = $this->translator->trans(
            'flash.hr.antrag.created',
            [
                '%name%' => $targetUser['display_name'],
                '%start%' => $this->dates->monthDay($start),
                '%end%' => $this->dates->short($end),
                '%tage%' => number_format($tageGezaehlt, 1, ',', '.'),
            ]
        );
        return $response->withHeader('Location', '/hr')->withStatus(302);
    }

    private function redirectWithError(Response $response, string $msg, array $body = []): Response
    {
        $_SESSION['flash_error'] = $msg;
        if (!empty($body)) {
            $_SESSION['hr_antrag_form'] = $body;
        }
        return $response->withHeader('Location', '/hr/antrag/neu')->withStatus(302);
    }
}
