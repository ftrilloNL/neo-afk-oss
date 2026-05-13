# SMTP-Setup — OAuth2 / XOAUTH2 für das `noreply`-Postfach

Alle Mails der App (Approval-Requests, Entscheidungen, Reminders, HR-Krankmeldung)
gehen über ein dediziertes `noreply`-Postfach (z.B. `noreply@eure-firma.de`).
Microsoft Graph wird **nicht** für Mailversand genutzt — bewusste Trennung, damit
ein Graph-Outage den Mailversand nicht mitnimmt.

> Beispiele in diesem Dokument nutzen Platzhalter wie `noreply@eure-firma.de`
> und `afk.eure-firma.de` — durch eure echten Werte ersetzen.

## Warum OAuth2 und nicht App-Password?

Microsoft hat App-Passwörter in M365-Tenants mit **Security Defaults** (heute der
Default für neue Tenants) abgeschaltet. Sie erscheinen im MFA-Setup nur noch wenn
man Security Defaults deaktiviert und Per-User-Legacy-MFA aktiviert — ein
tenantweiter Sicherheits-Downgrade, den wir uns sparen.

Stattdessen authentifiziert die App per OAuth2 (`XOAUTH2`-SMTP-Erweiterung) mit
einem **Refresh-Token** für das `noreply`-Postfach. Der Token wird einmal interaktiv
geholt und danach automatisch erneuert.

## Voraussetzungen

- `noreply@eure-firma.de` ist ein **lizenziertes User-Postfach**, kein Shared Mailbox
  (Shared Mailboxes können keine SMTP-Auth — siehe Punkt unten).
- Entra-ID-App-Registrierung ist fertig — Schritt 5 in
  [`entra-id-setup.md`](entra-id-setup.md) (Delegated Permission `SMTP.Send`
  mit Admin-Konsens, Public-Client-Flows aktiviert).
- SMTP-Auth ist für das `noreply`-Postfach im Admin-Center **nicht explizit
  deaktiviert**. Manche Tenants haben eine Org-weite Policy „Authentifiziertes
  SMTP aus" — bei der Mailbox einzeln einschalten:
  Admin-Center → User → `noreply@eure-firma.de` → **Mail** → **E-Mail-Apps verwalten** → **Authentifiziertes SMTP** = Ein.

## Schritt 1 — `.env` befüllen

```
OAUTH_TENANT_ID=<aus entra-id-setup.md>
OAUTH_CLIENT_ID=<aus entra-id-setup.md>
OAUTH_CLIENT_SECRET=<aus entra-id-setup.md>

SMTP_HOST=smtp.office365.com
SMTP_PORT=587
SMTP_FROM_EMAIL=noreply@eure-firma.de
SMTP_FROM_NAME=afk
```

`OAUTH_CLIENT_SECRET` ist für den SMTP-Setup nicht zwingend nötig (Device-Code-Flow
nutzt nur die Client-ID), aber wird ohnehin für SSO + Graph gebraucht.

## Schritt 2 — Refresh-Token via Device-Code-Flow holen

Auf dem Production-Server (oder lokal mit denselben `.env`-Werten):

```bash
php bin/setup-smtp-oauth.php
```

