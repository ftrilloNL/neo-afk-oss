<?php declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Database\Connection;
use App\Models\AbsenceRepository;
use App\Models\ApprovalTokenRepository;
use App\Models\AuditLogRepository;
use App\Models\UserRepository;
use App\Providers\Contracts\CalendarProvider;
use App\I18n\LocalizedDate;
use App\Providers\Contracts\OooProvider;
use Symfony\Component\Translation\Translator;

/**
 * Bearbeitung bestehender Antraege + Krankmeldungen.
 *
 * Berechtigung: own ODER ist_hr. Status `storniert`/`abgelehnt` sind nicht editierbar.
 *
 * Re-Approval-Trigger (nur USER-Edit eigener Urlaubsantraege):
 *  - Trigger-Felder: startdatum, enddatum, halbtag_start, halbtag_ende (-> neue tage_gezaehlt)
 *  - Bei Trigger im Status `aktiv`: Status -> `beantragt`, Resturlaub refunden,
 *    alte Tokens invalidieren, ApprovalService::requestApproval erneut.
 *  - Bei Trigger im Status `beantragt`: alte Tokens invalidieren, requestApproval erneut.
 *  - Kalender + OOO bleiben unveraendert bis Re-Approval (Design-Entscheidung).
 *
 * HR-Edit (auch bei eigenen Antraegen, wenn HR-User): keine Status-Transition,
 * Resturlaub-Diff direkt anwenden, Kalender + OOO refreshen, Info-Mail an
 * Genehmiger:in (falls vorhanden und != HR-Editor).
 *
 * Krankmeldungen: kein Approval-Flow. Kalender + OOO refreshen bei Bedarf,
 * HR-Notification an HR_NOTIFICATION_EMAIL.
 */
