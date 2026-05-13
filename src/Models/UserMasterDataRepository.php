<?php declare(strict_types=1);

namespace App\Models;

use App\Database\Connection;
use Doctrine\DBAL\Connection as DbalConnection;

final class UserMasterDataRepository
{
    private DbalConnection $db;

    public function __construct(Connection $conn)
    {
        $this->db = $conn->dbal();
    }

    /** @return array<string, mixed>|null */
    public function findByUserId(int $userId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM user_master_data WHERE user_id = ?',
            [$userId]
        );
        return $row !== false ? $row : null;
    }

    /**
     * INSERT … ON DUPLICATE KEY UPDATE — funktioniert weil user_id PRIMARY KEY ist.
     * Felder die NULL bleiben sollen, einfach null uebergeben.
     *
     * @param array{
     *     geburtsdatum: ?string,
     *     telefon: ?string,
     *     strasse: ?string,
     *     plz: ?string,
     *     ort: ?string,
     * } $data
     */
    public function upsert(int $userId, array $data): void
    {
        $this->db->executeStatement(
            'INSERT INTO user_master_data
                (user_id, geburtsdatum, telefon, strasse, plz, ort)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                geburtsdatum = VALUES(geburtsdatum),
                telefon = VALUES(telefon),
                strasse = VALUES(strasse),
                plz = VALUES(plz),
                ort = VALUES(ort)',
            [
                $userId,
                $data['geburtsdatum'],
                $data['telefon'],
                $data['strasse'],
                $data['plz'],
                $data['ort'],
            ]
        );
    }

}
