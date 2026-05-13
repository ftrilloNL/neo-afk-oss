<?php declare(strict_types=1);

/**
 * Shared Bootstrap fuer alle Cron-Skripte.
 *
 * Laedt Composer-Autoload + .env, baut den DI-Container, und stellt eine
 * `cron_log()`-Funktion bereit, mit der Skripte zentral in var/logs/cron.log
 * schreiben (zusaetzlich zu Stdout, das von Hetzner ggf. als Mail rausgeht).
 *
 * Usage in einem Cron-Skript:
 *
 *   require_once __DIR__ . '/bootstrap.php';
 *   $container = $GLOBALS['cron_container'];
 *   $users = $container->get(App\Models\UserRepository::class);
 *   ...
 *   cron_log('jahreswechsel', 'Rolled over 10 users');
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\App;
use Dotenv\Dotenv;

$rootPath = dirname(__DIR__);
Dotenv::createImmutable($rootPath)->safeLoad();

$GLOBALS['cron_root_path'] = $rootPath;
$GLOBALS['cron_container'] = App::buildContainer($rootPath);

/**
 * Schreibt eine Cron-Log-Zeile nach var/logs/cron.log + echoed auf Stdout.
 */
function cron_log(string $scriptName, string $message): void
{
    $line = sprintf('[%s] [%s] %s', date('c'), $scriptName, $message);

    $dir = $GLOBALS['cron_root_path'] . '/var/logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($dir . '/cron.log', $line . "\n", FILE_APPEND);

    echo $line . PHP_EOL;
}
