<?php declare(strict_types=1);

namespace App\Models;

use App\Database\Connection;
use Doctrine\DBAL\Connection as DbalConnection;

final class FeiertagRepository
{
    private DbalConnection $db;

    public function __construct(Connection $conn)
    {
        $this->db = $conn->dbal();
    }

    /**
     * @return string[] Liste der Feiertags-Daten im Format yyyy-MM-dd
     */
    public function listDates(int $startYear, int $endYear, string $bundesland = 'BE'): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT DATE_FORMAT(datum, "%Y-%m-%d") AS d FROM feiertage WHERE bundesland = ? AND YEAR(datum) BETWEEN ? AND ? ORDER BY datum',
            [$bundesland, $startYear, $endYear]
        );
        return array_map(static fn (array $r): string => (string) $r['d'], $rows);
    }
}
