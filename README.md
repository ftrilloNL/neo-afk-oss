# neo:afk

Open-source web app for absence tracking (vacation, sick leave, HR analytics)
with Microsoft 365 or Google Workspace SSO. Built for small companies
(~10–50 employees) that already run M365 or Google Workspace and want a
lightweight, self-hostable tool.

## Features

- **Vacation and sick-leave workflow** with workday calculation including
  public holidays and half-day adjustments
- **SSO** via Entra ID (Microsoft 365) or Google Identity (Workspace) —
  log in with the corporate account
- **Auto calendar events** in the Outlook/Workspace shared calendar
  (approved vacations + sick leaves, privacy-compliant "Out of office"
  label only)
- **Auto out-of-office** at vacation start (per-request text editable;
  cron syncs across multiple future vacations)
- **HR master-data management** + employee pre-create before first SSO +
  pro-rated yearly allowance calculation
- **Audit log** for every write operation
- **Mobile-optimized + PWA** (home-screen icon, standalone mode)
- **Bilingual UI** out of the box (English + German). Adding more
  languages = drop in a `messages.<locale>.po` file, see
  [`docs/translations.md`](docs/translations.md).

## Privacy by default

Internal HR app — the instance should **not** appear in search engines.
Three layers are active out of the box:

- `public/robots.txt` with `Disallow: /` (for well-behaved crawlers)
- `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` header in
  `public/.htaccess` (covers bots that ignore `robots.txt` — Google
  respects the header)
- `<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">`
  in every layout (fallback if the header gets stripped)

If you intentionally want the instance to be indexed, disable all three.

## Stack

PHP 8.2 / Slim 4 / MySQL / HTMX / Tailwind / Twig / Microsoft Graph or
Google Calendar+Gmail APIs / `symfony/translation` for i18n.
~3000 LOC, no heavyweight dependencies. Runs on shared web hosting with
SSH + cron support. No `ext-intl` required.

## Self-host for your org

### 1. Prerequisites

- M365 tenant **or** Google Workspace tenant with admin access (for the
  app registration / service account) — the app uses one of them as IdP;
  pick one in the setup wizard
- A domain + subdomain with HTTPS (e.g. `afk.your-company.com`)
- Shared web hosting with PHP 8.2+, MySQL/MariaDB, SSH access, cron
  support — or equivalent
- For **Microsoft 365**: a shared mailbox for the shared absence
  calendar (e.g. `vacation@your-company.com`) + a licensed mailbox for
  mail delivery (e.g. `noreply@your-company.com`)
- For **Google Workspace**: a shared Workspace calendar + an account
  the service account can impersonate for calendar writes and mail
  delivery

### 2. Set up the repo

```bash
git clone https://github.com/ftrilloNL/neo-afk-oss.git
cd neo-afk-oss
composer install --no-dev --optimize-autoloader
npm install
npm run build:css
```

### 3. Provider setup

Pick **one** of:

- **Microsoft 365**: see [`docs/entra-id-setup.md`](docs/entra-id-setup.md)
  for the Entra ID app registration. Short version: web platform with
  redirect URI `https://<your-subdomain>/auth/callback`; delegated
  `openid profile email offline_access User.Read`; application (admin
  consent) `Calendars.ReadWrite` + `MailboxSettings.ReadWrite`; Office
  365 Exchange Online delegated `SMTP.Send`; "Allow public client flows"
  set to **Yes**. Note Client ID, Tenant ID, Client Secret.
- **Google Workspace**: see
  [`docs/google-workspace-setup.md`](docs/google-workspace-setup.md) for
  the OAuth client + service account + Domain-Wide Delegation flow.
  You'll need the OAuth Client ID/Secret, a service-account JSON key,
  the Workspace domain, the calendar ID and an account the service
  account can impersonate.

### 4. SMTP OAuth token (Microsoft 365 only)

For the Microsoft path, fetch the SMTP refresh token. Details in
[`docs/smtp-setup.md`](docs/smtp-setup.md):

```bash
# Locally or directly on the server, with a working .env:
php bin/setup-smtp-oauth.php
```

The script walks you through the device-code flow: enter the code in
your browser → log in as `noreply@…` → refresh token lands in
`var/secrets/smtp-refresh-token`.

For the Google path, mail delivery reuses the service account — no
extra token needed.

### 5. Minimal `.env` for the wizard

Copy `.env.example` to `.env`. Only three values must be set before the
wizard starts:

```dotenv
APP_ENV=production
APP_URL=https://afk.your-company.com
APP_SECRET=<32 random bytes, e.g. `openssl rand -hex 32`>
SETUP_MODE=true
```

