<?php declare(strict_types=1);

namespace App\Models;

use App\Database\Connection;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\ParameterType;

final class AbsenceRepository
{
    private DbalConnection $db;

    public function __construct(Connection $conn)
    {
        $this->db = $conn->dbal();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM absences WHERE id = ?', [$id]);
        return $row !== false ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, int $limit = 20): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM absences WHERE user_id = ? ORDER BY startdatum DESC LIMIT ' . (int) $limit,
            [$userId],
            [ParameterType::INTEGER]
        );
    }

    public function sumKrankTageForUserAndYear(int $userId, int $year): float
    {
        $val = $this->db->fetchOne(
            'SELECT COALESCE(SUM(tage_gezaehlt), 0)
             FROM absences
             WHERE user_id = ?
               AND art = "krank"
               AND status = "aktiv"
               AND YEAR(startdatum) = ?',
            [$userId, $year]
        );
        return (float) $val;
    }

    public function countPendingForGenehmiger(int $genehmigerId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM absences WHERE genehmiger_id = ? AND status = "beantragt"',
            [$genehmigerId]
        );
    }

    /**
     * @return array<int, array<string, mixed>> Pending-Antraege, die einen Reminder brauchen.
     *
     * Catch-up-faehig: ein verpasstes Cron-Run holt den Reminder beim naechsten Lauf nach.
     * Doppel-Reminder werden via last_reminder_sent_at-Tracking verhindert.
     *
     * Trigger-Bedingung:
     *   - Antrag aelter als $afterDays Tage
     *   - last_reminder_sent_at ist NULL ODER laenger als $repeatAfterDays her
     */
    public function listPendingNeedingReminder(int $afterDays = 2, int $repeatAfterDays = 5): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT a.*,
                    applicant.display_name AS user_display_name,
                    applicant.email AS user_email,
                    genehmiger.display_name AS genehmiger_display_name,
                    genehmiger.email AS genehmiger_email
             FROM absences a
             JOIN users applicant ON a.user_id = applicant.id
             LEFT JOIN users genehmiger ON a.genehmiger_id = genehmiger.id
             WHERE a.status = "beantragt"
               AND a.genehmiger_id IS NOT NULL
               AND a.created_at <= (NOW() - INTERVAL ? DAY)
               AND (a.last_reminder_sent_at IS NULL
                    OR a.last_reminder_sent_at <= (NOW() - INTERVAL ? DAY))',
            [$afterDays, $repeatAfterDays]
        );
    }

    public function markReminderSent(int $absenceId): void
    {
        $this->db->executeStatement(
            'UPDATE absences SET last_reminder_sent_at = NOW() WHERE id = ?',
            [$absenceId]
        );
    }

    /**
     * Aktive Urlaubs-Antraege die genau heute starten und einen OOO-Text haben.
     * Wird vom Cron ooo-sync.php aufgerufen — vermeidet OOO-Konflikte
     * zwischen mehreren zukuenftigen Antraegen (Microsoft hat nur ein OOO-Slot
     * pro Mailbox).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listUrlaubeStartingOn(string $date): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT a.*, u.email AS user_email, u.display_name AS user_display_name
             FROM absences a
             JOIN users u ON a.user_id = u.id
             WHERE a.art = "urlaub"
               AND a.status = "aktiv"
               AND a.startdatum = ?
               AND (a.ooo_internal IS NOT NULL OR a.ooo_external IS NOT NULL)',
            [$date]
        );
    }

    /**
     * @return array<int, array<string, mixed>> Pending Antraege wo der/die User Genehmiger:in ist.
     *   Zusaetzlich angereichert mit user_display_name + user_email aus JOIN.
     */
    public function listPendingForGenehmiger(int $genehmigerId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT a.*, u.display_name AS user_display_name, u.email AS user_email
             FROM absences a JOIN users u ON a.user_id = u.id
             WHERE a.genehmiger_id = ? AND a.status = "beantragt"
             ORDER BY a.created_at ASC',
            [$genehmigerId]
        );
    }

    /**
     * Liste aller Abwesenheiten (HR-View). Optional gefiltert.
     *
     * @param array{art?: ?string, status?: ?string, user_id?: ?int, from?: ?string, to?: ?string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function listAllWithFilters(array $filters = []): array
    {
        $sql = 'SELECT a.*,
                       u.display_name AS user_display_name,
                       u.email AS user_email,
                       g.display_name AS genehmiger_display_name
                FROM absences a
                JOIN users u ON a.user_id = u.id
                LEFT JOIN users g ON a.genehmiger_id = g.id
                WHERE 1=1';
        $params = [];

        if (!empty($filters['art'])) {
            $sql .= ' AND a.art = ?';
            $params[] = $filters['art'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND a.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['user_id'])) {
            $sql .= ' AND a.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['from'])) {
            $sql .= ' AND a.enddatum >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND a.startdatum <= ?';
            $params[] = $filters['to'];
        }

        $sql .= ' ORDER BY a.startdatum DESC, a.id DESC';

        return $this->db->fetchAllAssociative($sql, $params);
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        $this->db->insert('absences', $data);
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $changes */
    public function update(int $id, array $changes): void
    {
        $this->db->update('absences', $changes, ['id' => $id]);
    }
}
