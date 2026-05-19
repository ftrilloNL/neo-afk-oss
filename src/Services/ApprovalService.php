<?php declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Database\Connection;
use App\Models\AbsenceRepository;
use App\Models\ApprovalTokenRepository;
use App\Models\AuditLogRepository;
use App\Models\UserRepository;
use App\Providers\Contracts\CalendarProvider;
use App\Providers\Contracts\OooProvider;
use Symfony\Component\Translation\Translator;

final class ApprovalService
{
    public function __construct(
        private readonly AbsenceRepository $absences,
        private readonly ApprovalTokenRepository $tokens,
        private readonly UserRepository $users,
        private readonly ResturlaubService $resturlaub,
        private readonly CalendarProvider $calendar,
        private readonly OooProvider $ooo,
        private readonly MailService $mail,
        private readonly AuditLogRepository $audit,
        private readonly Config $config,
        private readonly Connection $db,
        private readonly Translator $translator,
    ) {
    }

    /**
     * Generiert je ein Approve- und Reject-Token, speichert sie hashed in der DB
     * und schickt eine Approval-Mail an die Genehmiger:in mit beiden Magic-Links.
     */
    public function requestApproval(int $absenceId): void
    {
        $absence = $this->absences->findById($absenceId);
        if ($absence === null) {
            throw new \RuntimeException("Absence {$absenceId} not found");
        }

        $applicant = $this->users->findById((int) $absence['user_id']);
        $genehmiger = $this->users->findById((int) $absence['genehmiger_id']);
        if ($applicant === null || $genehmiger === null) {
            throw new \RuntimeException("Applicant or Genehmiger not found for absence {$absenceId}");
        }

        $expiresAt = new \DateTimeImmutable('+7 days');
        $approveToken = $this->generateAndStoreToken($absenceId, 'approve', $expiresAt);
        $rejectToken = $this->generateAndStoreToken($absenceId, 'reject', $expiresAt);

        $appUrl = $this->config->appUrl();
        $this->mail->send(
            (string) $genehmiger['email'],
            sprintf('Urlaubsantrag von %s — Genehmigung erforderlich', $applicant['display_name']),
            'mails/approval-request.twig',
            [
                'genehmiger' => $genehmiger,
                'applicant' => $applicant,
                'absence' => $absence,
                'approve_url' => $appUrl . '/approval/' . $approveToken,
                'reject_url' => $appUrl . '/approval/' . $rejectToken,
                'expires_at' => $expiresAt,
            ]
        );

        $this->audit->log(
            (int) $applicant['id'],
            'absence.approval_requested',
            'absence',
            $absenceId,
            ['genehmiger_id' => $genehmiger['id']]
        );
    }

    /**
     * Validiert den eingehenden plain Token gegen die DB, gibt Token-Zeile + Absence zurueck.
     *
     * @return array{token: array<string, mixed>, absence: array<string, mixed>}|null
     */
    public function lookupValidToken(string $plainToken): ?array
    {
        $hash = hash('sha256', $plainToken);
        $tokenRow = $this->tokens->findValidByHash($hash);
        if ($tokenRow === null) {
            return null;
        }
        $absence = $this->absences->findById((int) $tokenRow['absence_id']);
        if ($absence === null) {
            return null;
        }
        return ['token' => $tokenRow, 'absence' => $absence];
    }

    /**
     * Verarbeitet eine Token-basierte Approval-Action (Magic-Link aus Mail).
     * Markiert den Token als used und fuehrt die Aktion aus.
     *
     * @param array<string, mixed> $tokenRow
     * @param array<string, mixed> $absence
     */
    public function processTokenAction(array $tokenRow, array $absence, string $rejectComment = ''): void
    {
        $this->tokens->markUsed((int) $tokenRow['id']);
        // Andere offene Tokens fuer dieselbe Absence ungueltig machen (z.B. der Reject-Token, wenn Approve geklickt)
        $this->tokens->invalidateAllForAbsence((int) $absence['id']);

        $this->executeAction($absence, (string) $tokenRow['action'], $rejectComment);
    }

    /**
     * Verarbeitet eine direkte In-App-Aktion (Genehmiger-Liste).
     * Validiert dass die aufrufende Person tatsaechlich der/die Genehmiger:in ist
     * und der Antrag noch im Status 'beantragt' ist.
     *
     * @param array<string, mixed> $absence
     * @throws \RuntimeException wenn nicht autorisiert oder Status falsch
     */
    public function processInAppAction(int $callingUserId, array $absence, string $action, string $rejectComment = ''): void
    {
        if ((int) $absence['genehmiger_id'] !== $callingUserId) {
            throw new \RuntimeException($this->translator->trans('service.approval.not_genehmiger'));
        }
        if ($absence['status'] !== 'beantragt') {
            throw new \RuntimeException($this->translator->trans(
                'service.approval.already_processed',
                ['%status%' => $this->translator->trans('status.' . $absence['status'])]
            ));
        }
        if (!in_array($action, ['approve', 'reject'], true)) {
            throw new \RuntimeException("Ungueltige Aktion: {$action}");
        }

        $this->tokens->invalidateAllForAbsence((int) $absence['id']);
        $this->executeAction($absence, $action, $rejectComment);
    }

