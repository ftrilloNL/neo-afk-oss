# Absence-Workflow

Die zentrale Domäne: Urlaubsanträge und Krankmeldungen, ihre Lifecycle-States
und Side-Effects (Kalender-Events, Resturlaub-Abbuchung, Auto-OOO, Mails).

## Status-Modell für `absences.status`

```
beantragt → aktiv      (durch Approve)
beantragt → abgelehnt   (durch Reject)
beantragt → storniert   (durch Storno)
aktiv     → storniert   (durch Storno)
```

Krankmeldungen starten direkt mit `status='aktiv'` — kein Approval-Schritt
(`src/Controllers/KrankController.php:100`).

Automatische Ablehnung mit `status='abgelehnt'` und `begruendung_ablehnung` setzt,
wenn beim Antrag zu viele Tage angefragt sind (`src/Controllers/AntragController.php:103-110`).

## Werktage-Berechnung

`src/Services/WerktageService.php`. Berücksichtigt:
- Wochenenden (Sa/So zählen nicht).
- Berliner Feiertage aus der `feiertage`-Tabelle (Seed in Migration 002).
- Halbtages-Korrektur: Antragsteller wählt im Form `halbtag_start` (ganztag / ab Nachmittag) und `halbtag_ende` (ganztag / bis Mittag). Wenn Halbtag, wird der jeweilige Rand-Tag mit 0,5 statt 1,0 gezählt.

`tage_gezaehlt` ist `DECIMAL(4,1)` — Halbtages-genau.

Mermaid-Diagramm des Werktage-Algorithmus: `architecture.md` (Root).

## Antrags-Flow (Urlaub)

### `POST /antrag` → `AntragController::submit`

`src/Controllers/AntragController.php:48`:
1. Body-Parsing: `startdatum`, `enddatum`, `halbtag_start`, `halbtag_ende`, `genehmiger_id`, `notiz`, `ooo_internal`, `ooo_external`.
2. Validierung: Datum-Format, End >= Start, Genehmiger != Antragsteller, Halbtag-Werte aus Whitelist.
3. `WerktageService::compute` → `tage_gezaehlt`. Falls 0 (z.B. Antrag über ein Wochenende): Fehler.
4. **Resturlaub-Check vor Insert:** `verfuegbar = aktuell + vorjahr`. Wenn `tage_gezaehlt > verfuegbar`: Insert mit Status `beantragt`, dann sofort Update auf `abgelehnt` mit auto-generierter Begründung. Mail über Approval-Pfad **wird nicht** verschickt (kein Genehmiger-Roundtrip nötig).
5. Sonst: Insert mit `status='beantragt'`, `genehmiger_id` gesetzt, OOO-Texte mit drin (NULL falls leer).
6. `ApprovalService::requestApproval($absenceId)` triggert die Approval-Mail mit Magic-Links (Approve + Reject als getrennte Tokens, beide 7 Tage gültig, sha256-hashed in `approval_tokens`).

### `GET /approval/{token}` → `ApprovalController::landing`

Public-route (keine Auth). Validiert Token-Hash gegen DB. Zeigt eine Landing-Page
mit den Antrags-Details + Approve-Button (POST gleicher URL).

### `POST /approval/{token}` → Token-basierter Approve/Reject

`src/Services/ApprovalService.php:96` (`processTokenAction`):
1. Token in DB als `used` markieren.
2. Alle anderen Tokens für dieselbe Absence invalidieren (z.B. der Reject-Token wenn Approve geklickt, oder umgekehrt).
3. `executeAction` (siehe unten).

### In-App-Approve über `/genehmigungen/{id}/approve`

`src/Services/ApprovalService.php:111` (`processInAppAction`):
1. Berechtigungs-Check: Aufrufer **muss** `genehmiger_id` des Antrags sein.
2. Status-Check: Antrag muss noch `status='beantragt'` sein.
3. Tokens für diese Absence invalidieren (falls auch Mail-Link verschickt wurde).
4. `executeAction`.

## Transaktionaler `executeAction` (Approve)

`src/Services/ApprovalService.php:140`. Reihenfolge ist kritisch wegen des
gemischten Modells aus externer API (Graph) und DB.

