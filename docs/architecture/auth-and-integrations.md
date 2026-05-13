# Auth + Microsoft-Integrationen

Drei voneinander unabhängige Microsoft-Identity-Flows in der App, plus
CSRF-Schutz auf der Session-Schicht.

## SSO-Login (Authorization-Code-Flow)

Klassischer OAuth2-Code-Flow gegen Entra ID. Setup-Schritte in
`docs/entra-id-setup.md` (Tenant-Admin-Aufgaben).

**Endpoint-Konfiguration in `src/Auth/MicrosoftOAuth.php:18-32`:**
- `urlAuthorize`: `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize`
- `urlAccessToken`: `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`
- Scopes: `openid profile email offline_access User.Read` (Delegated)

`AuthController::login` (`src/Controllers/AuthController.php:18`) generiert `state`
(16 Hex-Bytes), legt in Session, redirected via `$this->oauth->authorizationUrl($state)`.

Wichtige Erweiterung: `authorizationUrl` schickt `prompt=select_account` mit
(`src/Auth/MicrosoftOAuth.php:36-43`). Heißt: User mit mehreren M365-Sessions im
Browser bekommen den Account-Picker statt automatischer Übernahme des aktiven Accounts.

**Callback** (`src/Controllers/AuthController.php:26`):
1. State-Check (Mismatch → Redirect mit `auth_error=state_mismatch`)
2. `MicrosoftOAuth::exchangeCode($code)` tauscht Code gegen Access+ID-Token.
3. ID-Token wird durch `JwksVerifier::verify` (siehe unten) verifiziert; gibt User-Claims (`oid`, `email`, `name`) zurück plus den `access_token` für Avatar-Fetch.
4. `upsertUser($userInfo)` (`src/Controllers/AuthController.php:86`) — siehe unten.
5. Best-effort `AvatarService::fetchAndStore` (Graph `/me/photo/$value` mit User-Access-Token).
6. `session_regenerate_id(true)`, Session-User-ID gesetzt, Redirect auf `/`.

### `upsertUser` mit Email-Fallback

Drei-stufige Suche (`src/Controllers/AuthController.php:90-120`):

1. **`entra_oid`-Match** — bestehender, vollständig verlinkter User. Update Email + Display-Name (falls in MS geändert).
2. **Pre-Created-Match** — wenn HR den User vorab unter `/hr/users/new` angelegt hatte: `entra_oid IS NULL AND LOWER(email) = LOWER(?)`. Trifft, dann wird `entra_oid` ergänzt → User ist jetzt verlinkt.
3. **Komplett neu** — Bootstrap-Logik: der allererste User in der DB wird automatisch HR + Genehmiger gesetzt. Sonst Standard-Defaults aus Schema.

Pre-Create-Flow setzt voraus dass `entra_oid` in der DB nullable ist — siehe Migration 003 in `data-model.md`.

### ID-Token-Verifikation (JwksVerifier)

Vollständig in `src/Auth/JwksVerifier.php`. Hash-Algorithmus RS256, JWKS-Endpoint
`https://login.microsoftonline.com/{tenant}/discovery/v2.0/keys`.

**Schritte in `JwksVerifier::verify`** (`src/Auth/JwksVerifier.php:25`):
1. JWT in drei Base64Url-Teile splitten (Header, Payload, Signature).
2. Header parsen → `alg=RS256`, `kid` extrahieren.
3. JWKS fetchen (lazy, in-memory pro Request gecached). Falls `kid` nicht gefunden: nochmal frisch holen — Microsoft rotiert Keys gelegentlich.
4. JWK → PEM via manuellem ASN.1-DER-Encoding (`jwkToPem`, `src/Auth/JwksVerifier.php:130`). Schreibt RSA-Public-Key in der Form: SubjectPublicKeyInfo → SEQUENCE(AlgorithmIdentifier rsaEncryption, BIT STRING(SEQUENCE(modulus INTEGER, exponent INTEGER))).
5. `openssl_verify(SigningInput, Signature, PEM, OPENSSL_ALGO_SHA256)` — muss 1 zurückgeben.
6. Claims: `iss == https://login.microsoftonline.com/{tenant}/v2.0`, `aud == OAUTH_CLIENT_ID`, `exp > now` (mit 60s Skew-Toleranz), `nbf <= now` (auch mit Skew).

Wirft `\RuntimeException` bei jedem Fehler — der Aufrufer (AuthController) fängt das und redirected mit `auth_error=token_exchange_failed`.

Bewusst kein `firebase/php-jwt`-Dep — siehe `overview.md` § Stack zur Begründung.

## CSRF