    /**
     * Kern-Logik der Approve/Reject-Aktion. Reihenfolge bei Approve:
     *
     *  1. Graph-Call (Kalender-Event anlegen). Wenn das scheitert: nichts geaendert,
     *     Exception bubbled hoch, User sieht Fehler.
     *  2. DB-Transaction (Resturlaub-Deduction + Status-Update + Audit) — atomic.
     *     Bei Rollback: compensating Graph-Delete des grad angelegten Events.
     *  3. Bestaetigungs-Mail — best-effort, kein Re-Throw bei Fehler (DB-State stimmt
     *     schon, Mail-Outage soll Approve nicht ruecksetzen).
     *
     * Bei Reject ist die Reihenfolge einfacher: DB-Transaction (Status + Audit) → Mail.
     *
     * @param array<string, mixed> $absence
     */
    private function executeAction(array $absence, string $action, string $rejectComment = ''): void
    {
        $absenceId = (int) $absence['id'];
        $applicant = $this->users->findById((int) $absence['user_id']);
        if ($applicant === null) {
            throw new \RuntimeException("Applicant not found for absence {$absenceId}");
        }

        if ($action === 'approve') {
            // 0. Re-Approval-Fall: wenn schon ein Event existiert (aus vorheriger
            //    Approve, bevor User-Edit Status auf `beantragt` zurueckgesetzt hat),
            //    erst loeschen. Best-effort.
            if (!empty($absence['kalender_event_id'])) {
                try {
                    $this->calendar->deleteEvent((string) $absence['kalender_event_id']);
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        'ApprovalService: alter Kalender-Event %s konnte nicht geloescht werden (re-approval von absence %d): %s',
                        $absence['kalender_event_id'],
                        $absenceId,
                        $e->getMessage(),
                    ));
                }
            }

            // 1. Graph-Call zuerst — Side-Effect, kann scheitern.
            $eventStart = new \DateTimeImmutable($absence['startdatum']);
            $eventEnd = (new \DateTimeImmutable($absence['enddatum']))->modify('+1 day');
            $subject = sprintf('[URLAUB] %s', $applicant['display_name']);
            $eventId = $this->calendar->createEvent($subject, $eventStart, $eventEnd, true);

            // 2. DB-Transaction — atomic Resturlaub + Status + Audit.
            $dbal = $this->db->dbal();
            $dbal->beginTransaction();
            try {
                $deduction = $this->resturlaub->deductFromUser($applicant, (float) $absence['tage_gezaehlt']);
                $this->absences->update($absenceId, [
                    'status' => 'aktiv',
                    'kalender_event_id' => $eventId,
                ]);
                $this->audit->log(
                    null,
                    'absence.approved',
                    'absence',
                    $absenceId,
                    ['vorjahr_used' => $deduction['vorjahr_used'], 'aktuell_used' => $deduction['aktuell_used']],
                );
                $dbal->commit();
            } catch (\Throwable $e) {
                $dbal->rollBack();
                // Compensating: gerade angelegtes Calendar-Event wieder loeschen,
                // sonst dangling. Best-effort — wenn auch dieser Delete scheitert,
                // dann hat HR halt einen verwaisten Event, ist im Vergleich harmlos.
                try {
                    $this->calendar->deleteEvent($eventId);
                } catch (\Throwable $cleanupErr) {
                    error_log(sprintf(
                        'ApprovalService: rollback-compensating delete of event %s fehlgeschlagen: %s',
                        $eventId,
                        $cleanupErr->getMessage(),
                    ));
                }
                throw $e;
            }

