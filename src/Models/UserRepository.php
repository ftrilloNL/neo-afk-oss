<?php declare(strict_types=1);

namespace App\Models;

use App\Database\Connection;
use Doctrine\DBAL\Connection as DbalConnection;

final class UserRepository
{
    private DbalConnection $db;

    public function __construct(Connection $conn)
    {
        $this->db = $conn->dbal();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM users WHERE id = ?', [$id]);
        if ($row === false) {
            return null;
        }
        // Computed field — wird vom Layout-Template fuer Avatar-vs-Initials genutzt.
        $avatarPath = dirname(__DIR__, 2) . '/var/avatars/' . $row['id'] . '.jpg';
        $row['has_avatar'] = is_file($avatarPath) && filesize($avatarPath) > 0;
        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function listGenehmiger(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT id, display_name, email FROM users WHERE ist_aktiv = 1 AND ist_genehmiger = 1 ORDER BY display_name'
        );
    }

    /** @return array<int, array<string, mixed>> Alle aktiven User (fuer HR-Filter) */
    public function listAllActive(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT id, display_name, email FROM users WHERE ist_aktiv = 1 ORDER BY display_name'
        );
    }

    /**
     * Team-Uebersicht fuer /team: aktive User plus Telefon aus master_data.
     * Inaktive bleiben weg.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTeam(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.id, u.display_name, u.job_title, u.email, u.ist_hr, u.ist_genehmiger,
                    m.telefon
             FROM users u
             LEFT JOIN user_master_data m ON m.user_id = u.id
             WHERE u.ist_aktiv = 1
             ORDER BY u.display_name'
        );

        $avatarsDir = dirname(__DIR__, 2) . '/var/avatars';
        foreach ($rows as &$row) {
            $avatarPath = $avatarsDir . '/' . $row['id'] . '.jpg';
            $row['has_avatar'] = is_file($avatarPath) && filesize($avatarPath) > 0;
        }
        return $rows;
    }

    /** @return array<int, array<string, mixed>> Alle User inkl. inaktive — fuer HR-Stammdaten */
    public function listAll(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM users ORDER BY ist_aktiv DESC, display_name'
        );
    }

    /**
     * Update der HR-pflegbaren Stammdaten. Felder hardcoded — keine generic
     * Pass-through, damit kein Caller versehentlich external_oid oder email
     * ueberschreibt.
     *
     * @param array{
     *     job_title?: ?string,
     *     eintrittsdatum?: ?string,
     *     jahresanspruch: int,
     *     resturlaub_aktuell: float,
     *     resturlaub_vorjahr: float,
     *     ist_aktiv: bool,
     *     ist_genehmiger: bool,
     *     ist_hr: bool,
     * } $data
     */
    public function updateStammdaten(int $userId, array $data): void
    {
        $this->db->update('users', [
            'job_title' => $data['job_title'] ?? null,
            'eintrittsdatum' => $data['eintrittsdatum'] ?? null,
            'jahresanspruch' => $data['jahresanspruch'],
            'resturlaub_aktuell' => $data['resturlaub_aktuell'],
            'resturlaub_vorjahr' => $data['resturlaub_vorjahr'],
            'ist_aktiv' => $data['ist_aktiv'] ? 1 : 0,
            'ist_genehmiger' => $data['ist_genehmiger'] ? 1 : 0,
            'ist_hr' => $data['ist_hr'] ? 1 : 0,
        ], ['id' => $userId]);
    }

    /** @return string[] */
    public function listHrEmails(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT email FROM users WHERE ist_hr = 1 AND ist_aktiv = 1');
        return array_map(static fn (array $r): string => (string) $r['email'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM users WHERE LOWER(email) = LOWER(?)',
            [$email]
        );
        return $row !== false ? $row : null;
    }

    /**
     * Findet User mit aehnlicher E-Mail — gleicher Local-Part oder gleicher
     * Domain mit aehnlichem Local-Part (z.B. flavio@ vs flavio.trillo@).
     * Wird beim HR-Pre-Create genutzt, um versehentliche Doppel-Anlagen
     * zu vermeiden.
     *
     * Vergleich: exact match auf Local-Part nach Punkte-Entfernung
     * (M365-Konvention: flavio@ und flavio.trillo@ haben unterschiedliche
     * Local-Parts, aber wenn jemand "flavio" eingibt und "flavio.trillo"
     * existiert, ist's wahrscheinlich derselbe Mensch).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findSimilarByEmail(string $email): array
    {
        $email = strtolower(trim($email));
        if (!str_contains($email, '@')) {
            return [];
        }
        [$localPart, $domain] = explode('@', $email, 2);
        // Wir suchen in derselben Domain alle deren Local-Part den eingegebenen
        // entweder enthaelt oder vom eingegebenen enthalten wird (substring,
        // beide Richtungen). Plus exact match wird separat schon abgefangen.
        return $this->db->fetchAllAssociative(
            'SELECT * FROM users
             WHERE LOWER(email) <> LOWER(?)
               AND LOWER(email) LIKE ?
               AND (
                    SUBSTRING_INDEX(LOWER(email), "@", 1) LIKE ?
                 OR ? LIKE CONCAT(SUBSTRING_INDEX(LOWER(email), "@", 1), "%")
                 OR SUBSTRING_INDEX(LOWER(email), "@", 1) LIKE CONCAT(?, "%")
               )',
            [
                $email,
                '%@' . $domain,
                '%' . $localPart . '%',
                $localPart,
                $localPart,
            ]
        );
    }

    /**
     * Vorab-Anlage eines Mitarbeiters durch HR. external_oid + external_provider
     * bleiben NULL bis zum ersten SSO-Login — siehe AuthController::upsertUser
     * (Pre-Created-Branch).
     *
     * @param array{
     *     display_name: string,
     *     email: string,
     *     job_title: ?string,
     *     eintrittsdatum: ?string,
     *     jahresanspruch: int,
     *     resturlaub_aktuell: float,
     *     resturlaub_vorjahr: float,
     *     ist_aktiv: bool,
     *     ist_genehmiger: bool,
     *     ist_hr: bool,
     * } $data
     */
    public function createPreUser(array $data): int
    {
        $this->db->insert('users', [
            'external_oid' => null,
            'external_provider' => null,
            'email' => $data['email'],
            'display_name' => $data['display_name'],
            'job_title' => $data['job_title'] ?? null,
            'eintrittsdatum' => $data['eintrittsdatum'],
            'jahresanspruch' => $data['jahresanspruch'],
            'resturlaub_aktuell' => $data['resturlaub_aktuell'],
            'resturlaub_vorjahr' => $data['resturlaub_vorjahr'],
            'ist_aktiv' => $data['ist_aktiv'] ? 1 : 0,
            'ist_genehmiger' => $data['ist_genehmiger'] ? 1 : 0,
            'ist_hr' => $data['ist_hr'] ? 1 : 0,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function applyResturlaubChange(int $userId, float $vorjahrDelta, float $aktuellDelta): void
    {
        $this->db->executeStatement(
            'UPDATE users SET resturlaub_vorjahr = resturlaub_vorjahr + ?, resturlaub_aktuell = resturlaub_aktuell + ? WHERE id = ?',
            [$vorjahrDelta, $aktuellDelta, $userId]
        );
    }
}
