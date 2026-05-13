# Data-Model

Schema-Übersicht über sechs Tabellen plus Migrations-History. Die DB ist
authoritative — Migration-Files zeigen die DDL-Struktur, aber nicht
den aktuellen DB-Stand (Manual-Edits oder über die App geänderte Werte).

## Tabellen-Übersicht

### `users`

Operative + Account-Daten pro Mitarbeiter:in.

| Spalte | Typ | Bedeutung |
|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT PK | |
| `entra_oid` | VARCHAR(64) NULLABLE UNIQUE | Microsoft-Object-ID aus dem ID-Token. NULL bei pre-created Users vor erstem SSO (siehe `hr-and-audit.md` § MA-Pre-Create). |
| `email` | VARCHAR(255) NOT NULL UNIQUE | M365-Login-Adresse. Case-insensitive verglichen beim SSO-Match. |
| `display_name` | VARCHAR(255) NOT NULL | Wird beim ersten und allen folgenden SSO-Logins mit dem MS-Wert überschrieben. |
| `job_title` | VARCHAR(100) NULLABLE | Frei wählbar, nur HR-pflegbar, im Team-Grid + Profil sichtbar. |
| `eintrittsdatum` | DATE NULLABLE | Für anteilige Berechnung des Jahresanspruchs + Onboarding-Reminder (Probezeit etc.). |
| `jahresanspruch` | TINYINT UNSIGNED NOT NULL DEFAULT 30 | Tage pro Jahr. |
| `resturlaub_aktuell` | DECIMAL(4,1) NOT NULL DEFAULT 0 | Verbleibender Urlaub im laufenden Jahr. |
| `resturlaub_vorjahr` | DECIMAL(4,1) NOT NULL DEFAULT 0 | Übertrag aus dem Vorjahr — verfällt 31.03. |
| `ist_aktiv` | TINYINT(1) NOT NULL DEFAULT 1 | Inaktive User können sich nicht mehr einloggen + erscheinen nicht in Listen. |
| `ist_genehmiger` | TINYINT(1) NOT NULL DEFAULT 0 | Darf für andere Anträge genehmigen + Sidebar-Link „Meine Genehmigungen" sichtbar. |
| `ist_hr` | TINYINT(1) NOT NULL DEFAULT 0 | Vollzugriff auf HR-Funktionen (Stammdaten, Audit-Log, etc.). |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | |

### `user_master_data`

Persönliche Daten, 1:1 zu `users` via PK = FK. Bewusst eigene Tabelle, damit
sensiblere Felder von operativen getrennt sind.

| Spalte | Typ | Bedeutung |
|---|---|---|
| `user_id` | INT UNSIGNED PK + FK ON DELETE CASCADE | |
| `geburtsdatum` | DATE NULLABLE | |
| `telefon` | VARCHAR(50) NULLABLE | |
| `strasse` | VARCHAR(255) NULLABLE | |
| `plz` | VARCHAR(10) NULLABLE | |
| `ort` | VARCHAR(255) NULLABLE | |
| `created_at`, `updated_at` | DATETIME | wie bei `users` |

Keine OOO-Default-Texte mehr — Migration 006 fügte sie ein, Migration 007
droppte sie wieder (Entscheidung: OOO-Texte werden pro Antrag im Antrags-Form
gesetzt, keine zentralen Defaults).

### `absences`

Anträge und Krankmeldungen. Bei jedem MA-Eintrag pro Person:

| Spalte | Typ | Bedeutung |
|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT PK | |
| `user_id` | INT UNSIGNED FK → users(id) | Antragsteller:in |
| `art` | ENUM('urlaub','krank') NOT NULL | |
| `startdatum`, `enddatum` | DATE NOT NULL | |
| `halbtag_start` | ENUM('ganztag','nachmittag') | bei `nachmittag`: erster Tag zählt 0.5 |
| `halbtag_ende` | ENUM('ganztag','vormittag') | bei `vormittag`: letzter Tag zählt 0.5 |
| `tage_gezaehlt` | DECIMAL(4,1) | Werktage zwischen Start/End, abzüglich Feiertage + Halbtag-Korrektur. Berechnet beim Submit, persistiert. |
| `status` | ENUM('beantragt','aktiv','abgelehnt','storniert') | siehe `absence-workflow.md` § Status-Modell |
| `genehmiger_id` | INT UNSIGNED FK → users(id) NULLABLE | NULL bei Krank (kein Approval) |
| `notiz` | TEXT NULLABLE | Nur für HR + Genehmiger:in sichtbar |
| `begruendung_ablehnung` | TEXT NULLABLE | Bei Reject gesetzt |
| `kalender_event_id` | VARCHAR(255) NULLABLE | Microsoft-Graph-Event-ID, NULL nach Storno |
| `ooo_internal`, `ooo_external` | TEXT NULLABLE | Pro Antrag gesetzt. NULL = Fallback-Text (bei Urlaub) oder kein OOO (bei Krank) |
| `last_reminder_sent_at` | DATETIME NULLABLE | Cron-Tracking, verhindert Doppel-Reminder |
| `created_at`, `updated_at` | DATETIME | |

### `approval_tokens`

Magic-Link-Tokens für externe Approve/Reject aus Mail.

| Spalte | Typ | Bedeutung |
|---|---|---|
| `id` | PK | |
| `absence_id` | FK → absences(id) | |
| `token_hash` | VARCHAR(64) | SHA-256-Hash des plain Tokens. Plain wird nur in der Mail-URL gezeigt, nie in DB. |
| `action` | ENUM('approve','reject') | |
| `expires_at` | DATETIME | Token-TTL 7 Tage |
| `used_at` | DATETIME NULLABLE | Single-use; nach Approve/Reject gesetzt |
| `created_at` | DATETIME | |

