# neo:afk

Open-Source-Web-App für Abwesenheits-Tracking (Urlaub, Krankmeldung, HR-Auswertung)
mit Microsoft-365-SSO-Integration. Gebaut für kleine Unternehmen (~10–50 MAs),
die ihren M365-Tenant bereits nutzen und ein leichtgewichtiges,
self-hostbares Tool wollen.

## Was es kann

- **Urlaubs- und Krankmeldungs-Workflow** mit Werktage-Berechnung inkl. Feiertage und Halbtages-Korrektur
- **Microsoft 365 SSO** via Entra ID — Login mit dem Firmen-Account
- **Auto-Calendar-Events** im Outlook-Kalender einer Shared Mailbox (genehmigte Urlaube + Krankmeldungen, DSGVO-konform „Abwesend"-Label)
- **Auto-Out-of-Office** beim Urlaubsstart (Texte pro Antrag editierbar, Cron-Sync zwischen mehreren zukünftigen Urlauben)
- **HR-Stammdaten-Pflege** + Mitarbeiter-Pre-Create vor erstem SSO + anteilige Berechnung des Jahresanspruchs
- **Audit-Log** für alle Schreib-Operationen
- **Mobile-optimiert + PWA** (Home-Screen-Icon, Standalone-Mode)

## Privacy by default

Interne HR-App — die Instanz soll **nicht** in Suchmaschinen auftauchen.
Drei Layers sind ab Werk aktiv:

- `public/robots.txt` mit `Disallow: /` (für brave Crawler)
- `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` Header in
  `public/.htaccess` (gilt auch für Bots, die `robots.txt` ignorieren —
  Google respektiert den Header)
- `<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">`
  in allen Layouts (Fallback wenn der Header weggestrippt wird)

Wenn ihr die Instanz absichtlich öffentlich indexieren wollt: alle drei
ausschalten.

## Stack

PHP 8.2 / Slim 4 / MySQL / HTMX / Tailwind / Twig / Microsoft Graph + SMTP-OAuth2.
~3000 LOC, keine schwergewichtigen Dependencies. Lauffähig auf Shared-Webhosting
mit SSH + Cron-Support.

## Self-Host für eure Org

### 1. Voraussetzungen

- M365-Tenant mit Admin-Zugriff (für die App-Registrierung) — die App nutzt M365 als IdP, ohne M365 funktioniert nichts
- Eine Domain + Subdomain mit HTTPS (z.B. `afk.eure-firma.de`)
- Shared Webhosting mit PHP 8.2+, MySQL/MariaDB, SSH-Zugang, Cron-Support — oder vergleichbares
- Eine Shared Mailbox in M365 für den geteilten Abwesenheits-Kalender (z.B. `urlaub@eure-firma.de`)
- Ein lizenziertes Postfach für den Mail-Versand (z.B. `noreply@eure-firma.de`)

### 2. Repo aufsetzen

```bash
git clone https://github.com/ftrilloNL/neo-afk-oss.git
cd neo-afk-oss
composer install --no-dev --optimize-autoloader
npm install
npm run build:css
```

### 3. Microsoft-Setup

Detaillierte Schritte in [`docs/entra-id-setup.md`](docs/entra-id-setup.md). Knapp:

1. App-Registrierung in Entra ID anlegen
2. Web-Plattform: Redirect-URI `https://eure-subdomain/auth/callback`
3. Delegated Permissions: `openid profile email offline_access User.Read`
4. Application Permissions (Admin-Konsens): `Calendars.ReadWrite` + `MailboxSettings.ReadWrite`
5. Office 365 Exchange Online → Delegated: `SMTP.Send`
6. Authentifizierung → „Öffentliche Clientflüsse zulassen" auf **Ja**
7. Notiere `Client-ID`, `Tenant-ID`, `Client-Secret`

### 4. SMTP-OAuth-Token holen

Detaillierte Schritte in [`docs/smtp-setup.md`](docs/smtp-setup.md). Knapp:

```bash
# Lokal oder direkt auf dem Server, mit korrekter .env:
php bin/setup-smtp-oauth.php
```

Skript führt durch den Device-Code-Flow: Code im Browser eingeben → als
`noreply@…` einloggen → Refresh-Token landet in `var/secrets/smtp-refresh-token`.

### 5. Minimal-`.env` für den Wizard-Start

`.env.example` nach `.env` kopieren. Nur drei Werte müssen vor dem Wizard-Start
gesetzt sein:

```dotenv
APP_ENV=production
APP_URL=https://afk.eure-firma.de
APP_SECRET=<32 zufaellige Bytes, z.B. `openssl rand -hex 32`>
SETUP_MODE=true
```

Alles weitere (DB-Credentials, Org-Branding, M365-Werte, SMTP, HR-Verteiler,
Feiertage-Bundesland) sammelt der Browser-Wizard im nächsten Schritt ein.

**Schreibrechte:** der Webserver-User braucht Schreibrechte auf `.env` und
auf `var/secrets/`, damit der Wizard Werte persistieren kann:

```bash
chmod 660 .env
chown www-data:www-data .env var/secrets/
```

### 6. Browser-Wizard

Öffne `https://afk.eure-firma.de/setup` und folge dem Wizard. Er erledigt:

- DB-Connection-Test + `migrations/schema.sql` einspielen
- Feiertage-Seed für euer Bundesland laden
- Org-Branding, M365-OAuth-Werte, SMTP-Setup (inkl. Refresh-Token via
  Device-Code-Flow im Browser) und HR-Verteiler-Mail einsammeln
- Erste:n HR-Admin pre-createn
- `.env` schreiben und `SETUP_MODE` auf `false` setzen

Der Wizard ist nur erreichbar wenn `SETUP_MODE=true` in der `.env` ist
(default in `.env.example`) und noch kein Setup gelaufen ist
(`var/secrets/setup-completed` existiert nicht).

**Alternativer Pfad (CLI):** SQL-Files manuell einspielen + `.env` manuell
pflegen. Siehe [`migrations/README.md`](migrations/README.md).

### 7. Deploy

Kein vorgefertigter Deploy-Workflow im Repo — Hosting-Setups variieren stark.
Pattern für Shared-Hosting mit SSH:

```bash
rsync -avz --delete \
  --exclude='.git' --exclude='.env' --exclude='node_modules' \
  --exclude='var/' --exclude='tests/' --exclude='.idea' \
  ./ user@host:/path/to/app/
```

Web-Root muss auf `/public/` zeigen (nicht auf das Repo-Root — Slim-Standard).

### 8. Cron-Jobs

```
0 2 * * *   php /pfad/zur/app/cron/cleanup-tokens.php
0 6 * * *   php /pfad/zur/app/cron/ooo-sync.php
0 9 * * *   php /pfad/zur/app/cron/reminders.php
0 2 1 1 *   php /pfad/zur/app/cron/jahreswechsel.php
0 2 1 4 *   php /pfad/zur/app/cron/verfall.php
```

### 9. Erster Login

Nach dem Wizard auf `/login` → mit dem im Wizard angelegten HR-Account
einloggen. Beim ersten SSO-Roundtrip wird die `entra_oid` via Email-Match
ergänzt.

**Logo ersetzen (optional):** das mitgelieferte `public/assets/logo.svg` ist
ein Platzhalter (4 schwarze Quadrate). Mit eigenem Logo überschreiben oder
`ORG_LOGO_URL` auf einen anderen Pfad zeigen lassen. PNG-Icons regenerieren
mit ImageMagick:

```bash
for size in 180 192 512; do
  inner=$((size * 65 / 100))
  magick -density 1000 -background white public/assets/logo.svg \
    -resize ${inner}x${inner} \
    -gravity center -background white -extent ${size}x${size} \
    -flatten public/assets/icon-${size}.png
done
```

## Architektur-Dokumentation

Per-Subsystem in `docs/architecture/`:

- `overview.md` — Stack, Komponenten, externe Integrationen
- `auth-and-integrations.md` — SSO, JWKS, CSRF, SMTP-OAuth, Microsoft Graph
- `absence-workflow.md` — Antrag/Krank-Lifecycle, transaktionaler Approve, Storno
- `hr-and-audit.md` — Stammdaten-Pflege, Audit-Log
- `data-model.md` — Schema + Migrations
- `tensions.md` — bekannte Inkonsistenzen und bewusste Vereinfachungen

## Lokales Setup (Development)

1. **Voraussetzungen:** PHP 8.2+, Composer, Node.js, MySQL/MariaDB
2. **DB lokal:** Docker-Container oder lokale MariaDB, `migrations/schema.sql` + Feiertage-Seed einspielen
3. **Dependencies:** `composer install && npm install && npm run build:css`
4. **`.env`** mit `APP_ENV=development` — Mails landen als HTML-File in `var/mails/`, Graph-Calls in `var/logs/graph.log` (Dev-Fallbacks)
5. **Start:** `php -S localhost:8080 -t public/`

## Lizenz

MIT (siehe [`LICENSE`](LICENSE)).

## Contributing

Pull-Requests willkommen für:

- Zusätzliche Bundesländer-Feiertage (Seed-Files in `migrations/seeds/`)
- Weitere Sprachen (aktuell hardcoded deutsch — i18n nicht eingeführt)
- Andere Country-Anpassungen (Datums-Formate, Werktage-Konventionen)
- Bug-Fixes + Test-Coverage

Größere Architektur-Änderungen vorher als Issue diskutieren.

