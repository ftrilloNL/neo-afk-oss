# Spezifikation — neo-afk

> **Hinweis:** Dieses Dokument ist der initiale Plan aus 2026-05 für die
> interne Instanz. Es enthält viele deployment-spezifische Werte (Domain,
> Mailboxen, Hetzner-Deploy-Details). Für aktuelle, deployment-neutrale Doku
> siehe [`docs/architecture/`](docs/architecture/). Diese Spec wird hier als
> historisches Artefakt erhalten — sinnvoll für Verständnis des „Warum" hinter
> einigen Designentscheidungen.

## 1. Domänen-Modell

Neu modelliert für relationale DB (initial portiert aus einem internen
Power-Platform-Vorgänger).

### 1.1 Tabelle `users`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | |
| `entra_oid` | VARCHAR(64) UNIQUE | OID aus Microsoft Entra ID (`oid`-Claim aus OAuth2-Token), eindeutiger Schlüssel zum M365-Account |
| `email` | VARCHAR(255) NOT NULL | Geschäftliche Mail-Adresse |
| `display_name` | VARCHAR(255) NOT NULL | Anzeigename aus Entra ID |
| `eintrittsdatum` | DATE | Für anteilige Berechnung |
| `jahresanspruch` | TINYINT UNSIGNED NOT NULL DEFAULT 30 | Volle Tage pro Jahr |
| `resturlaub_aktuell` | DECIMAL(4,1) NOT NULL DEFAULT 0 | |
| `resturlaub_vorjahr` | DECIMAL(4,1) NOT NULL DEFAULT 0 | Verfällt am 31.03. |
| `ist_aktiv` | TINYINT(1) NOT NULL DEFAULT 1 | |
| `ist_genehmiger` | TINYINT(1) NOT NULL DEFAULT 0 | Darf in Genehmiger-Dropdown auftauchen |
| `ist_hr` | TINYINT(1) NOT NULL DEFAULT 0 | Sieht HR-View, Krankheits-Detail |
| `created_at` | DATETIME NOT NULL | |
| `updated_at` | DATETIME NOT NULL | |

### 1.2 Tabelle `absences`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | |
| `user_id` | INT NOT NULL FK → users.id | Antragsteller:in |
| `art` | ENUM('urlaub','krank') NOT NULL | |
| `startdatum` | DATE NOT NULL | |
| `enddatum` | DATE NOT NULL | |
| `halbtag_start` | ENUM('ganztag','nachmittag') NOT NULL DEFAULT 'ganztag' | |
| `halbtag_ende` | ENUM('ganztag','vormittag') NOT NULL DEFAULT 'ganztag' | |
| `tage_gezaehlt` | DECIMAL(4,1) NOT NULL | Werktage Berlin minus Feiertage, ggf. ±0.5 |
| `status` | ENUM('beantragt','aktiv','abgelehnt','storniert') NOT NULL DEFAULT 'beantragt' | |
| `genehmiger_id` | INT NULL FK → users.id | Nur bei `art='urlaub'` |
| `notiz` | TEXT NULL | |
| `kalender_event_id` | VARCHAR(255) NULL | M365-Event-ID nach Anlage |
| `begruendung_ablehnung` | TEXT NULL | |
| `created_at` | DATETIME NOT NULL | |
| `updated_at` | DATETIME NOT NULL | |

Indizes: `(user_id, startdatum)`, `(genehmiger_id, status)`, `(status)`, `(startdatum, enddatum)`.

### 1.3 Tabelle `approval_tokens`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | |
| `absence_id` | INT NOT NULL FK → absences.id | |
| `token_hash` | CHAR(64) NOT NULL UNIQUE | SHA-256 des opaque random Tokens (nicht Plain-Token speichern) |
| `action` | ENUM('approve','reject') NOT NULL | |
| `expires_at` | DATETIME NOT NULL | Default: now+7 Tage |
| `used_at` | DATETIME NULL | Wenn != NULL: Token verbraucht, weitere Klicks → 410 Gone |
| `created_at` | DATETIME NOT NULL | |

