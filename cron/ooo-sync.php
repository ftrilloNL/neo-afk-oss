<?php declare(strict_types=1);

/**
 * Setzt Auto-OOO fuer Urlaubs-Antraege die heute starten.
 *
 * Microsoft Graph mailboxSettings.automaticRepliesSetting hat nur EINEN
 * Zeitraum-Slot pro Mailbox. Wenn wir den OOO bereits beim Approve setzen
 * wuerden (was die App vor diesem Cron tat), und der User mehrere zukuenftige
 * Urlaube approved bekommt, ueberschreibt der letzte alle vorherigen.
 *
 * Loesung: OOO wird beim Approve nur in der DB persistiert (ooo_internal,
 * ooo_external). Dieser Cron laeuft taeglich morgens und aktiviert OOO fuer
 * den Urlaub der heute beginnt. Microsoft schaltet via status='scheduled'
 * automatisch zum scheduledEnd ab.
 *
 * Krankmeldungen: bleiben sofort-aktiv via KrankController (kein Approve-Schritt,
 * kein Konflikt zwischen Future-Antraegen, deshalb hier nicht behandelt).
 *
 * Cron-Eintrag (Hetzner KonsoleH):
 *   0 6 * * * php /pfad/zur/app/cron/ooo-sync.php
 */

require_once __DIR__ . '/bootstrap.php';

use App\Models\AbsenceRepository;
use App\Services\GraphClient;

$script = 'ooo-sync';
$container = $GLOBALS['cron_container'];
$absences = $container->get(AbsenceRepository::class);
$graph = $container->get(GraphClient::class);

$today = (new DateTimeImmutable())->format('Y-m-d');
$candidates = $absences->listUrlaubeStartingOn($today);

if (count($candidates) === 0) {
    cron_log($script, "No vacations starting today ({$today}) need OOO sync.");
    exit(0);
}

$renderOoo = static fn (string $plain): string =>
    '<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>';

$set = 0;
foreach ($candidates as $a) {
    $email = (string) $a['user_email'];
    $start = new DateTimeImmutable((string) $a['startdatum']);
    $end = new DateTimeImmutable((string) $a['enddatum']);

    $fallback = sprintf(
        '<p>Ich bin vom %s bis %s außer Haus. Ihre Nachrichten werden in der Zwischenzeit nicht gelesen. Bei dringenden Anliegen wenden Sie sich bitte an meine Kolleg:innen.</p>',
        $start->format('d.m.Y'),
        $end->format('d.m.Y'),
    );

    $internal = !empty($a['ooo_internal']) ? $renderOoo((string) $a['ooo_internal']) : $fallback;
    $external = !empty($a['ooo_external']) ? $renderOoo((string) $a['ooo_external']) : $fallback;

    try {
        $graph->setAutoReply($email, $start, $end, $internal, $external);
        $set++;
    } catch (\Throwable $e) {
        cron_log($script, sprintf(
            'FAIL user=%s absence=%d: %s',
            $email,
            $a['id'],
            $e->getMessage(),
        ));
    }
}

cron_log($script, sprintf(
    'Set OOO for %d of %d urlaubs starting today (%s).',
    $set,
    count($candidates),
    $today,
));