Das Skript:
1. Holt einen Device-Code von Microsoft
2. Druckt eine URL (https://microsoft.com/devicelogin) und einen 8-Zeichen-Code
3. **Du** öffnest die URL im Browser, gibst den Code ein, loggst dich als
   `noreply@eure-firma.de` ein, klickst „Berechtigungen erteilen" für `SMTP.Send`
4. Skript pollt und speichert den Refresh-Token nach
   `var/secrets/smtp-refresh-token` (chmod 0600)

Beispiel-Ausgabe:

```
Hole Device-Code von Microsoft...

==========================================================
 1. Oeffne im Browser: https://microsoft.com/devicelogin
 2. Gib diesen Code ein: ABCD1234
 3. Logge dich als noreply@eure-firma.de ein
 4. Bestaetige die Berechtigung (SMTP.Send + offline_access)
==========================================================

Warte auf Autorisierung (Timeout 900s)...
.....
Refresh-Token gespeichert nach /pfad/zur/app/var/secrets/smtp-refresh-token
Datei-Permissions: 0600 (nur Owner lesbar)

Setup fertig. Naechster Mail-Send geht ueber XOAUTH2.
```

Danach kann die App Mails versenden — der `SmtpOAuthTokenProvider` zieht den
Refresh-Token aus der Datei, tauscht ihn beim ersten Send gegen einen
Access-Token (~1h gültig), cached den in-memory und refresht bei Bedarf.

## Schritt 3 — Test

```bash
ssh user@afk.eure-firma.de
cd /pfad/zur/app
php -r '
require "vendor/autoload.php";
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
$c = App\App::buildContainer(__DIR__);
$mail = $c->get(App\Services\MailService::class);
// Twig-Template fuer Smoke-Test nutzen — z.B. das vorhandene Decision-Template mit Dummy-Daten
$mail->send(
    "you@eure-firma.de",
    "Smoke-Test afk XOAUTH2",
    "mails/approval-decision.twig",
    [
        "applicant" => ["display_name" => "Test"],
        "absence" => ["startdatum" => "2026-05-12", "enddatum" => "2026-05-12"],
        "decision" => "approved",
        "comment" => "",
    ],
);
echo "OK\n";
'
```

Falls die Mail nicht durchkommt:
- `535 5.7.3 Authentication unsuccessful` → SMTP-Auth bei der Mailbox aus, oder Refresh-Token gehört einem anderen User als `SMTP_FROM_EMAIL`
- `Refresh-Token-Datei ist leer` → Schritt 2 nochmal ausführen
- `SMTP-Token-Refresh fehlgeschlagen … (>90 Tage ohne Nutzung)` → Refresh-Token abgelaufen, Schritt 2 nochmal

## Token-Lifecycle

- **Access-Token:** ~1h gültig, wird automatisch aus dem Refresh-Token geholt und in-memory gecached. Kein Pflegeaufwand.
- **Refresh-Token:** läuft nach **90 Tagen Inaktivität** ab. Bei regelmäßiger App-Nutzung (jeder Mail-Send zählt als Aktivität) wird er rolling erneuert — kein Pflegeaufwand.
- **Bei längerer Pause (>90 Tage):** Token ist tot. App wirft `RuntimeException` beim nächsten Send. Lösung: Schritt 2 erneut.
- **Bei Passwort-Wechsel des `noreply`-Postfachs:** alle Refresh-Tokens werden invalidiert. Schritt 2 erneut.
- **Bei Conditional-Access-Policy-Änderung:** kann den Refresh-Token invalidieren. Schritt 2 erneut.

Praktisch heißt das: ein Mal beim Setup, danach typischerweise nie wieder
anfassen. Wenn der Token doch mal stirbt, ist ein einzelner CLI-Befehl Reparatur.

## Sicherheit

- `var/secrets/` ist in `.gitignore` — Refresh-Token landet **niemals** in git.
- Datei hat `chmod 0600` — nur der Linux-User der die App ausführt kann lesen.
- Wenn der Server compromised wird: Refresh-Token im Admin-Center der App-Registrierung **revoken** (Authentifizierung → **Sitzungen widerrufen** für `noreply@eure-firma.de` im Entra-Admin-Center → User → Sign-in → „Sitzungen widerrufen"). Anschließend Schritt 2 erneut auf einem clean Server.
- Refresh-Token ≠ Passwort: kann gestohlen werden ohne dass das Mailbox-Passwort bekannt wird. Conditional Access mit IP-Restriction für SMTP-Auth wäre die nächste Defense-Stufe.

## Fallback wenn M365-OAuth nicht klappt

Falls `SMTP.Send` im Tenant geblockt ist (z.B. „Modern Auth für SMTP aus") und der
Tenant-Admin das nicht ändern will, bleibt als Fallback ein klassisches SMTP-Auth-Setup
mit beliebigem Provider (eigener Mailserver, Hetzner-Mailbox, SendGrid, etc.):

```
SMTP_HOST=mail.eure-firma.de
SMTP_PORT=587
SMTP_FROM_EMAIL=noreply@eure-firma.de
SMTP_FROM_NAME=afk
```

Für diese Variante müsste `MailService` zurück auf klassische SMTP-Auth
mit Passwort umgestellt werden (z.B. Branch im Code via `SMTP_AUTH_MODE`-env-Var) —
nicht aktuell implementiert, da der M365-Pfad bevorzugt ist. PR willkommen.