**Token-Format:** opaque, kryptografisch zufaellig (`bin2hex(random_bytes(32))` = 64 hex chars). KEIN JWT — wir muessen ohnehin in der DB nachschlagen wegen `used_at`-Check, der self-validation-Vorteil von JWT entfaellt damit. Dafuer sparen wir uns die JWT-Lib-Dependency.

### 1.4 Tabelle `feiertage`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `datum` | DATE PK | |
| `name` | VARCHAR(100) NOT NULL | „Tag der Arbeit" |
| `bundesland` | VARCHAR(10) NOT NULL DEFAULT 'BE' | Vorbereitet für andere Bundesländer |

Initial gefüllt via Seed-File (`migrations/seeds/feiertage-BE.sql` für Berlin,
`feiertage-BY.sql` für Bayern, weitere nach gleichem Pattern).

### 1.5 Tabelle `audit_log`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | INT PK AUTO_INCREMENT | |
| `user_id` | INT NULL FK → users.id | Wer hat ausgelöst (NULL bei System-Cron) |
| `action` | VARCHAR(64) NOT NULL | „absence.created", „absence.approved", „resturlaub.changed" |
| `entity_type` | VARCHAR(64) NOT NULL | „absence", „user" |
| `entity_id` | INT NOT NULL | |
| `payload` | JSON NULL | Vorher/Nachher-Werte für Änderungen |
| `created_at` | DATETIME NOT NULL | |

### 1.6 Tabelle `sessions`

PHP-Session-Storage (alternativ Default-File-Session). Schema je nach Library. **Vorschlag:** `delight-im/php-auth`-Style oder Slim-Session-Middleware mit DB-Backend für Skalierbarkeit + Session-Invalidation.

## 2. Endpunkte

### 2.1 Auth

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/login` | Startet OAuth2-Flow → Redirect zu Entra ID |
| GET | `/auth/callback` | OAuth-Callback, tauscht Code gegen Token, legt Session an |
| POST | `/logout` | Session zerstören, Redirect zu `/` |

### 2.2 App (auth required)

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/` | Dashboard: eigener Resturlaub, eigene Anträge, Navigations-Buttons |
| GET | `/antrag/neu` | Form: Urlaubsantrag stellen |
| POST | `/antrag` | Urlaubsantrag submitten → `status=beantragt`, Token + Mail an Genehmiger |
| GET | `/krank/neu` | Form: Krankmeldung erfassen |
| POST | `/krank` | Krankmeldung submitten → `status=aktiv` direkt, Mail an HR, Kalender-Event |
| GET | `/antrag/{id}` | Detail-Sicht (eigene oder als HR alles) |
| POST | `/antrag/{id}/storno` | Eigenen aktiven/beantragten Antrag stornieren |

### 2.3 Approval (Magic-Link, kein Login nötig)

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/approval/{token}` | Landing-Page: Antrag-Summary + Bestätigen-Button |
| POST | `/approval/{token}` | Action ausführen (approve/reject), Status updaten, Resturlaub abbuchen, Kalender-Event anlegen, Mail an Antragsteller |

### 2.4 HR (auth + `users.ist_hr=1`)

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/hr` | Übersicht aller Abwesenheiten inkl. Krank-Detail |
| GET | `/hr/users` | Mitarbeiter-Verwaltung |
| POST | `/hr/users/{id}` | User-Update (Jahresanspruch, Resturlaub, Rollen) |
| GET | `/hr/export.csv` | CSV-Export aller Abwesenheiten (gefiltert) |

### 2.5 HTMX-Endpunkte (Partials für AJAX)

| Methode | Pfad | Zweck |
|---|---|---|
| POST | `/htmx/tage-berechnung` | Live-Berechnung: Werktage zwischen zwei Daten + Halbtags-Korrektur, returns HTML-Snippet |

### 2.6 Cron (CLI, Hetzner-Cronjobs)

