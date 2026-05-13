# Tensions

Konsolidierte Liste der beobachteten Inkonsistenzen, bewussten Vereinfachungen
und offenen Designfragen, aggregiert aus den Tensions-Sections der anderen
architecture-Docs.

**Konvention:** observed conditions mit Code-Citation. Keine Lösungs-Vorschläge —
die kommen in Code-Review oder im `future-features.md`-Backlog.

## Datenintegrität + Inkonsistenz-Risiken

- **Storno-Refund geht immer auf Aktuell, nie auf Vorjahr.** Bei stornierten Urlauben, die teilweise aus Vorjahr abgebucht waren, geht der Vorjahr-Anteil verloren. (`src/Services/ResturlaubService::refundToAktuell`, `src/Controllers/StornoController.php:60-62`).
- **Auto-Ablehnung beim Resturlaub-Überzug schreibt zwei Audit-Steps** — Insert mit `beantragt`, dann Update auf `abgelehnt`. Im Audit-Log gibt's nur den Reject-Eintrag, kein initialer Create. (`src/Controllers/AntragController.php:101-110`).
- **Race-Condition bei In-App-Approval** — `processInAppAction` macht keinen FOR-UPDATE-Lock auf der Absence-Row. Bei zwei parallelen Approve-Requests könnte beide laufen. In der Praxis irrelevant (1 Genehmiger pro Antrag). (`src/Services/ApprovalService.php:111`).
- **Mail-Versand best-effort, kein Retry.** Bei SMTP-Outage während eines Approve geht die Bestätigungs-Mail verloren. Spur nur in `error.log`. (`src/Services/ApprovalService.php:199`).
- **Migration-Tracking fehlt.** Welche Migrations eingespielt wurden, ist nicht in der DB persistiert. Tracking läuft via Git-Commit-History + Server-Memory.

## Bewusste Vereinfachungen

- **`absences.art` ist ENUM('urlaub','krank')**, kein FK auf `absence_types`-Tabelle. Bei Erweiterung um Sonderurlaub (Hochzeit, Umzug, Bildungsurlaub etc.) müsste das aufgebrochen werden — siehe `future-features.md` § 6.
- **Keine echte User-Löschung, nur Inaktiv-Flag** — FK-Constraints auf `absences`/`audit_log`/`approval_tokens` machen echtes DELETE praktisch unmöglich. Bei DSGVO Art. 17 (Löschungs-Anfrage) müsste eine eigene Anonymisierungs-Logik gebaut werden.
- **`feiertage`-Tabelle nur Berlin.** Bei MAs in anderen Bundesländern (z.B. Bayern hat mehr Feiertage) wäre Bundesland-Spalte nötig.
- **Display-Name wird beim SSO mit Microsoft-Wert überschrieben.** HR-Pre-Create-Schreibweise unterliegt automatisch — Microsoft = Source of Truth.

## Sicherheit + Permissions

- **`MailboxSettings.ReadWrite` Application Permission ist Tenant-weit.** Die App kann theoretisch jede User-Mailbox manipulieren. Eine Exchange Online Application Access Policy zur Einschränkung existiert nicht.
- **CSRF-Token wird nicht pro Request rotiert** — eine Token pro Session. Bei Token-Theft bleibt das Risiko bis zum Logout. Pragmatisch akzeptiert für mehrere Browser-Tabs.
- **`/genehmigungen` ist nicht middleware-gesperrt für Non-Genehmiger** — Sidebar-Link verschwindet, Direkt-URL zeigt eine leere Liste. Kein Schaden, aber keine Hard-Sperre.
- **id_token-`access_token` wird beim SSO nur kurzfristig verwendet** (für Avatar-Fetch), dann verworfen — kein Long-Term-Storage. Heißt: Cron-Jobs können nicht auf User-Delegated-APIs zugreifen.

## DSGVO + Privacy

- **Audit-Log-Payload ist JSON ohne Schema-Versionierung.** Bei Schema-Erweiterungen können alte Einträge nicht-versionsfähig sein. Template-Renderer (`fmt`-Macro) handhabt NULLs sauber, aber strukturell brittle.
- **`actor_display_name` ist computed via JOIN, nicht persistiert im Audit-Log.** Wenn ein User später deaktiviert wird, zeigt das Audit-Log einen leeren Akteur statt eines Namens-Snapshots. Aktuell akzeptabel weil wir nicht löschen.
- **`var/avatars/`-Files sind via `/avatar/{id}` für jeden eingeloggten User erreichbar.** Privacy-Trade-off: niedrige Sensibilität (Avatare sind eh in M365 sichtbar), aber kein expliziter Permission-Check pro Avatar.
- **Notiz beim Antrag** ist nur für Genehmiger:in + HR sichtbar (im Template gefiltert), aber im Audit-Log via `before`/`after`-Payload möglicherweise indirekt sichtbar (aktuell wird `notiz` nicht im Audit-Diff trackiert, daher kein Issue — bei Erweiterung zu beachten).

