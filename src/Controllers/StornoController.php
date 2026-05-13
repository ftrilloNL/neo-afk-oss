<?php declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\Models\AbsenceRepository;
use App\Models\AuditLogRepository;
use App\Models\UserRepository;
use App\Providers\Contracts\CalendarProvider;
use App\Providers\Contracts\OooProvider;
use App\Services\MailService;
use App\Services\ResturlaubService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class StornoController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AbsenceRepository $absences,
        private readonly ResturlaubService $resturlaub,
        private readonly CalendarProvider $calendar,
        private readonly OooProvider $ooo,
        private readonly MailService $mail,
        private readonly AuditLogRepository $audit,
        private readonly Connection $db,
    ) {
    }

    public function storno(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $_SESSION['user_id'];
        $absenceId = (int) ($args['id'] ?? 0);

        $absence = $this->absences->findById($absenceId);
        $currentUser = $this->users->findById($userId);
        if ($absence === null || $currentUser === null) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $isOwner = (int) $absence['user_id'] === $userId;
        $isHr = (bool) $currentUser['ist_hr'];
        if (!$isOwner && !$isHr) {
            $_SESSION['flash_error'] = 'Du darfst diesen Antrag nicht stornieren.';
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        if (in_array($absence['status'], ['storniert', 'abgelehnt'], true)) {
            $_SESSION['flash_error'] = 'Antrag ist bereits ' . $absence['status'] . '.';
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        // event_id merken, weil DB-Update sie gleich auf NULL setzt.
        $eventIdToDelete = !empty($absence['kalender_event_id'])
            ? (string) $absence['kalender_event_id']
            : null;

        // 1. DB-Transaction: Refund + Status + Audit atomar.
        $dbal = $this->db->dbal();
        $dbal->beginTransaction();
        try {
            // Rueckbuchung NUR auf Aktuell, nicht auf Vorjahr (Vereinfachung wie im Power-Platform-Vorgaenger)
            if ($absence['art'] === 'urlaub' && $absence['status'] === 'aktiv') {
                $this->resturlaub->refundToAktuell((int) $absence['user_id'], (float) $absence['tage_gezaehlt']);
            }
            $this->absences->update($absenceId, [
                'status' => 'storniert',
                'kalender_event_id' => null,
            ]);
            $this->audit->log(
                $userId,
                'absence.cancelled',
                'absence',
                $absenceId,
                ['previous_status' => $absence['status']],
            );
            $dbal->commit();
        } catch (\Throwable $e) {
            $dbal->rollBack();
            throw $e;
        }

        // 2. Calendar-Delete — best-effort. DB-State ist schon korrekt; ein verwaister
        //    Calendar-Event ist ok (HR kann manuell loeschen). 404 wird idempotent
        //    vom Provider behandelt.
        if ($eventIdToDelete !== null) {
            try {
                $this->calendar->deleteEvent($eventIdToDelete);
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'StornoController: Calendar-Event %s konnte nicht geloescht werden (Antrag %d): %s',
                    $eventIdToDelete,
                    $absenceId,
                    $e->getMessage(),
                ));
            }
        }

        // 2b. Auto-OOO zuruecksetzen — nur wenn der Urlaub/Krank gerade aktiv
        //     laeuft (heute zwischen startdatum und enddatum). Bei zukuenftigen
        //     Antraegen ist noch kein OOO am Mailbox gesetzt (Cron ooo-sync.php
        //     aktiviert OOO erst am Urlaubsstart). Bei vergangenen Antraegen
        //     hat Microsoft via scheduledEnd schon selbst ausgeschaltet.
        $applicant = $this->users->findById((int) $absence['user_id']);
        $today = new \DateTimeImmutable('today');
        $start = new \DateTimeImmutable($absence['startdatum']);
        $end = new \DateTimeImmutable($absence['enddatum']);
        $isOooActive = $today >= $start && $today <= $end;
        if ($applicant !== null && $absence['status'] === 'aktiv' && $isOooActive) {
            try {
                $this->ooo->clearAutoReplyIfOurs((string) $applicant['email']);
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'StornoController: Auto-OOO-Clear fuer %s fehlgeschlagen: %s',
                    $applicant['email'],
                    $e->getMessage(),
                ));
            }
        }

        // 3. Bestaetigungs-Mail — best-effort.
        if ($applicant !== null) {
            try {
                $this->mail->send(
                    (string) $applicant['email'],
                    'Stornierung bestätigt',
                    'mails/storno-confirmation.twig',
                    ['applicant' => $applicant, 'absence' => $absence],
                );
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'StornoController: Storno-Mail an %s fehlgeschlagen: %s',
                    $applicant['email'],
                    $e->getMessage(),
                ));
            }
        }

        $_SESSION['flash_success'] = 'Antrag storniert.';
        return $response->withHeader('Location', '/')->withStatus(302);
    }
}