| Aufruf | Frequenz | Zweck |
|---|---|---|
| `php cron/jahreswechsel.php` | 1x jährlich, 1.1. 02:00 | Resturlaub_aktuell → Resturlaub_vorjahr; Resturlaub_aktuell = jahresanspruch |
| `php cron/verfall.php` | 1x jährlich, 1.4. 02:00 | Resturlaub_vorjahr = 0 |
| `php cron/reminders.php` | täglich, 09:00 | E-Mail an Genehmiger:innen mit pending-Anträgen >2 Tage alt |
| `php cron/cleanup-tokens.php` | täglich, 02:00 | Abgelaufene approval_tokens löschen |

## 3. Auth-Flow (M365-SSO via Entra ID)

### 3.1 Einmaliges Setup (durch Tenant-Admin)

1. Im Microsoft Entra Admin Center → **App-Registrierungen** → **Neue Registrierung**
2. Name: `neo:afk`, Typen: „Konten in diesem Organisationsverzeichnis"
3. Redirect-URI: `https://afk.firma.example/auth/callback` (Web-Plattform)
4. Nach Anlage: **API-Berechtigungen** → **Berechtigungen hinzufügen** → **Microsoft Graph** → **Delegierte Berechtigungen**:
   - `openid`, `profile`, `email`, `offline_access` (Standard für SSO)
   - `User.Read` (Eigenes Profil)
   - `Calendars.ReadWrite.Shared` (in Gruppen-Kalender schreiben)
   - `Group.Read.All` (Gruppen-Mitgliedschaft prüfen)
5. **Admin-Zustimmung erteilen** (notwendig für `Group.Read.All`)
6. **Zertifikate & Geheimnisse** → **Neuer geheimer Clientschlüssel**, in `.env` ablegen als `OAUTH_CLIENT_SECRET`
7. Anwendungs-(Client-)ID + Verzeichnis-(Mandanten-)ID notieren → `.env`: `OAUTH_CLIENT_ID`, `OAUTH_TENANT_ID`

### 3.2 Login-Flow

```
User → /login
  → Backend: redirect zu https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize
    ?client_id=...&response_type=code&redirect_uri=...&scope=...&state=<csrf>
User → Microsoft Login
User → /auth/callback?code=...&state=...
  → Backend: validate state, POST zu /token-Endpoint mit code + client_secret
    → access_token, id_token, refresh_token
  → Decode id_token, extract `oid`, `email`, `name`
  → SELECT/INSERT users WHERE entra_oid = oid; if new + first user: ist_hr=1 (Bootstrap-Admin)
  → Session anlegen mit user_id
  → Redirect zu /
```

### 3.3 Session-Lifetime

- 8 Stunden Inaktivität → automatisches Logout
- Cookie: `Secure`, `HttpOnly`, `SameSite=Lax`
- CSRF-Token in jedem Form

## 4. Magic-Link-Approval-Flow

```
User submits /antrag (POST)
  → Backend: INSERT absence (status=beantragt)
  → Generate opaque random token (approve): bin2hex(random_bytes(32))
  → INSERT approval_tokens (absence_id, token_hash=SHA-256(token), action=approve, expires_at=now+7d)
  → Same für reject-action mit eigenem Token
  → Mail an genehmiger.email mit zwei Links:
    https://afk.firma.example/approval/<token-approve>
    https://afk.firma.example/approval/<token-reject>

Genehmiger klickt approve-Link:
  → GET /approval/<token>
  → Backend: SELECT approval_tokens WHERE token_hash=SHA-256(token) AND expires_at > NOW() AND used_at IS NULL
    → if not found: 404 (Token ungültig / abgelaufen / verbraucht)
  → SELECT absence; render Bestätigungs-Seite mit Summary (action und absence_id stehen in der token-Zeile)
  → User klickt "Bestätigen"
  → POST /approval/<token>
  → Backend: re-validate, mark used_at, perform action:
    - if approve:
      - UPDATE absences SET status='aktiv'
      - Resturlaub abbuchen (zuerst vorjahr, dann aktuell)
      - Microsoft Graph: POST /groups/{groupId}/calendar/events (Subject: "[URLAUB] Vorname Nachname")
      - UPDATE absences SET kalender_event_id
      - Mail an absence.user.email: "Urlaub genehmigt"
    - if reject:
      - UPDATE absences SET status='abgelehnt', begruendung_ablehnung = form.begruendung
      - Mail an absence.user.email: "Urlaub abgelehnt"
```

