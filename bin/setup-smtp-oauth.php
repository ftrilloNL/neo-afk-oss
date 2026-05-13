#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Einmaliges Setup: holt einen Refresh-Token fuer SMTP-OAuth (XOAUTH2) und
 * speichert ihn nach var/secrets/smtp-refresh-token.
 *
 * Nutzt OAuth2 Device-Code-Flow — kein lokaler HTTP-Server noetig:
 *  1. Skript fragt Microsoft nach einem Device-Code
 *  2. User oeffnet https://microsoft.com/devicelogin im Browser, gibt Code ein,
 *     loggt sich als das in SMTP_FROM_EMAIL konfigurierte Postfach ein, autorisiert die App
 *  3. Skript pollt den Token-Endpoint bis der User autorisiert hat
 *  4. Refresh-Token wird persistiert
 *
 * Voraussetzungen (siehe docs/entra-id-setup.md):
 *  - Delegated Permission "SMTP.Send" mit Admin-Konsens
 *  - "Allow public client flows" in der App-Registrierung auf "Ja"
 *
 * Aufruf:
 *   php bin/setup-smtp-oauth.php
 *
 * Wiederholen falls Refresh-Token nach >90 Tagen Inaktivitaet abgelaufen ist.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\SmtpOAuthTokenProvider;
use Dotenv\Dotenv;
use GuzzleHttp\Client;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();

$tenant = $_ENV['OAUTH_TENANT_ID'] ?? '';
$clientId = $_ENV['OAUTH_CLIENT_ID'] ?? '';
$clientSecret = $_ENV['OAUTH_CLIENT_SECRET'] ?? '';
$userEmail = $_ENV['SMTP_FROM_EMAIL'] ?? 'noreply@example.com';

if ($tenant === '' || $clientId === '') {
    fwrite(STDERR, "Fehler: OAUTH_TENANT_ID und OAUTH_CLIENT_ID muessen in .env gesetzt sein.\n");
    exit(1);
}

echo sprintf(
    "Config: tenant=%s..., client_id=%s..., secret=%s (Laenge %d), user=%s\n\n",
    substr($tenant, 0, 8),
    substr($clientId, 0, 8),
    $clientSecret === '' ? 'LEER' : 'gesetzt',
    strlen($clientSecret),
    $userEmail,
);

$http = new Client(['timeout' => 30]);
$scope = 'https://outlook.office.com/SMTP.Send offline_access';

echo "Hole Device-Code von Microsoft...\n";
$response = $http->request(
    'POST',
    "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/devicecode",
    [
        'form_params' => [
            'client_id' => $clientId,
            'scope' => $scope,
        ],
    ],
);
$device = json_decode((string) $response->getBody(), true);

if (!is_array($device) || !isset($device['device_code'], $device['user_code'], $device['verification_uri'])) {
    fwrite(STDERR, "Unerwartete Device-Code-Response.\n");
    exit(1);
}

echo "\n";
echo "==========================================================\n";
echo " 1. Oeffne im Browser: {$device['verification_uri']}\n";
echo " 2. Gib diesen Code ein: {$device['user_code']}\n";
echo " 3. Logge dich als {$userEmail} ein\n";
echo " 4. Bestaetige die Berechtigung (SMTP.Send + offline_access)\n";
echo "==========================================================\n\n";
echo "Warte auf Autorisierung (Timeout " . (int) $device['expires_in'] . "s)...\n";

$interval = max(5, (int) ($device['interval'] ?? 5));
$deadline = time() + (int) $device['expires_in'];
$tokenData = null;

while (time() < $deadline) {
    sleep($interval);
    try {
        // Public-Client-Flow (Mobile/Desktop-Plattform in der App-Registrierung):
        // KEIN client_secret. Wenn die App-Registrierung auch eine Web-Plattform
        // hat (fuer SSO), unterscheidet Microsoft anhand der request-Parameter
        // welcher Modus gemeint ist.
        $params = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => $clientId,
            'device_code' => $device['device_code'],
        ];

        $tokenResp = $http->request(
            'POST',
            "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
            [
                'form_params' => $params,
                'http_errors' => false,
            ],
        );
    } catch (Throwable $e) {
        fwrite(STDERR, "Netzwerk-Fehler beim Polling: " . $e->getMessage() . "\n");
        continue;
    }

    $body = json_decode((string) $tokenResp->getBody(), true);
    if (!is_array($body)) {
        continue;
    }

    if (isset($body['access_token'], $body['refresh_token'])) {
        $tokenData = $body;
        break;
    }

    $error = $body['error'] ?? '';
    if ($error === 'authorization_pending') {
        echo ".";
        continue;
    }
    if ($error === 'slow_down') {
        $interval += 5;
        continue;
    }
    fwrite(STDERR, "\nAutorisierung fehlgeschlagen: {$error} — " . ($body['error_description'] ?? '') . "\n");
    fwrite(STDERR, "Gesendete Parameter (keys): " . implode(', ', array_keys($params)) . "\n");
    fwrite(STDERR, "client_secret im Request: " . (isset($params['client_secret']) ? 'ja (Laenge ' . strlen($params['client_secret']) . ')' : 'NEIN') . "\n");
    exit(1);
}

echo "\n";

if ($tokenData === null) {
    fwrite(STDERR, "Timeout — Autorisierung nicht abgeschlossen.\n");
    exit(1);
}

$tokenFile = $root . '/var/secrets/smtp-refresh-token';

// Wir brauchen keine Config-Klasse hier — direkt schreiben.
$dir = dirname($tokenFile);
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}
$tmp = $tokenFile . '.tmp';
file_put_contents($tmp, (string) $tokenData['refresh_token']);
chmod($tmp, 0600);
rename($tmp, $tokenFile);

echo "Refresh-Token gespeichert nach {$tokenFile}\n";
echo "Datei-Permissions: 0600 (nur Owner lesbar)\n";
echo "\nSetup fertig. Naechster Mail-Send geht ueber XOAUTH2.\n";
