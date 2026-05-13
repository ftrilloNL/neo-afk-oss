<?php declare(strict_types=1);

namespace App\Database;

use App\Config;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;

final class Connection
{
    private DbalConnection $conn;

    public function __construct(Config $config)
    {
        $this->conn = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $config->get('DB_HOST'),
            'port' => (int) $config->get('DB_PORT', '3306'),
            'dbname' => $config->get('DB_NAME'),
            'user' => $config->get('DB_USER'),
            'password' => $config->get('DB_PASS'),
            'charset' => 'utf8mb4',
        ]);
    }

    public function dbal(): DbalConnection
    {
        return $this->conn;
    }
}
