<?php declare(strict_types=1);

/**
 * Token-Cleanup — laeuft taeglich um 02:00.
 *
 * Loescht Approval-Tokens, deren Ablaufdatum mehr als 30 Tage zurueckliegt.
 * Tokens werden also 30 Tage ueber Ablauf hinaus aufbewahrt fuer Audit-Zwecke,
 * danach geloescht damit die Tabelle nicht endlos waechst.
 *
 * Cron-Eintrag (Hetzner KonsoleH):
 *   0 2 * * * php /pfad/zur/app/cron/cleanup-tokens.php
 */

require_once __DIR__ . '/bootstrap.php';

use App\Database\Connection;

$script = 'cleanup-tokens';
$container = $GLOBALS['cron_container'];
$db = $container->get(Connection::class)->dbal();

$rowsDeleted = (int) $db->executeStatement(
    'DELETE FROM approval_tokens WHERE expires_at < (NOW() - INTERVAL 30 DAY)'
);

cron_log($script, "Deleted $rowsDeleted expired approval tokens.");