```
1. Graph-Call: createCalendarEvent → Event-ID erhalten
                                            │
                                            ▼ (wenn fehlschlägt: nichts geändert)
2. DB-Transaction (begin):
     - deductFromUser (Vorjahr zuerst, dann Aktuell)
     - update absences SET status='aktiv', kalender_event_id=…
     - audit_log INSERT
   commit
                                            │
                                            ▼ (wenn fehlschlägt: rollback + compensating Graph-Delete)
3. Best-effort: setAutoReply NUR wenn startdatum<=today
                (zukünftige Urlaube: cron/ooo-sync.php aktiviert OOO erst am Start-Tag)
4. Best-effort: send approval-decision Mail
```

**Fallback-Texte für OOO** (`src/Services/ApprovalService.php:177-185`): wenn beide
absence-OOO-Felder NULL sind, wird derselbe deutsche Standard-Text für intern und
extern genutzt:

> *Ich bin vom DD.MM.YYYY bis DD.MM.YYYY außer Haus. Ihre Nachrichten werden in der Zwischenzeit nicht gelesen. Bei dringenden Anliegen wenden Sie sich bitte an meine Kolleg:innen.*

**User-Texte werden HTML-escaped** (`ApprovalService::renderOooText`, `src/Services/ApprovalService.php:265`) — Plain-Text rein, mit `htmlspecialchars` + `nl2br` zu `<p>...</p>` verpackt. Verhindert XSS in den auto-versandten Mails.

**Mail-Best-Effort**: bei Mail-Failure wird der Approve **nicht** zurückgesetzt — der DB-State stimmt schon, nur der Antragsteller erfährt es eben nicht per Mail. Fehler nur in `error.log`.

## Storno-Flow

`POST /antrag/{id}/storno` → `src/Controllers/StornoController.php`.

### Berechtigung

- Owner darf seinen eigenen Antrag stornieren.
- HR darf jeden stornieren.
- Bereits `storniert` / `abgelehnt`: keine erneute Stornierung möglich.

### Reihenfolge (umgekehrt zur Approve-Logik)

`src/Controllers/StornoController.php:48`:

```
1. Event-ID merken (DB-Update setzt sie gleich auf NULL)
2. DB-Transaction (begin):
     - Refund: NUR bei art='urlaub' UND status='aktiv'
                 → refundToAktuell (nicht aufs Vorjahr — Vereinfachung)
     - update absences SET status='storniert', kalender_event_id=NULL
     - audit_log INSERT
   commit
3. Best-effort: deleteCalendarEvent — 404 ist OK (idempotent im GraphClient)
4. Best-effort: clearAutoReplyIfOurs — nur wenn unser Marker im OOO-Text steht
5. Best-effort: send storno-confirmation Mail
```

Falls Schritt 3 mit echtem Fehler scheitert (nicht 404): DB-State stimmt schon
(storniert), nur der Calendar-Event hängt eventuell. HR kann manuell aufräumen.

### Refund-Vereinfachung

Refund geht **immer auf Aktuell**, nie auf Vorjahr. Heißt: wenn der Antrag
ursprünglich aus dem Vorjahr abgebucht wurde (z.B. 3 Tage Vorjahr + 2 Tage Aktuell),
und dann storniert wird, bekommt der User 5 Tage auf Aktuell zurück. Vorjahr bleibt.

Diese Vereinfachung ist bewusst — sonst müsste der Refund den ursprünglichen
`deduction`-Split aus dem Audit-Log rekonstruieren. Kommt aus der Power-Platform-Phase
und blieb so. Siehe `tensions.md`.

## Krankmeldungs-Flow

`POST /krank` → `src/Controllers/KrankController.php:46`. Anders als Urlaub:

1. Validierung + Werktage-Berechnung wie beim Antrag.
2. `createCalendarEvent` mit Subject `Abwesend – {Name}` (DSGVO Art. 9).
3. Insert mit `status='aktiv'` direkt (kein Approval-Schritt).
4. `audit.log` mit Action `absence.created`.
5. Optionales Auto-OOO: wenn min. eine der OOO-Textareas ausgefüllt, `setAutoReply` aufrufen — **ohne Fallback**. Wenn beide leer, kein OOO-Call. Wenn nur einer ausgefüllt: derselbe Text für beide Audiences.
6. HR-Notification-Mail an `HR_NOTIFICATION_EMAIL` (kann Komma-Liste sein).