## UX + Wahrnehmung

- **Email-Match beim Pre-Create-Verlinken ist case-insensitive aber 1:1.** Schreibweisen wie `admin@firma.example` vs. `admin@firma.example` werden NICHT als Match erkannt; Defense via Similarity-Warnung im Form (`UserRepository::findSimilarByEmail`) hilft, aber HR muss bewusst override'n.
- **`prompt=select_account` zwingt Microsoft-Account-Picker auch bei nur einem aktiven Account.** Bewusst gewählt für die wenigen User mit mehreren M365-Accounts, kostet einen extra Klick für alle anderen.
- **Audit-Log-Default-Limit 200, keine Pagination.** Bei mehreren Jahren Audit kann das knapp werden. Filter helfen, aber „alle Einträge ab 2027" gibt's nicht ohne Code-Änderung.
- **Storno-Mail enthält keinen Hinweis ob ein Calendar-Event gehängt blieb.** Wenn Graph-Delete scheitert, bekommt der User eine erfolgreich-storniert-Mail; im Outlook-Kalender bleibt aber der Event.

## Operationelles

- **Refresh-Token-Recovery ist manuell** — 90-Tage-Inactivity-TTL des Refresh-Tokens. Bei Ablauf scheitert SMTP komplett, kein Auto-Reset. `bin/setup-smtp-oauth.php` muss erneut laufen.
- **Cron-`bootstrap.php` exposed Container als `$GLOBALS['cron_container']`** — pragmatisch für 4 Skripte, würde bei Wachstum nicht skalieren.
- **`Config::get` wirft `RuntimeException` bei missing Env** ohne Default. In `production`-Mode keine sinnvolle Fehlermeldung im Browser — User sieht Slim-Standard-500, echter Grund im `error.log`.
- **Mail-Templates nutzen Inline-Styles** statt Tailwind — Konvention nicht dokumentiert beim Hinzufügen neuer Mail-Templates.

## Auto-OOO + Marker-Pattern

- **`clearAutoReplyIfOurs` läuft auch bei Krank-Storno**, auch wenn kein OOO via App gesetzt wurde. Idempotent ohne Marker, kein Schaden, aber unnötiger Graph-Roundtrip. (`src/Controllers/StornoController.php:97-110`).
- **Marker im OOO-HTML-Body ist ein HTML-Kommentar.** Wenn der User in Outlook den OOO-Text manuell editiert, behält der Marker eventuell — Outlook könnte ihn als verstecktes Element interpretieren und im Edit-Mode anzeigen oder verlieren. Beobachtbar nur durch Produktions-Praxis.
- **Bei Race zwischen App-OOO-Set und User-Manuell-Edit** in Outlook ist Microsoft's `mailboxSettings`-API last-write-wins. Wenn Microsoft den OOO startet (Status `alwaysEnabled`) bevor wir nochmal patchen, könnten unsere `clearAutoReplyIfOurs`-Logik dann auf einem Microsoft-modifizierten Marker treffen — vermutlich identisch erhalten, aber nicht garantiert.

## Frontend + Mobile

- **Mobile-Sidebar-Toggle via Vanilla-JS** (`public/assets/mobile.js`). Würde bei Erweiterung mehrere JS-Files zu einer Pseudo-Build-Pipeline führen. Aktuell ~30 Zeilen, ok.
- **Tabellen sind nur horizontal-scrollbar auf Mobile**, nicht in Card-View umgewandelt. Bei langen Zeilen (z.B. HR-Auswertung mit Person + Zeitraum + Tage + Status + Genehmiger:in) muss man scrollen, kein optimaler Mobile-UX.
- **HTML5-`<input type="date">` Picker folgt System-Locale.** Bei englischen Browser-Settings zeigt's `mm/dd/yyyy` statt `dd.mm.yyyy`. Konnte nicht erzwungen werden ohne JS-Datepicker.

## Fehlende Tests

- **Keine Test-Suite** — PHPUnit ist installiert (siehe `composer.json` require-dev), aber `tests/`-Directory ist leer. Bei wachsendem Feature-Set wahrscheinliche Regressionen ohne Sicherheitsnetz. Kritische Flows wie `WerktageService::compute` und `ResturlaubService::berechneAnteiligenJahresanspruch` wären Kandidaten für die ersten Tests.
