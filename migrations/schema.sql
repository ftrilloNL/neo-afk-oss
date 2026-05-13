-- Konsolidiertes Schema fuer Neuinstallationen.
--
-- Aequivalent zu sequenzieller Anwendung von migrations/001 bis 008,
-- aber ohne die historischen Zwischenschritte (z.B. Migration 006/007
-- haben sich gegenseitig aufgehoben). End-State zum Zeitpunkt 2026-05.
--
-- Run with:
--   mysql -h <host> -u <user> -p <db> < migrations/schema.sql
--
-- Danach Feiertage-Seed fuer das jeweilige Bundesland einspielen
-- (siehe migrations/seeds/).
--
-- Wenn du eine Bestandsinstallation hast und schrittweise upgraden willst,
-- nutze stattdessen migrations/001 bis 008 in der Reihenfolge.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Provider-agnostischer User-Identifier vom IdP. Bei Microsoft Entra ID = oid-Claim,
    -- bei Google Identity = sub-Claim. NULL erlaubt Pre-Create durch HR vor erstem
    -- SSO-Login. Beim ersten Login matched AuthController::upsertUser via Email
    -- und ergaenzt external_oid + external_provider.
    external_oid VARCHAR(64) NULL,
    external_provider ENUM('microsoft','google') NULL,
    email VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    -- Operativ + team-sichtbar (Anzeige auf /team).
    job_title VARCHAR(100) NULL,
    eintrittsdatum DATE NULL,
    jahresanspruch TINYINT UNSIGNED NOT NULL DEFAULT 30,
    resturlaub_aktuell DECIMAL(4,1) NOT NULL DEFAULT 0,
    resturlaub_vorjahr DECIMAL(4,1) NOT NULL DEFAULT 0,
    ist_aktiv TINYINT(1) NOT NULL DEFAULT 1,
    ist_genehmiger TINYINT(1) NOT NULL DEFAULT 0,
    ist_hr TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Composite unique: same external_oid darf bei zwei Providern auftauchen (rein
    -- defensiv — unwahrscheinlich, aber sauber modelliert).
    UNIQUE KEY uk_external_identity (external_provider, external_oid),
    UNIQUE KEY uk_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE absences (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    art ENUM('urlaub','krank') NOT NULL,
    startdatum DATE NOT NULL,
    enddatum DATE NOT NULL,
    halbtag_start ENUM('ganztag','nachmittag') NOT NULL DEFAULT 'ganztag',
    halbtag_ende ENUM('ganztag','vormittag') NOT NULL DEFAULT 'ganztag',
    tage_gezaehlt DECIMAL(4,1) NOT NULL,
    status ENUM('beantragt','aktiv','abgelehnt','storniert') NOT NULL DEFAULT 'beantragt',
    genehmiger_id INT UNSIGNED NULL,
    notiz TEXT NULL,
    kalender_event_id VARCHAR(255) NULL,
    -- Out-of-Office-Texte pro Antrag, vom User im Form gesetzt.
    -- Werden beim Approve bzw. via cron/ooo-sync.php als Auto-Reply gesetzt.
    ooo_internal TEXT NULL,
    ooo_external TEXT NULL,
    -- Catch-up-faehiges Reminder-Tracking: NULL = noch nicht erinnert.
    -- Cron updated nach erfolgreichem Mail-Send.
    last_reminder_sent_at DATETIME NULL,
    begruendung_ablehnung TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_start (user_id, startdatum),
    KEY idx_genehmiger_status (genehmiger_id, status),
    KEY idx_status (status),
    KEY idx_zeitraum (startdatum, enddatum),
    CONSTRAINT fk_absences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_absences_genehmiger FOREIGN KEY (genehmiger_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE approval_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    absence_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    action ENUM('approve','reject') NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_token_hash (token_hash),
    KEY idx_absence (absence_id),
    CONSTRAINT fk_tokens_absence FOREIGN KEY (absence_id) REFERENCES absences(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE feiertage (
    datum DATE NOT NULL,
    name VARCHAR(100) NOT NULL,
    -- ISO-3166-2:DE-Code (BE, BY, HH, ...). Wird gegen
    -- ORG_FEIERTAGE_BUNDESLAND aus der .env gematched.
    bundesland VARCHAR(10) NOT NULL DEFAULT 'BE',
    PRIMARY KEY (datum, bundesland)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_entity (entity_type, entity_id),
    KEY idx_user_time (user_id, created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1:1 zu users — bewusst eigene Tabelle damit sensible Daten (Adresse,
-- Geburtsdatum) klar von operativen Spalten getrennt sind. Sichtbarkeit
-- nur HR + User selbst.
CREATE TABLE user_master_data (
    user_id INT UNSIGNED NOT NULL,
    geburtsdatum DATE NULL,
    telefon VARCHAR(50) NULL,
    strasse VARCHAR(255) NULL,
    plz VARCHAR(10) NULL,
    ort VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_master_data_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