final class AbsenceEditService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AbsenceRepository $absences,
        private readonly ApprovalTokenRepository $tokens,
        private readonly WerktageService $werktage,
        private readonly ResturlaubService $resturlaub,
        private readonly ApprovalService $approval,
        private readonly CalendarProvider $calendar,
        private readonly OooProvider $ooo,
        private readonly MailService $mail,
        private readonly AuditLogRepository $audit,
        private readonly Config $config,
        private readonly Connection $db,
        private readonly Translator $translator,
        private readonly LocalizedDate $dates,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, error?: string, success?: string}
     */
    public function editUrlaub(int $absenceId, int $editorUserId, array $input): array
    {
        $absence = $this->absences->findById($absenceId);
        $editor = $this->users->findById($editorUserId);
        if ($absence === null || $editor === null) {
            return ['ok' => false, 'error' => $this->translator->trans('service.edit.urlaub.not_found')];
        }
        if ($absence['art'] !== 'urlaub') {
            return ['ok' => false, 'error' => $this->translator->trans('service.edit.urlaub.not_urlaub')];
        }

        $authError = $this->authorizeEdit($absence, $editor);
        if ($authError !== null) {
            return ['ok' => false, 'error' => $authError];
        }

        $parsed = $this->parseAndValidate($input);
        if (isset($parsed['error'])) {
            return ['ok' => false, 'error' => $parsed['error']];
        }

        $applicant = $this->users->findById((int) $absence['user_id']);
        if ($applicant === null) {
            return ['ok' => false, 'error' => $this->translator->trans('service.edit.applicant_gone')];
        }

        $isHrEdit = (bool) $editor['ist_hr'];
        $isOwnerEdit = (int) $absence['user_id'] === $editorUserId;
        $hasGenehmiger = $absence['genehmiger_id'] !== null;
        $criticalChange = $this->hasCriticalChange($absence, $parsed);

        $oldTage = (float) $absence['tage_gezaehlt'];
        $newTage = $parsed['tage_gezaehlt'];
        $diffTage = $newTage - $oldTage;

        // Direct-Edit (kein Re-Approval) gilt in zwei Faellen:
        //  1. HR editiert fremden Antrag (HR autorisiert sich selbst, siehe Decision).
        //  2. Owner editiert HR-erfassten Antrag ohne Genehmiger:in — Re-Approval
        //     ginge nicht (kein Genehmiger zum Approven), wuerde sonst dead-lock.
        $useDirectFlow = ($isHrEdit && !$isOwnerEdit) || !$hasGenehmiger;
        if ($useDirectFlow) {
            return $this->applyDirectUrlaubEdit($absence, $applicant, $editor, $parsed, $diffTage, $criticalChange);
        }

        return $this->applyOwnerUrlaubEdit($absence, $applicant, $parsed, $diffTage, $criticalChange);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, error?: string, success?: string}
     */
    public function editKrank(int $absenceId, int $editorUserId, array $input): array
    {
        $absence = $this->absences->findById($absenceId);
        $editor = $this->users->findById($editorUserId);
        if ($absence === null || $editor === null) {
            return ['ok' => false, 'error' => $this->translator->trans('service.edit.krank.not_found')];
        }
        if ($absence['art'] !== 'krank') {
            return ['ok' => false, 'error' => $this->translator->trans('service.edit.krank.not_krank')];
        }

        $authError = $this->authorizeEdit($absence, $editor);
        if ($authError !== null) {
            return ['ok' => false, 'error' => $authError];
        }

        $parsed = $this->parseAndValidate($input);
        if (isset($parsed['error'])) {
            return ['ok' => false, 'error' => $parsed['error']];
        }

        $applicant = $this->users->findById((int) $absence['user_id']);
        if ($applicant === null) {
            return ['ok' => false, 'error' => $this->translator->trans('service.edit.target_gone')];
        }

        $criticalChange = $this->hasCriticalChange($absence, $parsed);

        $updates = $this->buildUpdateFields($parsed);
        $this->absences->update($absenceId, $updates);

        if ($criticalChange) {
            $this->refreshCalendar($absenceId, $absence, $parsed, $applicant, 'krank');
        }
        $this->refreshOoo($absence, $parsed, $applicant);

        $this->audit->log(
            $editorUserId,
            'absence.edited',
            'absence',
            $absenceId,
            $this->buildAuditDiff($absence, $parsed),
        );

        $this->notifyKrankEditToHr($applicant, $editor, $absence, $parsed);

        return ['ok' => true, 'success' => $this->translator->trans('service.edit.krank.saved')];
    }

    /** @param array<string, mixed> $absence @param array<string, mixed> $editor */
    private function authorizeEdit(array $absence, array $editor): ?string
    {
        $isOwner = (int) $absence['user_id'] === (int) $editor['id'];
        $isHr = (bool) $editor['ist_hr'];
        if (!$isOwner && !$isHr) {
            return $this->translator->trans('service.edit.not_authorized');
        }
        if (in_array($absence['status'], ['storniert', 'abgelehnt'], true)) {
            return $this->translator->trans('service.edit.terminal_status');
        }
        return null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   error?: string,
     *   start?: \DateTimeImmutable,
     *   end?: \DateTimeImmutable,
     *   halbtag_start?: string,
     *   halbtag_ende?: string,
     *   tage_gezaehlt?: float,
     *   notiz?: ?string,
     *   ooo_internal?: ?string,
     *   ooo_external?: ?string,
     * }
     */
    private function parseAndValidate(array $input): array
    {
        $startStr = trim((string) ($input['startdatum'] ?? ''));
        $endStr = trim((string) ($input['enddatum'] ?? ''));
        $halbtagStart = (string) ($input['halbtag_start'] ?? 'ganztag');
        $halbtagEnde = (string) ($input['halbtag_ende'] ?? 'ganztag');
        $notiz = trim((string) ($input['notiz'] ?? ''));

        if ($startStr === '' || $endStr === '') {
            return ['error' => $this->translator->trans('service.edit.dates_required')];
        }
        try {
            $start = new \DateTimeImmutable($startStr);
            $end = new \DateTimeImmutable($endStr);
        } catch (\Exception) {
            return ['error' => $this->translator->trans('flash.common.invalid_date')];
        }
        if ($end < $start) {
            return ['error' => $this->translator->trans('flash.common.end_before_start')];
        }
        if (!in_array($halbtagStart, ['ganztag', 'nachmittag'], true)) {
            $halbtagStart = 'ganztag';
        }
        if (!in_array($halbtagEnde, ['ganztag', 'vormittag'], true)) {
            $halbtagEnde = 'ganztag';
        }
        $tage = $this->werktage->compute($start, $end, $halbtagStart, $halbtagEnde);
        if ($tage <= 0) {
            return ['error' => $this->translator->trans('flash.common.no_workdays')];
        }

        // Google: ein einziges ooo_text-Field, in beide Spalten gespiegelt.
        // Microsoft: getrennte ooo_internal + ooo_external.
        $unifiedOoo = trim((string) ($input['ooo_text'] ?? ''));
        if ($unifiedOoo !== '') {
            $oooInternal = $unifiedOoo;
            $oooExternal = $unifiedOoo;
        } else {
            $oooInternal = trim((string) ($input['ooo_internal'] ?? ''));
            $oooExternal = trim((string) ($input['ooo_external'] ?? ''));
        }

        return [
            'start' => $start,
            'end' => $end,
            'halbtag_start' => $halbtagStart,
            'halbtag_ende' => $halbtagEnde,
            'tage_gezaehlt' => $tage,
            'notiz' => $notiz !== '' ? $notiz : null,
            'ooo_internal' => $oooInternal !== '' ? $oooInternal : null,
            'ooo_external' => $oooExternal !== '' ? $oooExternal : null,
        ];
    }

    /**
     * Aenderung eines Resturlaub- oder Kalender-relevanten Felds?
     * @param array<string, mixed> $absence
     * @param array<string, mixed> $parsed
     */
    private function hasCriticalChange(array $absence, array $parsed): bool
    {
        return $absence['startdatum'] !== $parsed['start']->format('Y-m-d')
            || $absence['enddatum'] !== $parsed['end']->format('Y-m-d')
            || $absence['halbtag_start'] !== $parsed['halbtag_start']
            || $absence['halbtag_ende'] !== $parsed['halbtag_ende']
            || (float) $absence['tage_gezaehlt'] !== (float) $parsed['tage_gezaehlt'];
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private function buildUpdateFields(array $parsed): array
    {
        return [
            'startdatum' => $parsed['start']->format('Y-m-d'),
            'enddatum' => $parsed['end']->format('Y-m-d'),
            'halbtag_start' => $parsed['halbtag_start'],
            'halbtag_ende' => $parsed['halbtag_ende'],
            'tage_gezaehlt' => $parsed['tage_gezaehlt'],
            'notiz' => $parsed['notiz'],
            'ooo_internal' => $parsed['ooo_internal'],
            'ooo_external' => $parsed['ooo_external'],
        ];
    }

    /**
     * @param array<string, mixed> $absence
     * @param array<string, mixed> $parsed
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function buildAuditDiff(array $absence, array $parsed): array
    {
        $diff = [];
        $mapping = [
            'startdatum' => $parsed['start']->format('Y-m-d'),
            'enddatum' => $parsed['end']->format('Y-m-d'),
            'halbtag_start' => $parsed['halbtag_start'],
            'halbtag_ende' => $parsed['halbtag_ende'],
            'tage_gezaehlt' => $parsed['tage_gezaehlt'],
            'notiz' => $parsed['notiz'],
            'ooo_internal' => $parsed['ooo_internal'],
            'ooo_external' => $parsed['ooo_external'],
        ];
        foreach ($mapping as $key => $newVal) {
            $oldVal = $absence[$key] ?? null;
            // Tage werden als DECIMAL aus DB als string zurueckgegeben — float-Vergleich.
            if ($key === 'tage_gezaehlt') {
                if ((float) $oldVal !== (float) $newVal) {
                    $diff[$key] = ['old' => (float) $oldVal, 'new' => (float) $newVal];
                }
            } elseif ((string) $oldVal !== (string) ($newVal ?? '')) {
                $diff[$key] = ['old' => $oldVal, 'new' => $newVal];
            }
        }
        return $diff;
    }

    /**
     * Owner editiert eigenen Urlaubsantrag.
     * @param array<string, mixed> $absence
     * @param array<string, mixed> $applicant
     * @param array<string, mixed> $parsed
     * @return array{ok: bool, error?: string, success?: string}
     */
    private function applyOwnerUrlaubEdit(
        array $absence,
        array $applicant,
        array $parsed,
        float $diffTage,
        bool $criticalChange,
    ): array {
        $absenceId = (int) $absence['id'];
        $status = (string) $absence['status'];

        // Resturlaub-Check fuer Erweiterung: nur sinnvoll wenn Status `aktiv` und Erhoehung.
        // Bei `beantragt` ist noch nichts abgebucht — Check erfolgt beim Approve.
        if ($status === 'aktiv' && $diffTage > 0 && $criticalChange) {
            $verfuegbar = (float) $applicant['resturlaub_aktuell'] + (float) $applicant['resturlaub_vorjahr'];
            if ($diffTage > $verfuegbar) {
                return ['ok' => false, 'error' => $this->translator->trans(
                    'service.edit.insufficient_resturlaub',
                    [
                        '%tage%' => number_format($diffTage, 1, ',', '.'),
                        '%verfuegbar%' => number_format($verfuegbar, 1, ',', '.'),
                    ]
                )];
            }
        }

        $updates = $this->buildUpdateFields($parsed);

        $reapprovalTriggered = $criticalChange;
        $statusReverted = false;

        $dbal = $this->db->dbal();
        $dbal->beginTransaction();
        try {
            if ($status === 'aktiv' && $reapprovalTriggered) {
                // Status zurueck auf `beantragt`, alte Tage refunden.
                // Kalender + OOO bleiben unveraendert (Design-Entscheidung).
                $this->resturlaub->refundToAktuell(
                    (int) $applicant['id'],
                    (float) $absence['tage_gezaehlt']
                );
                $updates['status'] = 'beantragt';
                $statusReverted = true;
            }

            $this->absences->update($absenceId, $updates);
            $this->audit->log(
                (int) $applicant['id'],
                'absence.edited',
                'absence',
                $absenceId,
                array_merge(
                    $this->buildAuditDiff($absence, $parsed),
                    ['status_reverted' => $statusReverted],
                ),
            );
            $dbal->commit();
        } catch (\Throwable $e) {
            $dbal->rollBack();
            throw $e;
        }

        // OOO refresh wenn nicht-kritische Aenderung an OOO-Text bei aktiv laufendem Urlaub.
        if (!$reapprovalTriggered) {
            $this->refreshOoo($absence, $parsed, $applicant);
        }

        // Re-Approval-Mail (auch bei Status `beantragt`, wenn kritische Aenderung —
        // Genehmiger:in soll den alten Magic-Link nicht mehr nutzen).
        if ($reapprovalTriggered && $absence['genehmiger_id'] !== null) {
            try {
                $this->tokens->invalidateAllForAbsence($absenceId);
                $this->approval->requestApproval($absenceId);
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'AbsenceEditService: requestApproval nach Edit fehlgeschlagen (absence %d): %s',
                    $absenceId,
                    $e->getMessage(),
                ));
            }
        }

        $msg = $statusReverted
            ? $this->translator->trans('service.edit.urlaub.updated_reapproval')
            : $this->translator->trans('flash.antrag.updated');
        return ['ok' => true, 'success' => $msg];
    }

    /**
     * Direct-Edit-Pfad ohne Re-Approval. Trigger:
     *  - HR editiert fremden Antrag (HR autorisiert sich selbst).
     *  - Owner editiert HR-erfassten Antrag ohne Genehmiger:in (kein Re-Approval moeglich).
     *
     * Resturlaub-Diff wird direkt angewendet, Kalender + OOO refreshed,
     * Info-Mail an Genehmiger:in (sofern vorhanden und != Editor).
     *
     * @param array<string, mixed> $absence
     * @param array<string, mixed> $applicant
     * @param array<string, mixed> $editor
     * @param array<string, mixed> $parsed
     * @return array{ok: bool, error?: string, success?: string}
     */
    private function applyDirectUrlaubEdit(
        array $absence,
        array $applicant,
        array $editor,
        array $parsed,
        float $diffTage,
        bool $criticalChange,
    ): array {
        $absenceId = (int) $absence['id'];
        $status = (string) $absence['status'];

        // Resturlaub-Check bei Erhoehung im aktiv-Status (Tage schon abgebucht — Diff fehlt).
        if ($status === 'aktiv' && $diffTage > 0) {
            $verfuegbar = (float) $applicant['resturlaub_aktuell'] + (float) $applicant['resturlaub_vorjahr'];
            if ($diffTage > $verfuegbar) {
                return ['ok' => false, 'error' => $this->translator->trans(
                    'service.edit.insufficient_resturlaub_for',
                    [
                        '%name%' => $applicant['display_name'],
                        '%tage%' => number_format($diffTage, 1, ',', '.'),
                        '%verfuegbar%' => number_format($verfuegbar, 1, ',', '.'),
                    ]
                )];
            }
        }

        $updates = $this->buildUpdateFields($parsed);

        $dbal = $this->db->dbal();
        $dbal->beginTransaction();
        try {
            if ($status === 'aktiv' && abs($diffTage) > 0.001) {
                if ($diffTage > 0) {
                    // Mehr Tage: vorjahr-first abbuchen (wie deductFromUser, aber nur die Differenz).
                    // Wir laden den aktuellen User-State und buchen den diff ab.
                    $freshApplicant = $this->users->findById((int) $applicant['id']);
                    if ($freshApplicant !== null) {
                        $this->resturlaub->deductFromUser($freshApplicant, $diffTage);
                    }
                } else {
                    // Weniger Tage: refund (positiver Wert) auf aktuell.
                    $this->resturlaub->refundToAktuell((int) $applicant['id'], abs($diffTage));
                }
            }
            $this->absences->update($absenceId, $updates);
            $this->audit->log(
                (int) $editor['id'],
                'absence.edited',
                'absence',
                $absenceId,
                array_merge(
                    $this->buildAuditDiff($absence, $parsed),
                    [
                        'direct_edit' => true,
                        'fuer_user_id' => (int) $applicant['id'],
                        'editor_is_hr' => (bool) $editor['ist_hr'],
                    ],
                ),
            );
            $dbal->commit();
        } catch (\Throwable $e) {
            $dbal->rollBack();
            throw $e;
        }

        // Kalender refresh (nur bei kritischer Aenderung und nur wenn Event existiert).
        if ($criticalChange) {
            $this->refreshCalendar($absenceId, $absence, $parsed, $applicant, 'urlaub');
        }
        $this->refreshOoo($absence, $parsed, $applicant);

        // Info-Mail an Genehmiger:in (wenn vorhanden und != HR-Editor).
        $this->notifyGenehmigerAfterHrEdit($absence, $applicant, $editor, $parsed);

        return ['ok' => true, 'success' => $this->translator->trans(
            'service.edit.urlaub.updated_for',
            ['%name%' => $applicant['display_name']]
        )];
    }

    /**
     * Kalender-Event neu anlegen (delete + create). Nur wenn alter Event existiert
     * (vor Approve gibt es noch keinen). Best-effort wie bei Storno.
     *
     * @param array<string, mixed> $absence
     * @param array<string, mixed> $parsed
     * @param array<string, mixed> $applicant
     */
    private function refreshCalendar(
        int $absenceId,
        array $absence,
        array $parsed,
        array $applicant,
        string $art,
    ): void {
        $oldEventId = !empty($absence['kalender_event_id']) ? (string) $absence['kalender_event_id'] : null;
        if ($oldEventId === null) {
            return;
        }
        try {
            $this->calendar->deleteEvent($oldEventId);
        } catch (\Throwable $e) {
            error_log(sprintf(
                'AbsenceEditService: alter Kalender-Event %s konnte nicht geloescht werden (absence %d): %s',
                $oldEventId,
                $absenceId,
                $e->getMessage(),
            ));
        }

        $subject = $art === 'urlaub'
            ? sprintf('[URLAUB] %s', $applicant['display_name'])
            : sprintf('Abwesend – %s', $applicant['display_name']);
        $eventStart = $parsed['start'];
        $eventEnd = $parsed['end']->modify('+1 day');

        try {
            $newEventId = $this->calendar->createEvent($subject, $eventStart, $eventEnd, true);
            $this->absences->update($absenceId, ['kalender_event_id' => $newEventId]);
        } catch (\Throwable $e) {
            error_log(sprintf(
                'AbsenceEditService: neuer Kalender-Event konnte nicht angelegt werden (absence %d): %s',
                $absenceId,
                $e->getMessage(),
            ));
            // event_id auf null setzen, sonst zeigt DB auf den geloeschten Event.
            $this->absences->update($absenceId, ['kalender_event_id' => null]);
        }
    }

    /**
     * OOO neu setzen, wenn die Abwesenheit aktuell laeuft (today zwischen alt-start
     * und alt-end ODER neu-start und neu-end) UND OOO-Text gesetzt ist.
     *
     * Zukuenftige Urlaube: Cron `ooo-sync.php` greift am Starttag auf die DB-Werte
     * zu — kein direkter Aufruf noetig.
     *
     * @param array<string, mixed> $absence
     * @param array<string, mixed> $parsed
     * @param array<string, mixed> $applicant
     */
    private function refreshOoo(array $absence, array $parsed, array $applicant): void
    {
        $today = new \DateTimeImmutable('today');
        $oldStart = new \DateTimeImmutable((string) $absence['startdatum']);
        $oldEnd = new \DateTimeImmutable((string) $absence['enddatum']);
        $newStart = $parsed['start'];
        $newEnd = $parsed['end'];

        $oldActive = $today >= $oldStart && $today <= $oldEnd;
        $newActive = $today >= $newStart && $today <= $newEnd;
        if (!$oldActive && !$newActive) {
            return;
        }

        $internal = $parsed['ooo_internal'];
        $external = $parsed['ooo_external'];

        // Wenn beide OOO-Felder leer sind: aktuell laufender OOO sollte deaktiviert
        // werden, sofern wir ihn gesetzt haben.
        if (($internal === null || $internal === '') && ($external === null || $external === '')) {
            try {
                $this->ooo->clearAutoReplyIfOurs((string) $applicant['email']);
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'AbsenceEditService: clearAutoReplyIfOurs fuer %s fehlgeschlagen: %s',
                    $applicant['email'],
                    $e->getMessage(),
                ));
            }
            return;
        }

        $finalInt = $internal !== null && $internal !== '' ? $internal : $external;
        $finalExt = $external !== null && $external !== '' ? $external : $internal;
        $renderOoo = static fn (string $plain): string =>
            '<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>';

        try {
            $this->ooo->setAutoReply(
                (string) $applicant['email'],
                $newStart,
                $newEnd,
                $renderOoo((string) $finalInt),
                $renderOoo((string) $finalExt),
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                'AbsenceEditService: setAutoReply fuer %s fehlgeschlagen: %s',
                $applicant['email'],
                $e->getMessage(),
            ));
        }
    }

    /**
     * @param array<string, mixed> $absence
     * @param array<string, mixed> $applicant
     * @param array<string, mixed> $editor
     * @param array<string, mixed> $parsed
     */
    private function notifyGenehmigerAfterHrEdit(
        array $absence,
        array $applicant,
        array $editor,
        array $parsed,
    ): void {
        if ($absence['genehmiger_id'] === null) {
            return;
        }
        $genehmigerId = (int) $absence['genehmiger_id'];
        if ($genehmigerId === (int) $editor['id']) {
            return; // HR-Editor ist selbst Genehmiger:in, kein Self-Notify.
        }
        $genehmiger = $this->users->findById($genehmigerId);
        if ($genehmiger === null) {
            return;
        }
        try {
            $this->mail->send(
                (string) $genehmiger['email'],
                sprintf('Aenderung am Urlaubsantrag von %s', $applicant['display_name']),
                'mails/antrag-edited-genehmiger.twig',
                [
                    'genehmiger' => $genehmiger,
                    'applicant' => $applicant,
                    'editor' => $editor,
                    'absence_old' => $absence,
                    'absence_new' => [
                        'startdatum' => $parsed['start']->format('Y-m-d'),
                        'enddatum' => $parsed['end']->format('Y-m-d'),
                        'tage_gezaehlt' => $parsed['tage_gezaehlt'],
                        'notiz' => $parsed['notiz'],
                    ],
                ],
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                'AbsenceEditService: Info-Mail an Genehmiger:in %s fehlgeschlagen: %s',
                $genehmiger['email'],
                $e->getMessage(),
            ));
        }
    }

    /**
     * @param array<string, mixed> $applicant
     * @param array<string, mixed> $editor
     * @param array<string, mixed> $absence
     * @param array<string, mixed> $parsed
     */
    private function notifyKrankEditToHr(
        array $applicant,
        array $editor,
        array $absence,
        array $parsed,
    ): void {
        $hrEmail = (string) $this->config->get('HR_NOTIFICATION_EMAIL');
        if ($hrEmail === '') {
            return;
        }
        try {
            $this->mail->send(
                $hrEmail,
                sprintf(
                    'Krankmeldung aktualisiert: %s (%s–%s)',
                    $applicant['display_name'],
                    $this->dates->monthDay($parsed['start']),
                    $this->dates->short($parsed['end']),
                ),
                'mails/krank-edited-hr.twig',
                [
                    'applicant' => $applicant,
                    'editor' => $editor,
                    'absence_old' => $absence,
                    'absence_new' => [
                        'startdatum' => $parsed['start']->format('Y-m-d'),
                        'enddatum' => $parsed['end']->format('Y-m-d'),
                        'tage_gezaehlt' => $parsed['tage_gezaehlt'],
                        'notiz' => $parsed['notiz'],
                    ],
                ],
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                'AbsenceEditService: Krank-Edit-Mail an HR fehlgeschlagen: %s',
                $e->getMessage(),
            ));
        }
    }
}
