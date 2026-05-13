# Google Workspace Setup

Anleitung fuer den Workspace-Admin. Einmalig vor dem Setup-Wizard durchzufuehren.
Ergebnis: ein OAuth-Client (Browser-SSO), ein Service-Account (Calendar + Gmail
via Domain-Wide Delegation) und ein JSON-Key, den ihr im Wizard hochladet.

> Beispiele nutzen Platzhalter wie `afk.eure-firma.de` und `eure-firma.de` —
> durch eure echten Werte ersetzen.

## Warum zwei Auth-Typen?

Die App nutzt zwei voneinander getrennte Google-Identity-Flows:

1. **SSO-Login (OAuth-Client)** — User loggt sich mit seinem Workspace-Account ein.
   Browser-Redirect zu Google, Google liefert ein ID-Token zurueck. Damit weiss
   die App, wer der User ist.
2. **Calendar + Gmail (Service-Account mit DWD)** — Die App authentifiziert sich
   selbst (ohne User) als Service-Account, impersoniert das Kalender-Owner-
   bzw. das `noreply`-Postfach via Domain-Wide-Delegation und schreibt
   Events / OOO-Settings / Mails.

Beides laeuft im selben GCP-Projekt.

## Schritt 1 — GCP-Projekt anlegen

1. https://console.cloud.google.com → **Neues Projekt**
2. Name: `neo-afk` (oder frei waehlbar). Notiere die Project-ID.

## Schritt 2 — APIs aktivieren

In **APIs &amp; Services → Bibliothek** folgende APIs aktivieren:

- **Google Calendar API**
- **Gmail API**
- **People API** (fuer Avatar-Fetch beim Login)

## Schritt 3 — OAuth-Consent-Screen

In **APIs &amp; Services → OAuth consent screen**:

1. **User Type:** Internal (nur eure Workspace-Org)
2. **App-Name:** `neo-afk` (oder frei waehlbar)
3. **User-Support-Email:** eure Support-Email
4. **Authorized domains:** `eure-firma.de`
5. **Developer contact email**

**Scopes (nicht-sensitive):**
- `.../auth/userinfo.email`
- `.../auth/userinfo.profile`
- `openid`

Sensitive Scopes brauchst du fuer den OAuth-Client **nicht** — Calendar/Mail
laufen alle ueber den Service-Account.

## Schritt 4 — OAuth-Client (fuer Browser-SSO)

In **APIs &amp; Services → Credentials → Create Credentials → OAuth client ID**:

1. **Application type:** Web application
2. **Name:** `neo-afk-web`
3. **Authorized redirect URIs:** `https://afk.eure-firma.de/auth/callback`
4. **Create** klicken — notiere **Client-ID** und **Client-Secret** (im Wizard
   einzugeben)

## Schritt 5 — Service-Account (fuer Calendar + Gmail)

In **IAM &amp; Admin → Service Accounts → Create Service Account**:

1. **Name:** `neo-afk-server`
2. **Service Account ID:** `neo-afk-server` → wird zu
   `neo-afk-server@<projekt-id>.iam.gserviceaccount.com`
3. Keine Projekt-Rollen noetig (Service-Account nutzt nur Workspace-DWD, nicht GCP)
4. **Create** → fertig

### Service-Account-Key erzeugen

1. Auf den angelegten Service-Account klicken → Tab **Keys** → **Add Key →
   Create new key**
2. **Key type:** JSON
3. **Create** — Browser laedt automatisch die `.json`-Datei runter. Diese laedst
   du im Setup-Wizard hoch.
4. **Wichtig — Client-ID notieren:** die Unique-ID im Detail-View (numerischer
   String, z.B. `100123456789012345678`). Brauchst du fuer Schritt 6.

## Schritt 6 — Domain-Wide Delegation aktivieren

**Im Workspace-Admin-Center** (admin.google.com, nicht GCP):

