<?php declare(strict_types=1);

namespace App\Models;

use App\Database\Connection;
use Doctrine\DBAL\Connection as DbalConnection;

final class ApprovalTokenRepository
{
    private DbalConnection $db;

    public function __construct(Connection $conn)
    {
        $this->db = $conn->dbal();
    }

    /** @return array<string, mixed>|null */
    public function findValidByHash(string $hash): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM approval_tokens WHERE token_hash = ? AND expires_at > NOW() AND used_at IS NULL',
            [$hash]
        );
        return $row !== false ? $row : null;
    }

    public function insert(int $absenceId, string $tokenHash, string $action, \DateTimeImmutable $expiresAt): int
    {
        $this->db->insert('approval_tokens', [
            'absence_id' => $absenceId,
            'token_hash' => $tokenHash,
            'action' => $action,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function markUsed(int $id): void
    {
        $this->db->update('approval_tokens', ['used_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    /**
     * Invalidiert alle noch offenen Tokens fuer eine bestimmte Absence —
     * z.B. wenn die Genehmigung In-App durchgefuehrt wird, sodass der
     * Mail-Magic-Link nicht mehr funktioniert.
     */
    public function invalidateAllForAbsence(int $absenceId): void
    {
        $this->db->executeStatement(
            'UPDATE approval_tokens SET used_at = NOW() WHERE absence_id = ? AND used_at IS NULL',
            [$absenceId]
        );
    }
}
