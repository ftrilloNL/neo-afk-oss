# Entra-ID-App-Registrierung — Setup-Anleitung

Dieses Dokument richtet sich an den **Tenant-Admin** eures M365-Tenants (wer
Admin-Konsens erteilen darf). Es muss **einmalig vor dem ersten Production-Deploy**
durchgeführt werden.

> Beispiele in diesem Dokument nutzen Platzhalter wie `afk.eure-firma.de` und
> `urlaub@eure-firma.de` — durch eure echten Werte ersetzen.

Ergebnis: drei `.env`-Werte (`OAUTH_CLIENT_ID`, `OAUTH_CLIENT_SECRET`, `OAUTH_TENANT_ID`)
und eine konfigurierte App im Entra-Admin-Center.

## Warum zwei Permission-Typen?

Die App nutzt zwei voneinander getrennte Microsoft-Identity-Flows:

1. **SSO-Login (Delegated Permissions)** — User loggt sich mit seinen M365-Credentials
   ein. Browser-Redirect zu Microsoft, Microsoft liefert ein ID-Token zurück. Damit
   weiß die App, wer der User ist.
2. **Kalender-Schreibzugriff (Application Permissions)** — Die App authentifiziert
   sich selbst (ohne User) gegen Microsoft Graph und schreibt Events in den
   Gruppen-Kalender. Wird gebraucht für:
   - Magic-Link-Approval per Mail (User-Klick aus dem Mailclient, keine Session)
   - Cron-Jobs (Jahreswechsel, Verfall, Reminders)
   - Storno-Refund (Calendar-Event-Delete kann auch lange nach dem Approval passieren)

Beide Permissions werden an **derselben** App-Registrierung konfiguriert.

## Schritt 1 — App-Registrierung anlegen

1. https://entra.microsoft.com → **Anwendungen** → **App-Registrierungen** → **Neue Registrierung**
2. Werte:
   - **Name:** `afk` (Anzeigename im Admin-Center, frei wählbar)
   - **Unterstützte Kontotypen:** *Nur Konten in diesem Organisationsverzeichnis (Einzelner Mandant)*
   - **Umleitungs-URI:** Plattform **Web**, URI `https://afk.eure-firma.de/auth/callback`
3. **Registrieren** klicken.
4. Auf der Übersichts-Seite notieren:
   - **Anwendungs-(Client-)ID** → wird zu `OAUTH_CLIENT_ID`
   - **Verzeichnis-(Mandanten-)ID** → wird zu `OAUTH_TENANT_ID`

## Schritt 2 — Client-Secret erstellen

1. In der App → **Zertifikate & Geheimnisse** → **Neuer geheimer Clientschlüssel**
2. Beschreibung: `production` (oder `production-2026-q2` falls Rotation geplant)
3. Gültigkeit: **24 Monate** (Maximum). Kalender-Eintrag setzen für Rotation 30 Tage vor Ablauf.
4. **Hinzufügen** klicken.
5. **Wert** (nicht Geheimnis-ID!) sofort kopieren → wird zu `OAUTH_CLIENT_SECRET`.
   Microsoft zeigt den Wert nur dieses eine Mal an.

## Schritt 3 — Delegated Permissions für SSO

1. **API-Berechtigungen** → **Berechtigung hinzufügen** → **Microsoft Graph** → **Delegierte Berechtigungen**
2. Folgende fünf Scopes aktivieren:
   - `openid`
   - `profile`
   - `email`
   - `offline_access`
   - `User.Read`
3. **Berechtigungen hinzufügen** klicken.

Diese fünf Scopes werden im Login-Flow automatisch vom User angefragt — keine
Admin-Zustimmung nötig, der User akzeptiert beim ersten Login per Klick.

## Schritt 4 — Application Permissions für Graph

1. **API-Berechtigungen** → **Berechtigung hinzufügen** → **Microsoft Graph** → **Anwendungsberechtigungen**
2. Folgende Scopes aktivieren:
   - `Calendars.ReadWrite` (Application) — für Events im Shared-Mailbox-Kalender
   - `MailboxSettings.ReadWrite` (Application) — für Auto-OOO bei Urlaub
3. **Berechtigungen hinzufügen** klicken.
4. **Wichtig:** Auf der API-Berechtigungen-Übersicht den Button **„Administratorzustimmung
   für [eure Org] erteilen"** klicken und mit Tenant-Admin-Konto bestätigen.
   Ohne diesen Schritt liefert Graph `403 Forbidden`.

Status nach diesem Schritt: bei beiden Permissions muss in der Spalte
*Status* ein grüner Haken „Erteilt für …" stehen.

