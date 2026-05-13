# Overview

High-level Komponenten + Request-Lifecycle der neo-afk-App.

## Stack

| Schicht | Technologie | Begründung |
|---|---|---|
| Backend | PHP 8.2 + Slim 4 | Lauffähig auf Shared-Webhosting (kein Bun/Node/Docker nötig) |
| Datenbank | MySQL (MariaDB-kompatibel) via Doctrine DBAL Query-Builder | Vom Hosting bereitgestellt, kein ORM-Overkill |
| Templates | Twig | Auto-Escaping per Default, klare Trennung Logik/View |
| Frontend-Interaktivität | HTMX via CDN, plus winziges self-hosted JS für Mobile-Sidebar | Keine Build-Pipeline für JS — minimaler footprint |
| Styling | Tailwind + DaisyUI, precompiled | Build-Step nur für CSS (`npm run build:css`), Output unter `public/assets/app.css` |
| Auth | `league/oauth2-client` GenericProvider für Microsoft Entra ID | Kein dediziertes Microsoft-Provider-Package weil deren transitive `firebase/php-jwt`-Dep eine Security-Geschichte hatte |
| ID-Token-Verifikation | `src/Auth/JwksVerifier.php` — manuelle RS256-Prüfung gegen Tenant-JWKS, ASN.1-DER-Encoding für PEM-Konstruktion | Kein `firebase/php-jwt`-Dep (siehe oben) |
| HTTP-Client | GuzzleHTTP (transitiv aus oauth2-client) | für Graph + JWKS |
| Mail | PHPMailer mit XOAUTH2 gegen Office365 SMTP | App-Passwörter sind in M365-Tenants mit Security Defaults deaktiviert; XOAUTH2 mit Refresh-Token ist der einzige saubere Pfad |
| Microsoft Graph | direkt via Guzzle (keine SDK), Client-Credentials-Flow für Application-Permission-Endpoints | minimal-invasiv |
| DI | PHP-DI 7, public-static `App::buildContainer($rootPath)` damit auch Cron-Skripte den Container bekommen | Auto-Wiring für Controller, explizite Factories für Services/Repos |

## Verzeichnis-Map

| Pfad | Zweck |
|---|---|
| `src/App.php` | Slim-Setup, Route-Definitionen, DI-Container-Construction |
| `src/Config.php` | Sehr dünner Env-Wrapper mit `get()` + `isProduction()` |
| `src/Auth/` | OAuth + JWKS |
| `src/Database/Connection.php` | DBAL-Wrapper, Singleton via DI |
| `src/Models/*Repository.php` | Pro Tabelle eine Repo-Klasse, fetch+update-Operationen |
| `src/Services/` | Domain-Services (Approval, Resturlaub, Werktage, Mail, GraphClient, SmtpOAuthTokenProvider, Csrf, AvatarService) |
| `src/Controllers/` | HTTP-Handler pro Route-Gruppe |
| `src/Middleware/` | Auth-, HR-, CSRF-Middleware |
| `src/Templates/` | Twig-Templates, organisiert nach Feature |
| `cron/` | Vier Cron-Skripte mit gemeinsamem `bootstrap.php` |
| `bin/setup-smtp-oauth.php` | Einmal-CLI für initialen Refresh-Token via Device-Code-Flow |
| `migrations/` | Sequenziell nummerierte SQL-Files, manuell eingespielt (nicht automatisiert) |
| `public/` | Web-Root: `index.php`, `.htaccess`, Assets, Mobile-JS, Symbol-SVG |

## Externe Integrationen

| Integration | Wofür | Auth-Modus | Permission |
|---|---|---|---|
| **Entra ID (Microsoft Identity)** | SSO-Login | Authorization-Code-Flow, Confidential Client (Web-Plattform mit Secret) | Delegated: `openid profile email offline_access User.Read` |
| **Entra ID — Device-Code** | initialer SMTP-Refresh-Token via `bin/setup-smtp-oauth.php` | Device-Code-Flow, Public Client (Mobile/Desktop-Plattform, kein Secret) | Delegated: `SMTP.Send offline_access` |
| **Office365 SMTP** (`smtp.office365.com:587`) | Versand aller App-Mails (Approval-Request, Decision, Reminder, Krank-Notif, Storno-Confirmation) | XOAUTH2 mit Access-Token aus Refresh-Token-Exchange | impliziert durch SMTP.Send |
| **Microsoft Graph — /users/{id}/calendar/events** | Anlegen + Löschen von Kalender-Events im Shared-Mailbox-Kalender (Mailbox aus `GRAPH_CALENDAR_USER`) | App-only via Client-Credentials | Application: `Calendars.ReadWrite` |
| **Microsoft Graph — /users/{id}/mailboxSettings** | Setzen + Zurücksetzen der Auto-Reply (Out-of-Office) am Mailbox des Antragstellers | App-only via Client-Credentials | Application: `MailboxSettings.ReadWrite` |
| **Microsoft Graph — /me/photo/$value** | Avatar-Sync beim SSO-Callback | Delegated (User-Access-Token aus dem gerade-erfolgten Auth-Code-Exchange) | Delegated: `User.Read` (haben wir eh) |