**Kein Resturlaub-Abzug bei Krank** — Krank-Tage werden separat gezählt (siehe Home-Stat „Krank-Tage YTD").

## OOO-Sync-Cron

`cron/ooo-sync.php`, läuft täglich morgens (z.B. 06:00). Aktiviert Auto-OOO
für Urlaubs-Anträge mit `startdatum=today`.

**Begründung:** Microsoft Graph `mailboxSettings.automaticRepliesSetting` hat
nur einen Zeitraum-Slot pro Mailbox. Würde man beim Approve direkt setzen
(was vor diesem Cron Code-Stand war), würde der OOO eines später-approvedten
Antrags den eines früher-approvedten überschreiben — selbst wenn deren Daten
nicht überlappen. Die DB hält weiterhin OOO-Texte pro Antrag (`absences.ooo_internal`
+ `absences.ooo_external`); der Cron aktiviert am Start-Tag den passenden.

Microsoft schaltet via `status='scheduled'` zum `scheduledEndDateTime` selbst
ab — kein Clear-Cron nötig.

Krankmeldungen sind hiervon nicht betroffen (im KrankController sofort gesetzt;
kein Approve-Schritt, kein Konflikt zwischen Future-Anträgen).

## Reminder-Cron

`cron/reminders.php`, läuft täglich 09:00 (siehe Cron-Setup in `docs/deployment.md`).

Trigger-Bedingung in `src/Models/AbsenceRepository::listPendingNeedingReminder`:
- `status='beantragt'`
- `genehmiger_id IS NOT NULL`
- Antrag älter als `REMINDER_AFTER_DAYS` (default 2)
- `last_reminder_sent_at IS NULL` ODER `last_reminder_sent_at` älter als `REMINDER_REPEAT_AFTER_DAYS` (default 5)

**Catch-up-fähig:** wenn Cron einen Tag nicht läuft, holt der nächste Lauf verpasste
Reminder nach. Doppel-Reminder durch das `last_reminder_sent_at`-Tracking
verhindert.

## Approval-Token-Lifecycle

- Generiert in `ApprovalService::generateAndStoreToken`. Plain-Token (64 Hex-Chars) wird in der Mail-URL verschickt; in der DB nur der SHA-256-Hash.
- TTL 7 Tage (`$expiresAt`).
- Token-Aktionen: `approve` oder `reject` — pro Antrag werden beim Senden beide gleichzeitig erzeugt und im selben Mail-Body verlinkt.
- Single-use: `markUsed` setzt `used_at`; gleichzeitig invalidiert `invalidateAllForAbsence` alle Tokens dieser Absence.
- `cron/cleanup-tokens.php` löscht täglich um 02:00 abgelaufene + verwendete Tokens (kosmetisch — sie wären lookup-mäßig eh dead).

## Tensions

- **Refund-Vereinfachung** (siehe oben) — bei stornierten Urlauben die teilweise aus Vorjahr abgebucht waren, geht der Vorjahr-Anteil verloren. Bei einem 10-MA-Startup mit kurzem Vorjahr-Window (Verfall 31.03.) selten relevant.
- **Mail-Versand best-effort, ohne Retry** (`src/Services/ApprovalService.php:199`, `src/Controllers/StornoController.php:113`) — bei SMTP-Outage in dem Moment geht die Mail einfach verloren. `error.log` ist die einzige Spur. Kein Job-Queue, kein Retry-Mechanismus.
- **Storno macht keinen Compensating-Graph-Recreate** — wenn die DB-Transaction nach Calendar-Delete scheitert (selten, weil Event-Delete vor Transaction), wäre der Calendar-Event weg ohne dass der Antrag storniert ist. Aktuell ist die Reihenfolge umgekehrt (Transaction vor Delete), daher nicht relevant. Bei zukünftigen Refactors zu beachten.
- **`absences.art` ist ENUM('urlaub','krank')** — bei einer Erweiterung um Sonderurlaubs-Typen (siehe `future-features.md` § 6) wäre eine Foreign-Key auf eine `absence_types`-Tabelle saubererer. Aktuell ist's hartcodiert.
- **Auto-Ablehnung bei Resturlaub-Überzug** (`src/Controllers/AntragController.php:101`) schreibt einen Antrag in zwei Schritten (Insert + Update). Kein Audit-Log-Eintrag für den initialen `beantragt`-Status — direkt `abgelehnt`. Kann verwirren wenn jemand das Audit-Log durchforstet.
- **`processInAppAction` setzt voraus dass der Caller den exact `$absence` aus der DB lädt** (`src/Services/ApprovalService.php:114`) — keine erneute Lookup mit FOR UPDATE. Race-Condition möglich wenn zwei Genehmigungen parallel laufen, in der Praxis bei 1 Genehmiger pro Antrag irrelevant.
