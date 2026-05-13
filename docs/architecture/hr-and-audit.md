# HR-Flows + Audit-Log

HR-spezifische Funktionen — Stammdatenpflege, MA-Onboarding über Pre-Create,
anteilige Berechnung und das übergreifende Audit-Log.

## HR-Stammdaten-Form

`/hr/users/{id}/edit` → `src/Controllers/HrUsersController::edit` und `::update`.
HR-only via `HrMiddleware`.

**Pflegbare Felder** (`src/Models/UserRepository::updateStammdaten`):
- `job_title` (Free-Text bis 100 Chars)
- `eintrittsdatum` (optional)
- `jahresanspruch` (0–99 Tage)
- `resturlaub_aktuell`, `resturlaub_vorjahr` (Decimal, Halbtage erlaubt, Komma oder Punkt akzeptiert)
- `ist_aktiv` (Bool)
- `ist_genehmiger` (Bool)
- `ist_hr` (Bool)

Plus `user_master_data`-Felder:
- `geburtsdatum`, `telefon`, `strasse`, `plz`, `ort`

**Nicht editierbar via Form:**
- `email`, `display_name`, `entra_oid` — kommen aus Microsoft beim SSO und werden dort gepflegt. `display_name` wird sogar beim nächsten SSO mit MS-Wert überschrieben (Microsoft = Source of Truth).

### Transaktionale Update-Logic

`src/Controllers/HrUsersController::update` umschließt drei Operationen in einer DB-Transaction:
1. `users.updateStammdaten` (operative Felder)
2. `user_master_data.upsert` (persönliche Felder)
3. `audit_log` mit `before`/`after`-Diff im Payload

Bei Exception → Rollback. Sonst: Flash-Message + Redirect auf `/hr/users`.

### Anteilige Berechnung Jahresanspruch

`src/Services/ResturlaubService::berechneAnteiligenJahresanspruch`. Pure Funktion,
keine DB-Touches.

**Regel:**
- Eintritt am 1. eines Monats → dieser Monat zählt voll
- Eintritt mid-month → erst ab Folgemonat
- Rundung auf halbe Tage (`round(x*2)/2`)
- Eintritt vor Referenz-Jahr → voller Anspruch
- Eintritt im nächsten Jahr → 0

**Beispiele bei jahresanspruch=30, Referenz=heute im laufenden Jahr:**

| Eintritt | Anteilig |
|---|---|
| 01.01. | 30,0 |
| 01.07. | 15,0 |
| 15.07. | 12,5 |
| 01.12. | 2,5 |
| 15.12. | 0,0 |

Im **Pre-Create-Form** (`/hr/users/new`): Checkbox „Anteilig berechnen", default ON.
Wenn aktiviert und Eintrittsdatum gesetzt, überschreibt das Backend den
`resturlaub_aktuell`-Eingabewert mit dem berechneten Wert. HR-Eingabe ist sonst
authoritativ.

**Nicht im Edit-Form** — bewusst, weil dort HR den exakten Stand setzt (nach
historischen Anpassungen, Übertragungen etc.).

## MA-Pre-Create (vor erstem SSO)

Standard-Onboarding: HR legt einen MA an, bevor dieser sich das erste Mal
einloggt. Beim Erst-Login wird der Account via Email-Match verlinkt.

### Datenmodell-Voraussetzung

Migration 003 macht `users.entra_oid` nullable (vorher `NOT NULL UNIQUE`). MySQL
erlaubt mehrere NULL-Werte in einem UNIQUE-Index, daher können beliebig viele
„Pending Login"-User parallel existieren.

### Form-Flow

`/hr/users/new` (`src/Controllers/HrUsersController::newForm` + `::create`):

Felder (alle in einem Form):
- Anzeigename + E-Mail (Pflicht, E-Mail wird auf Format und Uniqueness validiert)
- Job-Titel, Eintrittsdatum (optional)
- Jahresanspruch, Resturlaub aktuell + Vorjahr
- Anteilig-Berechnen-Checkbox
- Rollen: Aktiv, Genehmiger:in, HR
- E-Mail-Similarity-Warnung (siehe unten)

### Email-Similarity-Defense

