<?php declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\App;

$app = App::create(dirname(__DIR__));
$app->run();