## 5. Krankmeldung-Flow

```
User submits /krank (POST)
  → INSERT absence (art=krank, status=aktiv direkt, kein Genehmiger nötig)
  → Microsoft Graph: POST event "Abwesend – Vorname Nachname" (NICHT "Krank"!)
  → UPDATE absence SET kalender_event_id
  → Mail an HR-Verteiler: "Krankmeldung von ..."
  → audit_log
  → Redirect zu / mit Erfolgs-Toast
```

## 6. Storno-Flow

```
User klickt /antrag/{id}/storno
  → Validate: absence.user_id = currentUser.id (oder ist_hr)
  → if absence.kalender_event_id != NULL:
    Microsoft Graph DELETE event
  → if absence.art = 'urlaub' AND absence.status was 'aktiv':
    Resturlaub_aktuell zurückbuchen (NICHT auf vorjahr — Vereinfachung wie im Power-Platform-Vorgänger)
  → UPDATE absences SET status='storniert', kalender_event_id=NULL
  → Mail an user
```

## 7. DSGVO-Maßnahmen

- **Krank-Daten** sind in DB sichtbar nur für: Antragsteller:in selbst + HR-Rolle
- **Krank-Listen-Sicht** für Nicht-HR ausgeblendet (Backend-Filter, nicht Frontend-Trick)
- **Geteilter Kalender** zeigt für Krank-Events nur „Abwesend – Name" (kein „Krank"-Hinweis)
- **Audit-Log** für alle write-Aktionen — DSGVO Art. 30 Verzeichnis
- **Magic-Link-Tokens** mit kurzer Lebenszeit (7 Tage) und einmal-nutzbar
- **Sessions** mit `Secure` + `HttpOnly` + `SameSite`
- **Auskunftsrecht (Art. 15):** HR-View hat „Daten-Export pro User"-Funktion
- **Recht auf Löschung (Art. 17):** HR kann User-Account auf `ist_aktiv=0` setzen, Daten bleiben für Aufbewahrungsfristen (Steuerrecht: 6 Jahre)

## 8. Berechtigungs-Matrix

| Aktion | User selbst | HR | Anonymous (mit valid Token) |
|---|---|---|---|
| Eigenes Dashboard sehen | ✓ | ✓ | — |
| Eigene Anträge sehen | ✓ | ✓ | — |
| Fremde Anträge sehen | — | ✓ (alle, inkl. Krank) | — |
| Resturlaub setzen | — | ✓ | — |
| Antrag stellen | ✓ | ✓ | — |
| Antrag stornieren | ✓ (eigene) | ✓ (alle) | — |
| Approval ausführen | — | — | ✓ (über Magic Link, einmalig) |
| HR-View | — | ✓ | — |
| User-Verwaltung | — | ✓ | — |

Erzwungen via Slim-Middleware `RequireAuth` und `RequireHR`.

## 9. Microsoft Graph API — verwendete Endpunkte

| Endpoint | Zweck |
|---|---|
| `POST /groups/{group-id}/calendar/events` | Kalender-Event erstellen |
| `DELETE /groups/{group-id}/calendar/events/{event-id}` | Kalender-Event löschen |
| `GET /me` | User-Profil (beim ersten Login) |
| `POST /me/sendMail` | Mails versenden (vom angemeldeten User-Account) |
| `GET /groups/{group-id}/members` | Optional: Liste der MAs für Bootstrap |

