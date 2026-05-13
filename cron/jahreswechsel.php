<?php declare(strict_types=1);

/**
 * Jahreswechsel-Job — laeuft 1x jaehrlich am 1. Januar 02:00.
 *
 * Logik pro aktiver MA:
 *   1. resturlaub_vorjahr := resturlaub_aktuell   (alte Reste werden zu Vorjahres-Resten)
 *   2. resturlaub_aktuell := jahresanspruch       (neues Jahres-Kontingent)
 *
 * Idempotenz: prueft Audit-Log, ob fuer das aktuelle Jahr bereits eine
 * 'system.annual_rollover'-Aktion vermerkt ist. Falls ja: skip.
 *
 * Cron-Eintrag (Hetzner KonsoleH):
 *   0 2 1 1 * php /pfad/zur/app/cron/jahreswechsel.php
 */

require_once __DIR__ . '/bootstrap.php';

use App\Database\Connection;
use App\Models\AuditLogRepository;
use App\Models\UserRepository;

$script = 'jahreswechsel';
$container = $GLOBALS['cron_container'];
$db = $container->get(Connection::class)->dbal();
$users = $container->get(UserRepository::class);
$audit = $container->get(AuditLogRepository::class);

$year = (int) date('Y');

// Idempotenz-Check
$already = (int) $db->fetchOne(
    'SELECT COUNT(*) FROM audit_log
     WHERE action = ?
       AND created_at >= ?
       AND created_at <  ?',
    ['system.annual_rollover', "$year-01-01", ($year + 1) . '-01-01']
);
if ($already > 0) {
    cron_log($script, "Skip: rollover already performed in $year (audit_log row found).");
    exit(0);
}

$activeUsers = $db->fetchAllAssociative(
    'SELECT id, display_name, jahresanspruch, resturlaub_aktuell, resturlaub_vorjahr FROM users WHERE ist_aktiv = 1'
);

$updated = 0;
foreach ($activeUsers as $user) {
    $userId = (int) $user['id'];
    $oldAktuell = (float) $user['resturlaub_aktuell'];
    $oldVorjahr = (float) $user['resturlaub_vorjahr'];
    $jahresanspruch = (float) $user['jahresanspruch'];

    $db->update('users', [
        'resturlaub_vorjahr' => $oldAktuell,
        'resturlaub_aktuell' => $jahresanspruch,
    ], ['id' => $userId]);

    $audit->log($userId, 'user.annual_rollover', 'user', $userId, [
        'old_aktuell' => $oldAktuell,
        'old_vorjahr' => $oldVorjahr,
        'new_aktuell' => $jahresanspruch,
        'new_vorjahr' => $oldAktuell,
    ]);

    $updated++;
}

$audit->log(null, 'system.annual_rollover', 'system', $year, ['users_updated' => $updated]);
cron_log($script, "Rolled over $updated active users for year $year.");
