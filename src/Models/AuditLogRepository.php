<?php declare(strict_types=1);

namespace App\Models;

use App\Database\Connection;
use Doctrine\DBAL\Connection as DbalConnection;

final class AuditLogRepository
{
    private DbalConnection $db;

    public function __construct(Connection $conn)
    {
        $this->db = $conn->dbal();
    }

    /** @param array<string, mixed> $payload */
    public function log(?int $userId, string $action, string $entityType, int $entityId, array $payload = []): void
    {
        $this->db->insert('audit_log', [
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload !== [] ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /**
     * @param array{
     *     action?: ?string,
     *     user_id?: ?int,
     *     entity_type?: ?string,
     *     from?: ?string,
     *     to?: ?string,
     * } $filters
     * @return array<int, array<string, mixed>>
     */
    public function listWithFilters(array $filters = [], int $limit = 200): array
    {
        $sql = 'SELECT a.id, a.user_id, a.action, a.entity_type, a.entity_id, a.payload, a.created_at,
                       u.display_name AS actor_display_name
                FROM audit_log a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE 1=1';
        $params = [];

        if (!empty($filters['action'])) {
            $sql .= ' AND a.action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['user_id'])) {
            $sql .= ' AND a.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['entity_type'])) {
            $sql .= ' AND a.entity_type = ?';
            $params[] = $filters['entity_type'];
        }
        if (!empty($filters['from'])) {
            $sql .= ' AND a.created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND a.created_at <= ?';
            $params[] = $filters['to'] . ' 23:59:59';
        }

        $sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . (int) $limit;

        return $this->db->fetchAllAssociative($sql, $params);
    }

    /** @return array<int, string> Liste eindeutiger Actions in der DB (fuer Filter-Dropdown). */
    public function listDistinctActions(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT DISTINCT action FROM audit_log ORDER BY action');
        return array_map(static fn (array $r): string => (string) $r['action'], $rows);
    }
}