**Token-Strategie:** Wir nutzen den User-Access-Token aus dem OAuth-Flow für alle Graph-Calls — d.h. die App handelt **im Namen des angemeldeten Users**. Mails kommen also vom User selbst (Antragsteller), nicht von einem Service-Account. Für Cron-Jobs (Reminder etc.): siehe § 10.

## 10. Service-Account für Cron + System-Mails

Cron-Skripte können nicht im Namen eines eingeloggten Users laufen. Lösung:

**Option A — Client-Credentials-Flow** (Application-Token):
- Entra-ID-App-Registrierung erhält **Application-Berechtigungen** (zusätzlich zu Delegated):
  - `Mail.Send` (App)
  - `Calendars.ReadWrite` (App)
- Cron nutzt OAuth2 Client-Credentials Grant → bekommt App-Token
- Mails kommen von einer Mailbox (wir brauchen eine dedizierte Service-Mailbox, z.B. `abwesenheit@firma.example`)

**Option B — kein Microsoft-Mail-Versand für Cron**:
- Cron nutzt SMTP direkt (Hetzner stellt SMTP), z.B. `phpmailer/phpmailer` mit From-Adresse `noreply@firma.example`
- Einfacher, kein App-Token-Aufwand

**Empfehlung:** Option B. Cron-Mails sind technisch und müssen nicht von M365-Mailbox kommen.

## 11. Deployment auf Hetzner Webhosting

- Hetzner-Webhosting-Plan mit SSH-Zugang
- DocumentRoot zeigt auf `public/` (via .htaccess oder Webhosting-Konfig)
- Composer-Install lokal, `vendor/` nicht in Git, aber im Deploy mitgepackt
- Tailwind-CSS lokal kompiliert (`npm run build:css`), das CSS-File mitgedeployt
- DB-Migrations: einfach SQL-Scripts in `migrations/`, manuell via `mysql < migration.sql` oder kleines `php migrate.php`-Script
- `.env` für Secrets (nicht in Git), wird via SSH einmalig auf Server abgelegt
- `deploy.sh`:
  ```bash
  rsync -avz --delete \
    --exclude='.git' --exclude='.env' --exclude='node_modules' --exclude='tests' \
    ./ user@host:/var/www/abwesenheits-app/
  ssh user@host "cd /var/www/abwesenheits-app && composer install --no-dev --optimize-autoloader"
  ```
- Cron-Jobs in Hetzner-KonsoleH eintragen mit `php /var/www/abwesenheits-app/cron/<file>.php`

## 12. Locked Decisions (2026-05-08)

1. **Mails:** **alle** Mails (Approval an Genehmiger:in, Krank-Notif an HR, System-Reminder, Storno-Bestätigung) gehen via SMTP vom Service-Account `noreply@firma.example`. Konfiguration via `.env` (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM_EMAIL`). Microsoft Graph wird **nur** für Kalender-Events benutzt, nicht für Mails.
2. **Domain:** `https://afk.firma.example`. OAuth Redirect URI = `https://afk.firma.example/auth/callback`.
3. **HR-Bootstrap:** der erste erfolgreich anmeldende User bekommt automatisch `ist_hr=1` UND `ist_genehmiger=1`. Spätere User landen mit beiden Flags auf 0. HR kann später weitere User hochstufen (HR-View → User-Verwaltung).
4. **Backups:** Hetzner-Standard-Server-Backups reichen. Kein separater `mysqldump`-Cron.
5. **Logging:** Monolog mit zwei Channels:
   - `app` → `var/logs/app.log` (Errors, Warnings, Info)
   - `audit` → `var/logs/audit.log` (jede Schreib-Operation auf `users` und `absences` mit Vorher-/Nachher-Werten als JSON)
6. **Mail-Templates:** simple HTML mit Inline-CSS (Twig-Templates unter `src/Templates/mails/`), kein JavaScript, kein externes CSS — kompatibel mit allen Mail-Clients inkl. Outlook.