1. Navigation: **Security → Access and data control → API controls →
   Domain-wide delegation → Add new**
2. **Client ID:** die numerische ID aus Schritt 5 (Service-Account-Unique-ID)
3. **OAuth scopes** (komma-separiert):
   ```
   https://www.googleapis.com/auth/calendar,
   https://www.googleapis.com/auth/gmail.settings.basic,
   https://www.googleapis.com/auth/gmail.send
   ```
4. **Authorize** klicken

> **Wichtig:** Workspace-Admin-Center, nicht GCP. Ohne diesen Schritt scheitert
> jeder API-Call mit `unauthorized_client` oder `Not authorized to access this
> resource`.

## Schritt 7 — Geteilter Kalender + Kalender-Owner

Die App schreibt Events in einen geteilten Kalender. Zwei Optionen:

**Variante A: Workspace-Resource-Kalender** (empfohlen)

1. Admin-Center → **Buildings &amp; Resources → Resources**
2. Resource anlegen, z.B. `Abwesenheiten`
3. Kalender-Adresse notieren (sieht aus wie `eure-firma.de_xxxxxxxxxx@resource.calendar.google.com`)

**Variante B: User-Kalender als Resource**

1. Workspace-User anlegen, z.B. `abwesenheit@eure-firma.de`
2. Aus dessen Konto Kalender mit allen MA teilen (oder via Group-Permission)
3. Die User-Email = Kalender-ID

**Kalender-Owner** (Impersonation-Target): ein Workspace-User, dessen Account
der Service-Account impersoniert um den Kalender zu schreiben. Bei Variante A
ein beliebiger Admin-User mit Calendar-Resource-Schreibrechten; bei Variante B
der Resource-User selbst (`abwesenheit@eure-firma.de`).

## Schritt 8 — Mail-From-Postfach

Workspace-User anlegen, z.B. `noreply@eure-firma.de`. Muss ein lizenziertes
User-Postfach sein (Aliase / Groups funktionieren nicht fuer Gmail-API-Send).
Keine zusaetzliche Konfiguration noetig — der Service-Account impersoniert
diesen User dank DWD und `gmail.send`-Scope automatisch.

## Schritt 9 — Werte im Setup-Wizard eintragen

| Feld im Wizard | Wert |
|---|---|
| Identity-Provider | `google` |
| OAuth Client-ID | aus Schritt 4 |
| OAuth Client-Secret | aus Schritt 4 |
| Service-Account-JSON | JSON-Datei aus Schritt 5 hochladen |
| Workspace-Domain | `eure-firma.de` |
| Kalender-ID | aus Schritt 7 |
| Kalender-Owner | aus Schritt 7 |
| HR-Verteiler-Mail | `hr@eure-firma.de` |
| SMTP From-Email | `noreply@eure-firma.de` (aus Schritt 8) |

## Verifikation

Nach Setup:

1. `https://afk.eure-firma.de/login` → Klick → Google-Login → einloggen mit
   Workspace-Account → Redirect auf `/` mit aktivem User-Session.
   **SSO funktioniert.**
2. Antrag stellen → Approval-Mail kommt an → Magic-Link klicken → Approve →
   Geteilten Kalender oeffnen → Event sichtbar. **Service-Account-Calendar
   funktioniert.**
3. Bei `unauthorized_client` oder `403 Forbidden`: DWD-Setup in Schritt 6
   nochmal checken. Client-ID muss die **Unique-ID** des Service-Accounts sein,
   nicht die OAuth-Client-ID.

## Service-Account-Key rotieren

Empfehlung: alle 12 Monate.

1. Neuen JSON-Key in der GCP-Console anlegen (Schritt 5)
2. Im Setup-Wizard erneut durchlaufen ODER `var/secrets/google-service-account.json`
   direkt mit dem neuen JSON ueberschreiben (chmod 0600)
3. Alten Key in der GCP-Console loeschen