Everything else (DB credentials, org branding, M365/Google values, SMTP,
HR distribution email, public-holidays region) is collected by the
browser wizard in the next step.

**Write access:** the webserver user needs write access to `.env` and
`var/secrets/` so the wizard can persist values:

```bash
chmod 660 .env
chown www-data:www-data .env var/secrets/
```

### 6. Browser wizard

Open `https://afk.your-company.com/setup` and follow the wizard. It
handles:

- DB connection test + applying `migrations/schema.sql`
- Loading the public-holidays seed for your region
- Org branding, SSO + integration values, SMTP setup (incl. refresh
  token via device-code flow in the browser for Microsoft) and the HR
  distribution email
- Pre-creating the first HR admin
- Writing `.env` and flipping `SETUP_MODE` to `false`

The wizard is only reachable while `SETUP_MODE=true` in `.env`
(default in `.env.example`) and the setup-completed marker file doesn't
exist yet (`var/secrets/setup-completed`).

**Wizard locale:** the wizard picks up the browser `Accept-Language`
header (English or German out of the box). If you want a specific
locale forced regardless of browser, set `DEFAULT_LOCALE=de` or `en` in
`.env`.

**Alternative CLI path:** apply the SQL files manually + edit `.env`
manually. See [`migrations/README.md`](migrations/README.md).

### 7. Deploy

There's no pre-built deploy workflow in the repo — hosting setups vary
too much. Pattern for shared hosting with SSH:

```bash
rsync -avz --delete \
  --exclude='.git' --exclude='.env' --exclude='node_modules' \
  --exclude='var/' --exclude='tests/' --exclude='.idea' \
  ./ user@host:/path/to/app/
```

The web root must point at `/public/` (not the repo root — standard
Slim layout).

### 8. Cron jobs

```
0 2 * * *   php /path/to/app/cron/cleanup-tokens.php
0 6 * * *   php /path/to/app/cron/ooo-sync.php
0 9 * * *   php /path/to/app/cron/reminders.php
0 2 1 1 *   php /path/to/app/cron/jahreswechsel.php
0 2 1 4 *   php /path/to/app/cron/verfall.php
```

### 9. First login

After the wizard, go to `/login` and sign in with the HR account you
created. On the first SSO round-trip the `entra_oid` (Microsoft) or
`google_sub` (Google) is matched in via email.

**Replace the logo (optional):** the bundled `public/assets/logo.svg`
is a placeholder (4 black squares). Overwrite with your own logo or
point `ORG_LOGO_URL` at a different path. Regenerate the PNG icons
with ImageMagick:

```bash
for size in 180 192 512; do
  inner=$((size * 65 / 100))
  magick -density 1000 -background white public/assets/logo.svg \
    -resize ${inner}x${inner} \
    -gravity center -background white -extent ${size}x${size} \
    -flatten public/assets/icon-${size}.png
done
```

## Architecture documentation

Per subsystem under `docs/architecture/`:

- `overview.md` — stack, components, external integrations
- `auth-and-integrations.md` — SSO, JWKS, CSRF, SMTP OAuth, Microsoft
  Graph, Google APIs
- `absence-workflow.md` — vacation/sick-leave lifecycle, transactional
  approve, cancellation
- `hr-and-audit.md` — master-data management, audit log
- `data-model.md` — schema + migrations
- `tensions.md` — known inconsistencies and deliberate simplifications

Translation workflow + how to add a new language:
[`docs/translations.md`](docs/translations.md).

## Local development

1. **Requirements:** PHP 8.2+, Composer, Node.js, MySQL/MariaDB
2. **Local DB:** Docker container or local MariaDB; apply
   `migrations/schema.sql` + a public-holidays seed
3. **Dependencies:**
   `composer install && npm install && npm run build:css`
4. **`.env`** with `APP_ENV=development` — emails land as HTML files in
   `var/mails/`, calendar/Graph calls in `var/logs/calendar.log` (dev
   fallbacks)
5. **Start:** `php -S localhost:8080 -t public/`

## License

MIT (see [`LICENSE`](LICENSE)).

## Contributing

Pull requests welcome for:

- Additional regional public-holiday seeds (files in
  `migrations/seeds/`)
- Additional UI languages — drop in a `translations/messages.<locale>.po`
  file, run `composer i18n:extract` to find missing keys (see
  [`docs/translations.md`](docs/translations.md))
- Other country-specific conventions (date formats, workday rules)
- Bug fixes + test coverage

Discuss bigger architecture changes via an issue first.
