<?php declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Models\AbsenceRepository;
use App\Models\AuditLogRepository;
use App\Models\UserRepository;
use App\Services\GraphClient;
use App\Services\MailService;
use App\Services\WerktageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class KrankController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AbsenceRepository $absences,
        private readonly WerktageService $werktage,
        private readonly GraphClient $graph,
        private readonly MailService $mail,
        private readonly AuditLogRepository $audit,
        private readonly Config $config,
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

        return $this->view->render($response, 'krank/neu.twig', [
            'user' => $user,
            'active_nav' => 'krank',
            'today' => date('Y-m-d'),
            'flash_error' => $_SESSION['flash_error'] ?? null,
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
        $notiz = trim((string) ($body['notiz'] ?? ''));
        $oooInternal = trim((string) ($body['ooo_internal'] ?? ''));
        $oooExternal = trim((string) ($body['ooo_external'] ?? ''));

        if ($startStr === '' || $endStr === '') {
            $_SESSION['flash_error'] = 'Bitte Datums-Felder ausfüllen.';
            return $response->withHeader('Location', '/krank/neu')->withStatus(302);
        }
        try {
            $start = new \DateTimeImmutable($startStr);
            $end = new \DateTimeImmutable($endStr);
        } catch (\Exception) {
            $_SESSION['flash_error'] = 'Ungültiges Datum.';
            return $response->withHeader('Location', '/krank/neu')->withStatus(302);
        }
        if ($end < $start) {
            $_SESSION['flash_error'] = 'Enddatum darf nicht vor Startdatum liegen.';
            return $response->withHeader('Location', '/krank/neu')->withStatus(302);
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
        $subject = sprintf('Abwesend – %s', $user['display_name']);
        $eventId = $this->graph->createCalendarEvent($subject, $eventStart, $eventEnd, true);

        $absenceId = $this->absences->insert([
            'user_id' => $userId,
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
            $userId,
            'absence.created',
            'absence',
            $absenceId,
            ['art' => 'krank', 'tage_gezaehlt' => $tageGezaehlt]
        );

        // Optionaler Auto-OOO — anders als bei Urlaub OHNE Fallback. Wenn beide Textareas
        // leer waren, wird kein OOO gesetzt. Wenn nur einer ausgefuellt: fuer beide nutzen.
        if ($oooInternal !== '' || $oooExternal !== '') {
            $finalInt = $oooInternal !== '' ? $oooInternal : $oooExternal;
            $finalExt = $oooExternal !== '' ? $oooExternal : $oooInternal;
            $renderOoo = fn (string $plain): string =>
                '<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>';
            try {
                $this->graph->setAutoReply(
                    (string) $user['email'],
                    $start,
                    $end,
                    $renderOoo($finalInt),
                    $renderOoo($finalExt),
                );
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'KrankController: Auto-OOO fuer %s fehlgeschlagen: %s',
                    $user['email'],
                    $e->getMessage(),
                ));
            }
        }

        // HR-Notification
        $hrEmail = $this->config->get('HR_NOTIFICATION_EMAIL');
        $this->mail->send(
            $hrEmail,
            sprintf(
                'Krankmeldung: %s (%s–%s)',
                $user['display_name'],
                $start->format('d.m.'),
                $end->format('d.m.Y')
            ),
            'mails/krank-notif.twig',
            [
                'user' => $user,
                'startdatum' => $start,
                'enddatum' => $end,
                'tage' => $tageGezaehlt,
                'notiz' => $notiz,
            ]
        );

        $_SESSION['flash_success'] = 'Krankmeldung erfasst. HR wurde benachrichtigt. Gute Besserung!';
        return $response->withHeader('Location', '/')->withStatus(302);
    }
}