            // 3. Auto-OOO setzen — aber NUR wenn der Urlaub heute oder schon
            //    in der Vergangenheit startet. Zukuenftige Urlaube werden vom
            //    Cron cron/ooo-sync.php am Start-Tag aktiviert, sonst gibt's
            //    Konflikte zwischen mehreren approved-zukuenftigen OOOs
            //    (Microsoft hat nur einen mailboxSettings-Slot pro Mailbox).
            $startDate = new \DateTimeImmutable($absence['startdatum']);
            $today = new \DateTimeImmutable('today');
            if ($startDate <= $today) {
                try {
                    $endFmt = (new \DateTimeImmutable($absence['enddatum']))->format('d.m.Y');
                    $fallback = sprintf(
                        '<p>Ich bin vom %s bis %s außer Haus. Ihre Nachrichten werden in der Zwischenzeit nicht gelesen. Bei dringenden Anliegen wenden Sie sich bitte an meine Kolleg:innen.</p>',
                        $startDate->format('d.m.Y'),
                        $endFmt,
                    );
                    $internal = !empty($absence['ooo_internal'])
                        ? $this->renderOooText((string) $absence['ooo_internal'])
                        : $fallback;
                    $external = !empty($absence['ooo_external'])
                        ? $this->renderOooText((string) $absence['ooo_external'])
                        : $fallback;

                    $this->ooo->setAutoReply(
                        (string) $applicant['email'],
                        $startDate,
                        new \DateTimeImmutable($absence['enddatum']),
                        $internal,
                        $external,
                    );
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        'ApprovalService: Auto-OOO fuer %s fehlgeschlagen: %s',
                        $applicant['email'],
                        $e->getMessage(),
                    ));
                }
            }

            // 4. Bestaetigungs-Mail — best-effort.
            try {
                $this->mail->send(
                    (string) $applicant['email'],
                    sprintf('Urlaub genehmigt — %s bis %s',
                        (new \DateTimeImmutable($absence['startdatum']))->format('d.m.'),
                        (new \DateTimeImmutable($absence['enddatum']))->format('d.m.Y')
                    ),
                    'mails/approval-decision.twig',
                    [
                        'applicant' => $applicant,
                        'absence' => $absence,
                        'decision' => 'approved',
                        'comment' => '',
                    ]
                );
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'ApprovalService: Bestaetigungs-Mail (approved) an %s fehlgeschlagen: %s',
                    $applicant['email'],
                    $e->getMessage(),
                ));
            }
        } else { // reject
            $dbal = $this->db->dbal();
            $dbal->beginTransaction();
            try {
                $this->absences->update($absenceId, [
                    'status' => 'abgelehnt',
                    'begruendung_ablehnung' => $rejectComment,
                    'kalender_event_id' => null,
                ]);
                $this->audit->log(
                    null,
                    'absence.rejected',
                    'absence',
                    $absenceId,
                    ['comment' => $rejectComment],
                );
                $dbal->commit();
            } catch (\Throwable $e) {
                $dbal->rollBack();
                throw $e;
            }

            // Re-Approval-Reject-Fall: alter Kalender-Event + OOO koennen noch vom
            //  ursprünglichen Approve haengen. Best-effort cleanup.
            if (!empty($absence['kalender_event_id'])) {
                try {
                    $this->calendar->deleteEvent((string) $absence['kalender_event_id']);
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        'ApprovalService: Kalender-Event %s nach Reject nicht geloescht (absence %d): %s',
                        $absence['kalender_event_id'],
                        $absenceId,
                        $e->getMessage(),
                    ));
                }
            }
            $startDate = new \DateTimeImmutable($absence['startdatum']);
            $endDate = new \DateTimeImmutable($absence['enddatum']);
            $today = new \DateTimeImmutable('today');
            if ($today >= $startDate && $today <= $endDate) {
                try {
                    $this->ooo->clearAutoReplyIfOurs((string) $applicant['email']);
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        'ApprovalService: OOO-Clear nach Reject fehlgeschlagen fuer %s: %s',
                        $applicant['email'],
                        $e->getMessage(),
                    ));
                }
            }

            try {
                $this->mail->send(
                    (string) $applicant['email'],
                    sprintf('Urlaub abgelehnt — %s bis %s',
                        (new \DateTimeImmutable($absence['startdatum']))->format('d.m.'),
                        (new \DateTimeImmutable($absence['enddatum']))->format('d.m.Y')
                    ),
                    'mails/approval-decision.twig',
                    [
                        'applicant' => $applicant,
                        'absence' => $absence,
                        'decision' => 'rejected',
                        'comment' => $rejectComment,
                    ]
                );
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'ApprovalService: Bestaetigungs-Mail (rejected) an %s fehlgeschlagen: %s',
                    $applicant['email'],
                    $e->getMessage(),
                ));
            }
        }
    }

    /**
     * Verpackt den vom User eingegebenen Plain-Text in einfaches HTML —
     * htmlspecialchars um XSS zu verhindern, Zeilenumbrueche werden <br>.
     * User koennen keine eigenen Tags einbetten.
     */
    private function renderOooText(string $plain): string
    {
        $escaped = htmlspecialchars($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '<p>' . nl2br($escaped) . '</p>';
    }

    private function generateAndStoreToken(int $absenceId, string $action, \DateTimeImmutable $expiresAt): string
    {
        $plain = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plain);
        $this->tokens->insert($absenceId, $hash, $action, $expiresAt);
        return $plain;
    }
}