**Wirkung der Permissions:**
- `Calendars.ReadWrite` wirkt auf den Kalender der Shared Mailbox (z.B. `urlaub@eure-firma.de`) — Events anlegen/löschen
- `MailboxSettings.ReadWrite` wirkt auf **alle** User-Postfächer im Tenant — die App kann automatische Antworten bei jedem User setzen. Falls das zu weitreichend ist, kann via Exchange Online „Application Access Policy" auf bestimmte Mailboxen eingeschränkt werden — aktuell nicht konfiguriert.

## Schritt 5 — Delegated Permission für SMTP-Auth (XOAUTH2)

SMTP-Auth in M365 funktioniert nur noch über OAuth2 (App-Passwörter sind in
Tenants mit Security Defaults deaktiviert). Wir holen einen Refresh-Token für
das `noreply`-Postfach via Device-Code-Flow und verwenden ihn beim Mailversand.

1. **API-Berechtigungen** → **Berechtigung hinzufügen** → **APIs, die meine Organisation verwendet** → **Office 365 Exchange Online** suchen → auswählen
2. **Delegierte Berechtigungen** → `SMTP.Send` aktivieren → **Berechtigungen hinzufügen**
3. Admin-Konsens für `SMTP.Send` klicken (grüner Haken muss erscheinen)

Falls Office 365 Exchange Online nicht in der Suche auftaucht, das alte
Power-Shell-Workaround vermeiden — stattdessen unter **APIs, die meine
Organisation verwendet** nach der GUID `00000002-0000-0ff1-ce00-000000000000`
suchen.

### Device-Code-Flow aktivieren

Damit der Setup-Flow `bin/setup-smtp-oauth.php` funktioniert, muss die
App-Registrierung public-client-fähig sein:

1. **Authentifizierung** → ganz unten **Öffentliche Clientflüsse zulassen** auf **Ja**
2. Speichern

(Das macht die App **nicht** komplett zum Public Client — der Web-Client-Secret für
SSO bleibt erhalten. Der Toggle erlaubt zusätzlich Device-Code- und ROPC-Flows ohne
Secret.)

## Schritt 6 — Token-Konfiguration (optional, empfohlen)

Damit das ID-Token aus dem Login-Flow direkt `email` enthält (und nicht nur
`preferred_username`):

1. **Token-Konfiguration** → **Optionalen Anspruch hinzufügen**
2. Token-Typ: **ID**
3. Anspruch: `email` ankreuzen, **Hinzufügen**

Die App fällt automatisch auf `preferred_username` zurück falls `email` fehlt, dieser
Schritt ist also nicht zwingend.

## Schritt 7 — Werte in `.env` eintragen

Auf dem Production-Server in `.env`:

```
OAUTH_CLIENT_ID=<aus Schritt 1>
OAUTH_CLIENT_SECRET=<aus Schritt 2>
OAUTH_TENANT_ID=<aus Schritt 1>
OAUTH_REDIRECT_URI=https://afk.eure-firma.de/auth/callback
GRAPH_CALENDAR_USER=urlaub@eure-firma.de
```

`GRAPH_CALENDAR_USER` zeigt auf die Shared Mailbox, deren Kalender als
geteilter Abwesenheits-Kalender genutzt wird. Die App schreibt Urlaubs- und
Krank-Events dort hinein.

## Verifikation

Nach Deploy:

1. `https://afk.eure-firma.de/login` → Klick → MS-Login-Maske → einloggen → Redirect auf `/` mit aktivem User-Session. **SSO funktioniert.**
2. Antrag stellen → Approval-Mail kommt an → Magic-Link klicken → Approve → Shared-Mailbox-Kalender öffnen (in Outlook als gemeinsamer Kalender abonniert) → Event sichtbar. **Application Permissions funktionieren.**

Falls Schritt 2 fehlschlägt mit `403`: meistens vergessener Admin-Konsens
(Schritt 4 Punkt 4). Im Admin-Center bei der App nachprüfen, dass die
Application-Permission `Calendars.ReadWrite` den grünen Haken hat.

Falls Mailversand fehlschlägt mit `535 5.7.3`: vergessener Admin-Konsens für
`SMTP.Send` (Schritt 5) oder Refresh-Token noch nicht gesetzt — siehe
[`smtp-setup.md`](smtp-setup.md).

## Secret-Rotation (alle 24 Monate)

1. Neues Secret anlegen (Schritt 2) mit Beschreibung `production-YYYY-QX`
2. `.env` auf dem Server updaten, App reloaden (`touch public/index.php` reicht bei PHP-FPM nicht — über Deploy-Skript oder direkt eine Datei berühren die Opcache invalidiert)
3. Smoke-Test (siehe oben)
4. Altes Secret im Admin-Center löschen
