# Google Workspace Setup

Guide for the Workspace admin. Run once before the setup wizard. The end
result: an OAuth client (browser SSO), a service account (Calendar +
Gmail via Domain-Wide Delegation) and a JSON key that you upload in the
wizard.

> Examples use placeholders like `afk.your-company.com` and
> `your-company.com` — replace with your real values.

## Why two auth types?

The app uses two independent Google identity flows:

1. **SSO login (OAuth client)** — the user signs in with their
   Workspace account. Browser redirects to Google, Google returns an
   ID token. That's how the app knows who the user is.
2. **Calendar + Gmail (service account with DWD)** — the app
   authenticates itself (no user) as a service account, impersonates
   the calendar-owner / the `noreply` mailbox via Domain-Wide
   Delegation, and writes events / OOO settings / mail.

Both live in the same GCP project.

## Step 1 — create a GCP project

1. https://console.cloud.google.com → **New project**
2. Name: `neo-afk` (or your choice). Note the project ID.

## Step 2 — enable APIs

In **APIs &amp; Services → Library** enable:

- **Google Calendar API**
- **Gmail API**
- **People API** (for avatar fetch on login)

## Step 3 — OAuth consent screen

In **APIs &amp; Services → OAuth consent screen**:

1. **User type:** Internal (your Workspace org only)
2. **App name:** `neo-afk` (or your choice)
3. **User support email:** your support email
4. **Authorized domains:** `your-company.com`
5. **Developer contact email**

**Scopes (non-sensitive):**
- `.../auth/userinfo.email`
- `.../auth/userinfo.profile`
- `openid`

You do **not** need sensitive scopes for the OAuth client — Calendar
and Mail run through the service account.

## Step 4 — OAuth client (for browser SSO)

In **APIs &amp; Services → Credentials → Create Credentials → OAuth client ID**:

1. **Application type:** Web application
2. **Name:** `neo-afk-web`
3. **Authorized redirect URIs:** `https://afk.your-company.com/auth/callback`
4. Click **Create** — note the **Client ID** and **Client Secret**
   (you enter both in the wizard)

## Step 5 — service account (for Calendar + Gmail)

In **IAM &amp; Admin → Service Accounts → Create Service Account**:

1. **Name:** `neo-afk-server`
2. **Service Account ID:** `neo-afk-server` → becomes
   `neo-afk-server@<project-id>.iam.gserviceaccount.com`
3. No project roles needed (the service account only uses Workspace
   DWD, not GCP IAM)
4. **Create** → done

### Generate the service-account key

1. Click the new service account → tab **Keys** → **Add Key →
   Create new key**
2. **Key type:** JSON
3. **Create** — the browser downloads the `.json` file automatically.
   You upload it in the setup wizard.
4. **Important — note the Client ID:** the unique ID in the detail
   view (numeric string, e.g. `100123456789012345678`). You'll need it
   in step 6.

## Step 6 — enable Domain-Wide Delegation

**In the Workspace Admin Console** (admin.google.com, not GCP):

1. Navigate to: **Security → Access and data control → API controls →
   Domain-wide delegation → Add new**
2. **Client ID:** the numeric ID from step 5 (the service-account
   unique ID)
3. **OAuth scopes** (comma-separated):
   ```
   https://www.googleapis.com/auth/calendar,
   https://www.googleapis.com/auth/gmail.settings.basic,
   https://www.googleapis.com/auth/gmail.send
   ```
4. Click **Authorize**

> **Important:** Workspace Admin Console, not GCP. Without this step
> every API call fails with `unauthorized_client` or `Not authorized to
> access this resource`.

## Step 7 — shared calendar + calendar owner

The app writes events into a shared calendar. Two options:

**Option A: Workspace resource calendar** (recommended)

1. Admin console → **Buildings &amp; Resources → Resources**
2. Create a resource, e.g. `Absences`
3. Note the calendar address (looks like
   `your-company.com_xxxxxxxxxx@resource.calendar.google.com`)

**Option B: user calendar as a resource**

1. Create a Workspace user, e.g. `vacation@your-company.com`
2. From that account, share the calendar with all employees (or via
   group permission)
3. The user email = the calendar ID

**Calendar owner** (impersonation target): a Workspace user whose
account the service account impersonates to write to the calendar. For
option A, any admin user with write access to the resource calendar;
for option B, the resource user itself (`vacation@your-company.com`).

## Step 8 — mail-from mailbox

Create a Workspace user, e.g. `noreply@your-company.com`. Must be a
licensed user mailbox (aliases / groups do not work for Gmail-API send).
No extra configuration needed — the service account impersonates this
user thanks to DWD + the `gmail.send` scope automatically.

## Step 9 — enter values in the setup wizard

| Field in the wizard | Value |
|---|---|
| Identity provider | `google` |
| OAuth Client ID | from step 4 |
| OAuth Client Secret | from step 4 |
| Service-account JSON | JSON file from step 5 (upload) |
| Workspace domain | `your-company.com` |
| Calendar ID | from step 7 |
| Calendar owner | from step 7 |
| HR distribution email | `hr@your-company.com` |
| SMTP From email | `noreply@your-company.com` (from step 8) |

## Verification

After setup:

1. `https://afk.your-company.com/login` → click → Google login → sign
   in with a Workspace account → redirect to `/` with an active user
   session. **SSO works.**
2. Submit a request → approval email arrives → click the magic link →
   approve → open the shared calendar → event visible. **Service-
   account calendar works.**
3. On `unauthorized_client` or `403 Forbidden`: double-check the DWD
   setup in step 6. The client ID must be the **unique ID** of the
   service account, not the OAuth client ID.

## Rotating the service-account key

Recommended: every 12 months.

1. Create a new JSON key in the GCP console (step 5)
2. Run through the setup wizard again OR overwrite
   `var/secrets/google-service-account.json` directly with the new
   JSON (chmod 0600)
3. Delete the old key in the GCP console