Synchronizer-Token-Pattern. Token einmal pro Session in `$_SESSION['csrf_token']`
(64 Hex-Chars, `bin2hex(random_bytes(32))`), keine Rotation pro Request.

**Generation + Validierung** in `src/Services/Csrf.php`:
- `token()` lazy-erstellt + cached.
- `validate($submitted)` nutzt `hash_equals` (constant-time).

**Middleware** in `src/Middleware/CsrfMiddleware.php`:
- Greift für POST/PUT/PATCH/DELETE.
- Liest `_csrf` aus `getParsedBody()`.
- Mismatch → 403 mit Klartext-Response (kein Slim-Stack-Trace).

**Twig-Function `csrf_field()`** in `src/App.php` registriert. Rendert hidden input.

**Routen mit CSRF** (Liste in `src/App.php:65-93`):
- `/logout`, `/antrag`, `/antrag/{id}/storno`, `/krank`, `/genehmigungen/{id}/approve`, `/genehmigungen/{id}/reject`, `/hr/users`, `/hr/users/{id}`

**Ohne CSRF (bewusst)**:
- `/approval/{token}` — Magic-Link aus Mail. URL-Token ist 64 hex Chars, single-use, an genau die Approver-E-Mail geschickt. CSRF wäre redundant; der User hat zudem keine vorhandene Session.

## SMTP via XOAUTH2

Microsoft hat App-Passwörter in Tenants mit Security Defaults abgeschaltet —
XOAUTH2 ist der einzige saubere Pfad für Server-side Mail-Versand über M365.
Setup-Walkthrough in `docs/smtp-setup.md`.

**Komponenten:**
- `bin/setup-smtp-oauth.php` — Einmal-CLI mit Device-Code-Flow. Schreibt Refresh-Token nach `var/secrets/smtp-refresh-token` (chmod 0600).
- `src/Services/SmtpOAuthTokenProvider.php` — implementiert `PHPMailer\PHPMailer\OAuthTokenProvider`. Tauscht Refresh-Token gegen Access-Token (in-memory cached + bei Rolling-Rotation persistiert), liefert XOAUTH2-Auth-String via `getOauth64()`.
- `src/Services/MailService.php` — wickelt PHPMailer mit `AuthType = XOAUTH2`.

**Public-Client-Mode kritisch:** das Refresh-Token wurde via Device-Code-Flow geholt, was die App **temporär** als Public Client behandelt (Mobile/Desktop-Plattform in der Entra-App-Registrierung). Der Refresh-Token-Exchange (`SmtpOAuthTokenProvider::getAccessToken`, `src/Services/SmtpOAuthTokenProvider.php:35`) sendet deshalb **keinen** `client_secret` — sonst lehnt Microsoft mit `AADSTS700025: Client is public…` ab. Trotzdem hat die App-Registrierung gleichzeitig die Web-Plattform mit Secret (für SSO). Microsoft unterscheidet anhand der Request-Parameter.

**Token-Lifecycle:**
- Access-Token ~1h gültig, automatisch refreshed.
- Refresh-Token läuft nach 90 Tagen Inaktivität ab. Bei regelmäßigem Mail-Versand rolling renewed. Bei Konflikt: `bin/setup-smtp-oauth.php` erneut ausführen.

**Multiple Recipients:** `MailService::send` akzeptiert seit kurzem Komma- oder Semikolon-separierte Adressen im `$to`-Parameter (`src/Services/MailService.php:60-77`). Wird für `HR_NOTIFICATION_EMAIL` genutzt (Krank-Notification kann an mehrere HR-Verantwortliche gehen).

## Microsoft Graph

Drei Endpoint-Familien, alle in `src/Services/GraphClient.php`:

### Client-Credentials-Flow für App-only

`GraphClient::getAppToken` (`src/Services/GraphClient.php:113`) — POST gegen
`/oauth2/v2.0/token` mit `grant_type=client_credentials` und `scope=https://graph.microsoft.com/.default`.

In-memory gecached. Wird für alle App-only-Calls genutzt: Calendar-CRUD,
MailboxSettings für Auto-OOO.

### Calendar (Shared Mailbox)

Endpoint: `/users/{GRAPH_CALENDAR_USER}/calendar/events`. `GRAPH_CALENDAR_USER` ist
typischerweise eine Shared Mailbox mit Kalender (z.B. `urlaub@eure-firma.de`).
Bewusste Entscheidung über M365-Group-Calendar — siehe `tensions.md`.

