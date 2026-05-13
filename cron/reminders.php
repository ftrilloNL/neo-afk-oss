<?php declare(strict_types=1);

/**
 * Reminder-Mails an Genehmiger:innen — laeuft taeglich um 09:00.
 *
 * Triggert wenn:
 *   - Antrag status='beantragt' und aelter als REMINDER_AFTER_DAYS (default 2)
 *   - kein Reminder gesendet ODER letzter Reminder laenger her als
 *     REMINDER_REPEAT_AFTER_DAYS (default 5)
 *
 * Catch-up-faehig: wenn der Cron einen Tag nicht laeuft, holt der naechste Lauf
 * die verpassten Reminder nach. Doppel-Reminder werden via last_reminder_sent_at
 * verhindert.
 *
 * Cron-Eintrag (Hetzner KonsoleH):
 *   0 9 * * * php /pfad/zur/app/cron/reminders.php
 */

require_once __DIR__ . '/bootstrap.php';

use App\Config;
use App\Models\AbsenceRepository;
use App\Services\MailService;

$script = 'reminders';
$container = $GLOBALS['cron_container'];
$absences = $container->get(AbsenceRepository::class);
$mail = $container->get(MailService::class);
$config = $container->get(Config::class);

$afterDays = (int) ($_ENV['REMINDER_AFTER_DAYS'] ?? 2);
$repeatAfterDays = (int) ($_ENV['REMINDER_REPEAT_AFTER_DAYS'] ?? 5);

$candidates = $absences->listPendingNeedingReminder($afterDays, $repeatAfterDays);

if (count($candidates) === 0) {
    cron_log($script, sprintf(
        'No pending requests needing reminder (after_days=%d, repeat_after=%d).',
        $afterDays,
        $repeatAfterDays,
    ));
    exit(0);
}

$genehmigungenUrl = $config->appUrl() . '/genehmigungen';
$sent = 0;

foreach ($candidates as $a) {
    $createdAt = new DateTimeImmutable((string) $a['created_at']);
    $now = new DateTimeImmutable();
    $daysPending = (int) $createdAt->diff($now)->days;

    $genehmigerFirstName = explode(' ', (string) $a['genehmiger_display_name'])[0];

    try {
        $mail->send(
            (string) $a['genehmiger_email'],
            sprintf('Erinnerung: Urlaubsantrag von %s wartet auf Genehmigung', $a['user_display_name']),
            'mails/reminder.twig',
            [
                'absence' => $a,
                'applicant' => $a,
                'genehmiger_first_name' => $genehmigerFirstName,
                'days_pending' => $daysPending,
                'genehmigungen_url' => $genehmigungenUrl,
            ]
        );
        $absences->markReminderSent((int) $a['id']);
        $sent++;
    } catch (\Throwable $e) {
        cron_log($script, sprintf('FAILED for absence_id=%d: %s', $a['id'], $e->getMessage()));
    }
}

cron_log($script, sprintf('Sent %d reminder(s) of %d candidates.', $sent, count($candidates)));