Beim Approve oder Reject werden **alle** Tokens dieser Absence invalidiert
(`ApprovalTokenRepository::invalidateAllForAbsence`).

`cron/cleanup-tokens.php` räumt abgelaufene/used Tokens täglich auf.

### `audit_log`

Append-only-Log aller relevanten Schreib-Operationen. Schema in Migration 001.

| Spalte | Typ | Bedeutung |
|---|---|---|
| `id` | PK | |
| `user_id` | INT UNSIGNED NULLABLE FK → users(id) | Akteur. NULL bei Token-basierten Aktionen (kein Session-Context). |
| `action` | VARCHAR(64) NOT NULL | siehe `hr-and-audit.md` § Aktions-Vokabular |
| `entity_type` | ENUM('user','absence','system') | |
| `entity_id` | INT UNSIGNED | Bei `system.annual_rollover`: das Jahr (z.B. 2027). |
| `payload` | JSON NULLABLE | Struktur abhängig von Action |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | |

### `feiertage`

Feste Tabelle mit den Berliner Feiertagen. Seed in Migration 002. Wird von
`WerktageService::compute` für die Tage-Berechnung konsultiert. Keine Updates
zur Laufzeit; bei künftigen Jahren manuelle Erweiterung nötig oder Cron-Skript.

| Spalte | Typ | Bedeutung |
|---|---|---|
| `id` | PK | |
| `datum` | DATE NOT NULL UNIQUE | |
| `name` | VARCHAR(100) | Anzeige-Name, z.B. „Tag der Arbeit" |

## Migrations-History

Sequenziell durchnummeriert. Manuell eingespielt (Auto-Deploy-Workflow warnt nur,
führt nicht aus — siehe `docs/deployment.md`).

| # | Datei | Inhalt |
|---|---|---|
| 001 | `001_initial.sql` | Basis-Schema: 5 Tabellen (users, absences, approval_tokens, feiertage, audit_log). |
| 002 | `002_seed_feiertage_berlin.sql` | Feiertage-Seed-Daten. |
| 003 | `003_users_entra_oid_nullable.sql` | `users.entra_oid` → NULLABLE für HR-Pre-Create. |
| 004 | `004_absences_last_reminder_sent_at.sql` | `absences.last_reminder_sent_at` für Cron-Catch-up. |
| 005 | `005_user_master_data.sql` | Neue Tabelle `user_master_data` (1:1 zu users, CASCADE). |
| 006 | `006_ooo_texts.sql` | OOO-Default-Spalten in `user_master_data` + OOO-Pro-Antrag-Spalten in `absences`. |
| 007 | `007_drop_ooo_defaults.sql` | Rollback der Default-Spalten aus 006 (idempotent via `DROP COLUMN IF EXISTS`). |
| 008 | `008_users_job_title.sql` | `users.job_title` für Team-Übersicht + Profil-Anzeige. |

## Relationship-Diagramm

```
users  ─┬─ 1:1 ── user_master_data
        │
        ├─ 1:N ── absences (als user_id, Antragsteller)
        │         └── 1:N ── approval_tokens
        │
        ├─ 1:N ── absences (als genehmiger_id, optional)
        │
        └─ 1:N ── audit_log (als user_id, optional/nullable)

feiertage  ── kein FK, wird per Datum-Vergleich konsultiert
```

## Konventionen beim Schema

- **DB-Spalten Deutsch** (`startdatum`, `genehmiger_id`, `eintrittsdatum`). Code-Identifier Englisch (`startDate` wäre falsch, weil mit der Spalte direkt verglichen wird).
- **Bools als TINYINT(1)** (0/1) statt MySQL-`BOOLEAN` — gleicher Underlying-Typ, aber explizit für Klarheit.
- **Datums-Typen**: `DATE` für reine Daten (Antrags-Zeitraum), `DATETIME` für Audit + Tokens.
- **Geld/Tage als DECIMAL(4,1)** — Halbtages-Präzision ohne Float-Rundungsfehler.
- **Charset utf8mb4** durchgängig, Collation `utf8mb4_unicode_ci`.
- **InnoDB** wegen FK-Support.

## Tensions

- **`feiertage`-Tabelle ist nur Berlin** (Migration 002). Bei Erweiterung um MA in anderen Bundesländern (Bayern hat mehr Feiertage, Hamburg andere) wäre eine Bundesland-Spalte + Joins nötig. Für ein Berlin-Startup egal, bei Standort-Expansion zu fixen.
- **Migration-Files sind Source of Truth nur für DDL**, nicht für Daten. Es gibt keinen DB-Snapshot oder Seed-Dump für eine frische Production-Instanz — wir würden Production-Daten aus Backups recovern, nicht aus den Files.
- **Keine Migration-Tracking-Tabelle** (`schema_migrations` o.ä.). Welche Migrations wann eingespielt wurden, ist nirgends in der DB persistiert — Tracking läuft via Git-Commit-History plus Server-Memory-Disziplin. Bei Erweiterung um Migration-Framework (z.B. doctrine-migrations) wäre das ein Schritt.
- **`audit_log.payload` ist JSON ohne Schema-Versionierung** — siehe `hr-and-audit.md` § Tensions.
- **`absences.kalender_event_id` ist VARCHAR ohne Constraint** — kann theoretisch Inkonsistenzen mit dem echten Graph-Event-ID-Format haben. Bisher kein Issue.
- **Keine soft-deletes** für `users` (nur `ist_aktiv`-Flag). FK-Constraints zwischen `absences`/`audit_log`/`approval_tokens` und `users` machen echtes DELETE praktisch unmöglich — alle Referenzen müssten erst null'ed werden, was Audit-Trail zerstört.