- `createCalendarEvent($subject, $start, $end, $isAllDay)` → gibt Event-ID zurück, wird in `absences.kalender_event_id` persistiert.
- `deleteCalendarEvent($eventId)` → idempotent: 404 wird silent als Erfolg behandelt (`src/Services/GraphClient.php:79-100`). STUB-IDs aus Dev-Mode werden gar nicht erst an Graph weitergeleitet.

Event-Subject:
- Urlaub: `[URLAUB] {Display-Name}`
- Krank: `Abwesend – {Display-Name}` — **DSGVO Art. 9**: im Kalender steht nur „Abwesend", die Tatsache der Krankheit wird nicht im geteilten Kalender exposed.

### Auto-OOO (Out-of-Office)

Endpoint: `PATCH /users/{user-mailbox}/mailboxSettings`. Zwei Methoden in
`src/Services/GraphClient.php:108-180`:

- `setAutoReply($mailbox, $start, $end, $internalHtml, $externalHtml)` — setzt `status=scheduled` mit Zeitraum und zwei separaten Reply-Bodies (intern + extern). Microsoft schaltet zum Start-Zeitpunkt selbst auf `alwaysEnabled`.
- `clearAutoReplyIfOurs($mailbox)` — **GET** des aktuellen OOO-Settings, prüft ob unser **Marker** (`<!-- neo-afk:auto-ooo -->`) im Internal-Reply-Text steht. Nur wenn ja: `status=disabled`. Sonst lassen — User hat zwischenzeitlich manuell überschrieben, das anzutasten wäre falsch.

Marker ist HTML-Kommentar im Mail-Body, unsichtbar für Mail-Empfänger aber stabil im Source erkennbar.

**Multi-Urlaub-Konflikt-Vermeidung:** Microsoft erlaubt nur einen scheduled-Zeitraum
pro Mailbox. Mehrere zukünftige approved-Urlaube würden sich gegenseitig überschreiben.
Lösung: `setAutoReply` wird **nicht** beim Approve aufgerufen wenn der Antrag in
der Zukunft startet (`src/Services/ApprovalService.php:160`); stattdessen läuft
täglich `cron/ooo-sync.php` und aktiviert OOO am Urlaubs-Start-Tag aus den in der
DB persistierten Texten. Krankmeldungen sind hiervon nicht betroffen (sofort
aktiv, keine Future-Konkurrenz).

### Avatar-Sync (Delegated, beim SSO)

Anders als die anderen Graph-Calls: nicht App-only sondern mit **User-Access-Token**
aus dem gerade-abgeschlossenen Auth-Code-Flow. `src/Services/AvatarService.php` —
`fetchAndStore($userId, $userAccessToken)`. Holt `/me/photo/$value`, schreibt nach
`var/avatars/{user-id}.jpg`. 404 (= kein Foto in M365) löscht eine vorhandene
alte Datei, damit das UI dann auf Initials fällt.

Application Permission `User.Read.All` ist **nicht** nötig — Delegated `User.Read` reicht für `/me/photo`.

## Tensions

- **Auto-OOO bei Krank-Storno wird auch zurückgesetzt** (`src/Controllers/StornoController.php:97-110`) — auch wenn der User keinen OOO via App gesetzt hat, läuft `clearAutoReplyIfOurs` durch. Idempotent ohne Marker, kein Schaden, aber unnötiger Graph-Roundtrip pro Krank-Storno.
- **Refresh-Token-Recovery ist manuell** — wenn der Refresh-Token nach 90+ Tagen abläuft, scheitert jeder Mail-Versand bis `bin/setup-smtp-oauth.php` erneut läuft. Kein automatischer Reminder oder Notification, nur Server-Log-Eintrag.
- **`MailboxSettings.ReadWrite` Application Permission ist Tenant-weit** — wirkt auf jede Mailbox im Tenant. Falls eine Exchange Online Application Access Policy gewünscht ist, müsste das in Exchange-Admin gesetzt werden (aktuell nicht konfiguriert). Im aktuellen Setup könnte die App theoretisch jede User-Mailbox manipulieren.
- **`prompt=select_account` zwingt Picker auch bei nur einem aktiven Account** (`src/Auth/MicrosoftOAuth.php:43`). Geringfügiger UX-Mehraufwand für die Nutzer mit nur einem M365-Account. Bewusst weil die alternative (`prompt=none` mit Fallback) komplexer ist.
- **CSRF-Middleware-Reihenfolge** in den Route-Definitionen: `->add(CsrfMiddleware::class)->add(AuthMiddleware::class)` heißt: Auth wird zuerst ausgeführt (Slim-FILO). Bei einer geänderten Reihenfolge würde CSRF auch für nicht-eingeloggte Sessions geprüft → 403 vor dem Login-Redirect, verwirrend für User.