Vor dem Insert prüft `src/Controllers/HrUsersController::create` mit
`UserRepository::findSimilarByEmail`, ob bereits ein User mit ähnlicher E-Mail
existiert (gleicher Local-Part oder Substring-Match in derselben Domain).

Wenn Treffer **und** Body enthält `override_similarity` nicht: Form wird mit
Warnung neu gerendert. HR muss bewusst die „Trotzdem als neuen Mitarbeiter
anlegen"-Checkbox setzen, dann beim zweiten Submit geht's durch.

Vermeidet versehentliche Doppel-Anlagen wie `admin@firma.example` vs.
`admin@firma.example` — die beim ersten SSO sonst zu zwei separaten
User-Datensätzen führen würden.

### Link-Up beim ersten SSO

Bei der ersten Anmeldung des MA durchläuft `AuthController::upsertUser`
(`src/Controllers/AuthController.php:90`) drei Suchstufen:
1. `entra_oid`-Match
2. Pre-Created-Match (case-insensitive Email + `entra_oid IS NULL`)
3. Neu anlegen

Im Pre-Created-Pfad wird die `entra_oid` ergänzt, plus der Display-Name auf den
Microsoft-Wert gesetzt. Alle anderen Felder (Resturlaub, Rollen, Stammdaten)
bleiben unverändert — das ist genau der Wert, den HR vorab gepflegt hat.

### „Pending Login"-Badge

In `/hr/users` zeigt das Template ein gelbes Pill „Pending Login" für jeden User
mit `entra_oid IS NULL`. Verschwindet nach dem ersten SSO-Login automatisch.

## Avatar-Sync

Implementiert in `src/Services/AvatarService.php`. Triggert im
`AuthController::callback` direkt nach `upsertUser`.

**Source:** Microsoft Graph `/me/photo/$value` mit dem User-Access-Token aus dem
gerade-erfolgten Auth-Code-Flow. Delegated `User.Read` reicht — keine extra
Application Permission nötig.

**Storage:** `var/avatars/{user-id}.jpg`, chmod 0644. Atomic-write via `.tmp` +
rename. Wird über die Route `/avatar/{id}` ausgeliefert (`src/Controllers/AvatarController.php`),
AuthMiddleware-gated, mit `Cache-Control: private, max-age=86400`.

**404 von Graph** (= kein Foto in M365 hinterlegt): vorhandene alte Datei wird
gelöscht, damit das UI auf Initials zurückfällt.

**Computed `has_avatar`** in `UserRepository::findById` (`src/Models/UserRepository.php:23`):
FS-Check via `is_file()` und `filesize() > 0`. Wird ins Layout-Template übergeben
für die Sidebar-Avatar-Anzeige.

## Audit-Log

Tabelle `audit_log`, befüllt durch `AuditLogRepository::log($userId, $action, $entityType, $entityId, $payload)`.

`$userId` ist nullable — bei Token-basierten Approvals (Magic-Link aus Mail) ist
kein Session-User-Context vorhanden, der Eintrag wird mit `user_id=NULL` geschrieben
und im UI als „System" angezeigt.

### Aktions-Vokabular (aktuell)

| Action | Wann | Payload-Form |
|---|---|---|
| `absence.approval_requested` | nach Insert eines neuen Antrags, Approval-Mail wurde versendet | `{genehmiger_id}` |
| `absence.approved` | erfolgreicher Approve | `{vorjahr_used, aktuell_used}` |
| `absence.rejected` | Reject mit Begründung | `{comment}` |
| `absence.cancelled` | Storno | `{previous_status}` |
| `absence.created` | Krankmeldung (kein Approval-Schritt) | `{art, tage_gezaehlt}` |
| `user.pre_created` | HR legt MA über `/hr/users/new` an | `{email, display_name, jahresanspruch, resturlaub_aktuell, resturlaub_vorjahr, anteilig_berechnet}` |
| `user.stammdaten_updated` | HR ändert MA über `/hr/users/{id}/edit` | `{before: {…}, after: {…}, master_data: {…}}` — der einzige Action-Type mit Diff-Struktur |
| `user.annual_rollover` | pro User beim Jahreswechsel-Cron | `{old_aktuell, old_vorjahr, new_aktuell, new_vorjahr}` |
| `system.annual_rollover` | einmal pro Jahreswechsel-Cron-Lauf | `{users_updated}` |

