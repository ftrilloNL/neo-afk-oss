<?php declare(strict_types=1);

/**
 * Vorjahres-Verfall — laeuft 1x jaehrlich am 1. April 02:00.
 *
 * Logik: setzt resturlaub_vorjahr := 0 fuer alle aktiven MAs.
 * Verfaellt also unbenutzte Urlaubstage aus dem Vorjahr.
 *
 * Idempotenz: prueft Audit-Log, ob fuer das aktuelle Jahr bereits eine
 * 'system.vorjahr_verfall'-Aktion vermerkt ist.
 *
 * Cron-Eintrag (Hetzner KonsoleH):
 *   0 2 1 4 * php /pfad/zur/app/cron/verfall.php
 */

require_once __DIR__ . '/bootstrap.php';

use App\Database\Connection;
use App\Models\AuditLogRepository;

$script = 'verfall';
$container = $GLOBALS['cron_container'];
$db = $container->get(Connection::class)->dbal();
$audit = $container->get(AuditLogRepository::class);

$year = (int) date('Y');

$already = (int) $db->fetchOne(
    'SELECT COUNT(*) FROM audit_log
     WHERE action = ?
       AND created_at >= ?
       AND created_at <  ?',
    ['system.vorjahr_verfall', "$year-01-01", ($year + 1) . '-01-01']
);
if ($already > 0) {
    cron_log($script, "Skip: verfall already performed in $year (audit_log row found).");
    exit(0);
}

$rowsWithVorjahr = $db->fetchAllAssociative(
    'SELECT id, display_name, resturlaub_vorjahr FROM users WHERE ist_aktiv = 1 AND resturlaub_vorjahr > 0'
);

$updated = 0;
$totalVerfallen = 0.0;
foreach ($rowsWithVorjahr as $user) {
    $userId = (int) $user['id'];
    $verfallen = (float) $user['resturlaub_vorjahr'];

    $db->update('users', ['resturlaub_vorjahr' => 0], ['id' => $userId]);

    $audit->log($userId, 'user.vorjahr_verfall', 'user', $userId, [
        'verfallen' => $verfallen,
    ]);

    $updated++;
    $totalVerfallen += $verfallen;
}

$audit->log(null, 'system.vorjahr_verfall', 'system', $year, [
    'users_affected' => $updated,
    'total_verfallen' => $totalVerfallen,
]);

cron_log(
    $script,
    sprintf('Verfall durchgefuehrt: %d Mitarbeiter:innen, %s Tage verfallen.', $updated, number_format($totalVerfallen, 1, ',', '.'))
);
