<?php declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Models\AbsenceRepository;
use App\Models\AuditLogRepository;
use App\Models\UserRepository;
use App\Providers\Contracts\CalendarProvider;
use App\Providers\Contracts\OooProvider;
use App\Services\MailService;
use App\Services\WerktageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class HrKrankController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AbsenceRepository $absences,
        private readonly WerktageService $werktage,
        private readonly CalendarProvider $calendar,
        private readonly OooProvider $ooo,
        private readonly MailService $mail,
        private readonly AuditLogRepository $audit,
        private readonly Config $config,
        private readonly Twig $view,
    ) {
    }

    public function neu(Request $request, Response $response): Response
    {
        $hrUser = $this->users->findById((int) $_SESSION['user_id']);

        $flashError = $_SESSION['flash_error'] ?? null;
        $form = $_SESSION['hr_krank_form'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['hr_krank_form']);

        return $this->view->render($response, 'hr/krank/neu.twig', [
            'user' => $hrUser,
            'active_nav' => 'hr',
            'all_users' => $this->users->listAllActive(),
            'today' => date('Y-m-d'),
            'flash_error' => $flashError,
            'form' => $form,
        ]);
    }

    public function submit(Request $request, Response $response): Response
    {
        unset($_SESSION['flash_error']);
        $hrUserId = (int) $_SESSION['user_id'];

        $body = (array) ($request->getParsedBody() ?? []);

        $fuerUserId = (int) ($body['fuer_user_id'] ?? 0);
        if ($fuerUserId === 0) {
            return $this->redirectWithError($response, 'Bitte eine:n Mitarbeiter:in auswählen.', $body);
        }
        $targetUser = $this->users->findById($fuerUserId);
        if ($targetUser === null || !(bool) $targetUser['ist_aktiv']) {
            return $this->redirectWithError($response, 'Mitarbeiter:in nicht gefunden oder inaktiv.', $body);
        }

        $startStr = trim((string) ($body['startdatum'] ?? ''));
        $endStr = trim((string) ($body['enddatum'] ?? ''));
        $halbtagStart = (string) ($body['halbtag_start'] ?? 'ganztag');
        $halbtagEnde = (string) ($body['halbtag_ende'] ?? 'ganztag');
        $notiz = trim((string) ($body['notiz'] ?? ''));
        $sendNotification = !empty($body['send_notification']);

        $unifiedOoo = trim((string) ($body['ooo_text'] ?? ''));
        if ($unifiedOoo !== '') {
            $oooInternal = $unifiedOoo;
            $oooExternal = $unifiedOoo;
        } else {
            $oooInternal = trim((string) ($body['ooo_internal'] ?? ''));
            $oooExternal = trim((string) ($body['ooo_external'] ?? ''));
        }

        if ($startStr === '' || $endStr === '') {
            return $this->redirectWithError($response, 'Bitte Start- und Enddatum ausfüllen.', $body);
        }
        try {
            $start = new \DateTimeImmutable($startStr);
            $end = new \DateTimeImmutable($endStr);
        } catch (\Exception) {
            return $this->redirectWithError($response, 'Ungültiges Datum.', $body);
        }
        if ($end < $start) {
            return $this->redirectWithError($response, 'Enddatum darf nicht vor Startdatum liegen.', $body);
        }
        if (!in_array($halbtagStart, ['ganztag', 'nachmittag'], true)) {
            $halbtagStart = 'ganztag';
        }
        if (!in_array($halbtagEnde, ['ganztag', 'vormittag'], true)) {
            $halbtagEnde = 'ganztag';
        }

        $tageGezaehlt = $this->werktage->compute($start, $end, $halbtagStart, $halbtagEnde);

        // DSGVO-konform: Kalender-Subject zeigt nur "Abwesend", nicht "Krank"
        $eventStart = $start;
        $eventEnd = $end->modify('+1 day');
        $subject = sprintf('Abwesend – %s', $targetUser['display_name']);
        $eventId = $this->calendar->createEvent($subject, $eventStart, $eventEnd, true);

        $absenceId = $this->absences->insert([
            'user_id' => $fuerUserId,
            'art' => 'krank',
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

        $this->audit->log(
            $hrUserId,
            'absence.hr_created',
            'absence',
            $absenceId,
            [
                'fuer_user_id' => $fuerUserId,
                'art' => 'krank',
                'tage_gezaehlt' => $tageGezaehlt,
            ]
        );

        // Optionaler Auto-OOO für den/die Mitarbeiter:in (best-effort)
        if ($oooInternal !== '' || $oooExternal !== '') {
            $finalInt = $oooInternal !== '' ? $oooInternal : $oooExternal;
            $finalExt = $oooExternal !== '' ? $oooExternal : $oooInternal;
            $renderOoo = fn (string $plain): string =>
                '<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>';
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
                    'HrKrankController: Auto-OOO fuer %s fehlgeschlagen: %s',
                    $targetUser['email'],
                    $e->getMessage(),
                ));
            }
        }

        // HR-Notification (wie beim regulären KrankController)
        $hrEmail = $this->config->get('HR_NOTIFICATION_EMAIL');
        try {
            $this->mail->send(
                $hrEmail,
                sprintf(
                    '[HR erfasst] Krankmeldung: %s (%s–%s)',
                    $targetUser['display_name'],
                    $start->format('d.m.'),
                    $end->format('d.m.Y')
                ),
                'mails/krank-notif.twig',
                [
                    'user' => $targetUser,
                    'startdatum' => $start,
                    'enddatum' => $end,
                    'tage' => $tageGezaehlt,
                    'notiz' => $notiz,
                ]
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                'HrKrankController: HR-Notification fehlgeschlagen: %s',
                $e->getMessage(),
            ));
        }

        // Info-Mail an Mitarbeiter:in (best-effort, nur wenn HR-Checkbox gesetzt)
        if ($sendNotification) {
            try {
                $this->mail->send(
                    (string) $targetUser['email'],
                    sprintf(
                        'Krankmeldung von HR erfasst — %s bis %s',
                        $start->format('d.m.'),
                        $end->format('d.m.Y')
                    ),
                    'mails/hr-erfassung-notif.twig',
                    [
                        'target' => $targetUser,
                        'art' => 'krank',
                        'startdatum' => $start,
                        'enddatum' => $end,
                        'tage' => $tageGezaehlt,
                        'notiz' => $notiz,
                    ]
                );
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'HrKrankController: Info-Mail an %s fehlgeschlagen: %s',
                    $targetUser['email'],
                    $e->getMessage(),
                ));
            }
        }

        $_SESSION['flash_success'] = sprintf(
            'Krankmeldung für %s erfasst (%s bis %s).',
            $targetUser['display_name'],
            $start->format('d.m.'),
            $end->format('d.m.Y')
        );
        return $response->withHeader('Location', '/hr')->withStatus(302);
    }

    private function redirectWithError(Response $response, string $msg, array $body = []): Response
    {
        $_SESSION['flash_error'] = $msg;
        if (!empty($body)) {
            $_SESSION['hr_krank_form'] = $body;
        }
        return $response->withHeader('Location', '/hr/krank/neu')->withStatus(302);
    }
}