### `/hr/audit` UI

`src/Controllers/AuditController.php` + `src/Templates/hr/audit/index.twig`.

- Filter: Aktion, Akteur, Zeitraum (von/bis).
- Default-Limit 200 Einträge.
- Aktions-Labels werden im Template via `action_labels`-Map deutsch lesbar
  gemacht (z.B. `absence.approved` → „Antrag genehmigt"). Unmappede Aktionen
  fallen auf raw-String.
- **Diff-Rendering**: wenn Payload `before` + `after` enthält → 3-Spalten-Tabelle
  (Feld / Vorher / Nachher), geänderte Zeilen amber hervorgehoben, alte Werte mit
  `line-through`. Sonst flache Key/Value-Tabelle.
- Werte-Formatierung im `fmt`-Macro: Bools → „Ja"/„Nein", NULL → „—", ISO-Datum
  → `dd.mm.yyyy`, Arrays → JSON-Fallback.
- **Feld-Label-Map** (`field_labels`) übersetzt DB-Spaltennamen (z.B. `ist_hr` →
  „HR", `eintrittsdatum` → „Eintrittsdatum"). Unmappede fallen auf raw-Key.

## HR-Auswertung

`/hr` → `src/Controllers/HrController::index`. Tabelle aller Abwesenheiten über
alle User mit Filtern (Art, Status, Person, Zeitraum). Plus Aggregat-Stats:
Summe Tage gesamt, davon Urlaub, davon Krank.

Genehmiger:in-Spalte zeigt seit dem aktuellen Stand den Display-Namen statt der ID
(LEFT JOIN in `AbsenceRepository::listAllWithFilters`).

## Team-Übersicht

`/team` — alle eingeloggten User, nicht nur HR. Karten-Grid (1/2/3 Spalten responsive).
`src/Controllers/TeamController` + `src/Templates/team/index.twig`.

Bewusst keine Hierarchie: kein `manager_id` im Schema, kein Tree-Render. Für 10-MA-Team
ist eine flache Liste mit Avatar, Name, Job-Titel, Email, Telefon, Rollen-Pills
ausreichend.

Inaktive User werden in `UserRepository::listTeam` weggefiltert (`WHERE ist_aktiv = 1`).

## Tensions

- **`actor_display_name` ist computed via JOIN**, nicht persistiert. Wenn ein HR-User später gelöscht würde (was wir nicht tun, nur deaktivieren), zeigt das Audit-Log einen leeren Akteur statt einer Namens-Snapshot.
- **`action`-Strings sind hartcodiert** (keine Enum-Tabelle, keine Konstanten-Klasse). Bei Tippfehler in einem zukünftigen Callsite würde der Eintrag unter einer neuen Action erscheinen — Audit-Log-Filter würde ihn nicht unter den anderen Approve-Aktionen finden.
- **Payload-Struktur ist nicht versioniert.** Wenn wir z.B. `absence.approved` um ein neues Feld erweitern, sind alte Einträge ohne dieses Feld da. Das `fmt`-Macro im Template handelt das (NULL → „—"), aber strukturell brittle.
- **`fetchAndStore` für Avatar wirft alle non-404-Errors silent weg** (`src/Services/AvatarService.php:64`). Bei Graph-Outage erfährt der User nichts — bekommt einfach weiter Initials angezeigt. Pro: User-Erlebnis nicht beeinträchtigt. Contra: Avatar-Sync-Probleme nicht offenkundig.
- **Audit-Log-Pagination fehlt** — Limit 200 hardcoded. Bei 5+ Jahren Audit kann das knapp werden. Filter helfen, aber „alle Einträge ab 2027" gibt's nicht.
- **`/hr/users` listet auch inaktive User** mit reduzierter Opacity — fein für 10–20 MAs, bei wachsendem Team unübersichtlich. Filter „nur aktive zeigen" wäre nice.
- **`UserRepository::findSimilarByEmail` macht SQL-LIKE-Comparison** (`src/Models/UserRepository.php:75-95`) — bei 1000+ Usern langsam. Bei der aktuellen Größenordnung kein Issue.