Tenant-Setup-Detailansicht: `docs/entra-id-setup.md`. SMTP-Refresh-Token-Lifecycle und Setup: `docs/smtp-setup.md`.

## Request-Lifecycle

Ein typischer GET (z.B. `/profil`):

1. Apache routet `<eure-host>/*` über `.htaccess` auf `public/index.php` (Slim Front-Controller).
2. `public/index.php` ruft `App::create($rootPath)`. Das macht in dieser Reihenfolge:
   - `Dotenv` lädt `.env` (safeLoad, immutable — bereits gesetzte ENV-Vars werden nicht überschrieben).
   - Session-Cookie-Setup (secure/httponly/samesite, secure nur in production).
   - DI-Container über `buildContainer()`.
   - Slim-App mit Container + TwigMiddleware + RoutingMiddleware + BodyParsingMiddleware.
3. Route-Match: `GET /profil` → `ProfilController::index` mit `AuthMiddleware` davor.
4. `AuthMiddleware` prüft `$_SESSION['user_id']`. Falls leer → 302 nach `/login`.
5. Controller lädt User aus `UserRepository::findById` (inkl. computed `has_avatar` via Filesystem-Check), rendert Template.

Ein typischer POST mit CSRF (z.B. `/antrag`):

1. Wie oben bis Routing.
2. Stack: `AuthMiddleware` → `CsrfMiddleware` → Controller. Reihenfolge im Code: `->add(CsrfMiddleware::class)->add(AuthMiddleware::class)` — Slim führt Middleware in **umgekehrter** Hinzufügungsreihenfolge aus, deshalb Auth zuerst.
3. `CsrfMiddleware::process` (`src/Middleware/CsrfMiddleware.php`) liest `_csrf` aus `getParsedBody()`, vergleicht via `hash_equals` mit `$_SESSION['csrf_token']`. Bei Mismatch → 403 mit Klartext-Response (keine Slim-Error-Seite, kein Stack-Trace).
4. Controller verarbeitet Body, schreibt in DB, redirect oder Render.

Ein Cron-Skript (z.B. `cron/reminders.php`):

1. Cron-Daemon ruft typischerweise mit `flock`-Wrap auf, Interpreter ist explizit PHP 8.2.
2. `cron/bootstrap.php` lädt `.env`, baut Container, exposed ihn als `$GLOBALS['cron_container']`. Kein Slim, keine Session.
3. Skript zieht Services + Repos aus dem Container, macht seine Arbeit, logged über `cron_log()` nach `var/logs/cron-<name>.log`.

## CSP-Header

Aktiv via `public/.htaccess`:

```
Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' https://unpkg.com; img-src 'self' data:;
```

- `script-src 'self' https://unpkg.com` — eigenes JS plus HTMX-CDN
- `style-src 'self' 'unsafe-inline'` — `unsafe-inline` weil Tailwind-Generated-CSS + Inline-Attribute mit Tailwind-Klassen
- `img-src 'self' data:` — eigene Assets + `data:`-URIs (z.B. SVG-Sparks)

Plus `X-Frame-Options DENY`, `X-Content-Type-Options nosniff`, `Referrer-Policy strict-origin-when-cross-origin`, `Strict-Transport-Security` mit langer max-age.

## Tensions

- **Cron-`bootstrap.php` exposed Container als `$GLOBALS['cron_container']`** (`cron/bootstrap.php`) — pragmatisch für 4 Skripte, aber Anti-Pattern für eine wachsende Cron-Suite. Würde bei mehr Skripten lieber via DI-aware-Helper laufen.
- **Mail-Templates nutzen Inline-Styles, nicht Tailwind** — bewusst weil Mail-Clients Tailwind/Stylesheets nicht zuverlässig rendern. Konvention nicht dokumentiert beim Hinzufügen neuer Mail-Templates.
- **Twig-`<details>` und HTMX vermischen** — `src/Templates/hr/audit/index.twig` nutzt `<details>` für Payload-Expand; HTMX nicht. Inkonsistent: andere Stellen würden für Toggle HTMX nutzen.
- **`Config::get` wirft `RuntimeException` bei missing Env ohne Default** (`src/Config.php:11`) — geht ungebremst durch bis zum Slim-Error-Handler. Production-Modus zeigt deshalb Slim's generische 500-Seite, der echte Grund nur im `error.log`.
