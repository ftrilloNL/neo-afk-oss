<?php declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Services\EnvWriter;
use GuzzleHttp\Client as HttpClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Symfony\Component\Translation\Translator;

/**
 * Setup-Wizard. Sammelt DB-Credentials, Schema/Seeds, Org-Branding,
 * M365-OAuth, SMTP-OAuth (Device-Code-Flow) und ersten HR-User; schreibt
 * Werte in .env und legt einen Lock-Marker an wenn fertig.
 *
 * Gate:
 *  - SETUP_MODE=true muss in .env stehen
 *  - var/secrets/setup-completed darf nicht existieren
 *
 * Auth: keine — der Wizard laeuft vor dem ersten Login. Das Gate ist die
 * einzige Verteidigung, deshalb sollte SETUP_MODE nach Fertigstellung
 * zwingend auf false (macht der Wizard selbst).
 */
final class SetupController
{
    // Step labels are looked up via translation key `setup.step.<slug>` --
    // see template setup/layout.twig. Only the numeric order is fixed here.
    private const STEPS = [
        'intro'    => ['nr' => 1],
        'provider' => ['nr' => 2],
        'db'       => ['nr' => 3],
        'org'      => ['nr' => 4],
        'oauth'    => ['nr' => 5],
        'smtp'     => ['nr' => 6],
        'admin'    => ['nr' => 7],
        'finish'   => ['nr' => 8],
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Twig $view,
        private readonly EnvWriter $env,
        private readonly string $rootPath,
        private readonly Translator $translator,
    ) {
    }

    private function gate(Response $response): ?Response
    {
        $marker = $this->rootPath . '/var/secrets/setup-completed';
        if (is_file($marker)) {
            $response->getBody()->write($this->translator->trans('setup.completed_message'));
            return $response->withStatus(410);
        }
        $setupMode = $_ENV['SETUP_MODE'] ?? 'false';
        if ($setupMode !== 'true') {
            return $response->withStatus(404);
        }
        return null;
    }

    public function intro(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        return $this->render($response, 'intro', []);
    }

    public function provider(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        return $this->render($response, 'provider', [
            'env' => $this->env->readAll(),
            'error' => $_SESSION['setup_error'] ?? null,
        ]);
    }

    public function providerSubmit(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $choice = (string) (($request->getParsedBody() ?? [])['identity_provider'] ?? '');
        if (!in_array($choice, ['microsoft', 'google'], true)) {
            $_SESSION['setup_error'] = $this->translator->trans('setup.error.choose_provider');
            return $response->withHeader('Location', '/setup/provider')->withStatus(302);
        }
        $this->env->update(['IDENTITY_PROVIDER' => $choice]);
        // Sofort in $_ENV propagieren, damit der naechste Step die Auswahl sieht.
        $_ENV['IDENTITY_PROVIDER'] = $choice;
        unset($_SESSION['setup_error']);
        return $response->withHeader('Location', '/setup/db')->withStatus(302);
    }

    private function selectedProvider(): string
    {
        $env = $this->env->readAll();
        $value = $env['IDENTITY_PROVIDER'] ?? ($_ENV['IDENTITY_PROVIDER'] ?? 'microsoft');
        return in_array($value, ['microsoft', 'google'], true) ? $value : 'microsoft';
    }

    public function db(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $env = $this->env->readAll();
        $availableSeeds = $this->discoverSeeds();
        return $this->render($response, 'db', [
            'env' => $env,
            'available_seeds' => $availableSeeds,
            'error' => $_SESSION['setup_error'] ?? null,
        ]);
    }

    public function dbSubmit(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $data = (array) $request->getParsedBody();
        $host = trim((string) ($data['db_host'] ?? ''));
        $port = trim((string) ($data['db_port'] ?? '3306'));
        $name = trim((string) ($data['db_name'] ?? ''));
        $user = trim((string) ($data['db_user'] ?? ''));
        $pass = (string) ($data['db_pass'] ?? '');
        $bundesland = trim((string) ($data['feiertage_bundesland'] ?? ''));

        try {
            if ($host === '' || $name === '' || $user === '' || $bundesland === '') {
                throw new \RuntimeException($this->translator->trans('setup.error.required_fields'));
            }
            $pdo = $this->connectPdo($host, $port, $name, $user, $pass);
            $this->applySql($pdo, $this->rootPath . '/migrations/schema.sql');
            $seedFile = $this->rootPath . '/migrations/seeds/feiertage-' . $bundesland . '.sql';
            if (!is_file($seedFile)) {
                throw new \RuntimeException($this->translator->trans('setup.error.seed_missing', ['%bundesland%' => $bundesland]));
            }
            $this->applySql($pdo, $seedFile);

            $this->env->update([
                'DB_HOST' => $host,
                'DB_PORT' => $port,
                'DB_NAME' => $name,
                'DB_USER' => $user,
                'DB_PASS' => $pass,
                'ORG_FEIERTAGE_BUNDESLAND' => $bundesland,
            ]);
            unset($_SESSION['setup_error']);
            return $response->withHeader('Location', '/setup/org')->withStatus(302);
        } catch (\Throwable $e) {
            $_SESSION['setup_error'] = $e->getMessage();
            return $response->withHeader('Location', '/setup/db')->withStatus(302);
        }
    }

    public function org(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        return $this->render($response, 'org', [
            'env' => $this->env->readAll(),
            'error' => $_SESSION['setup_error'] ?? null,
        ]);
    }

    public function orgSubmit(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $data = (array) $request->getParsedBody();
        $appUrl = rtrim(trim((string) ($data['app_url'] ?? '')), '/');
        $errors = [];
        if (!preg_match('~^https?://[^\s/]+~', $appUrl)) {
            $errors[] = $this->translator->trans('setup.error.app_url_format');
        }
        $jahresanspruch = (int) ($data['default_jahresanspruch'] ?? 30);
        if ($jahresanspruch < 1 || $jahresanspruch > 50) {
            $errors[] = $this->translator->trans('setup.error.jahresanspruch_range');
        }
        if ($errors) {
            $_SESSION['setup_error'] = implode(' ', $errors);
            return $response->withHeader('Location', '/setup/org')->withStatus(302);
        }
        $this->env->update([
            'APP_URL' => $appUrl,
            'ORG_NAME' => trim((string) $data['org_name']),
            'ORG_SHORT_NAME' => trim((string) $data['org_short_name']),
            'ORG_LEGAL_NAME' => trim((string) $data['org_legal_name']),
            'ORG_SUPPORT_EMAIL' => trim((string) $data['org_support_email']),
            'ORG_ACCENT_COLOR_HEX' => trim((string) $data['org_accent_color']),
            'ORG_DEFAULT_JAHRESANSPRUCH' => (string) $jahresanspruch,
            'OAUTH_REDIRECT_URI' => $appUrl . '/auth/callback',
        ]);
        unset($_SESSION['setup_error']);
        return $response->withHeader('Location', '/setup/oauth')->withStatus(302);
    }

    public function oauth(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        return $this->render($response, 'oauth', [
            'env' => $this->env->readAll(),
            'error' => $_SESSION['setup_error'] ?? null,
            'provider' => $this->selectedProvider(),
            'service_account_present' => is_file($this->rootPath . '/var/secrets/google-service-account.json'),
        ]);
    }

    public function oauthSubmit(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $provider = $this->selectedProvider();
        $data = (array) $request->getParsedBody();
        $hrMail = trim((string) ($data['hr_notification_email'] ?? ''));
        $clientId = trim((string) ($data['oauth_client_id'] ?? ''));
        $clientSecret = trim((string) ($data['oauth_client_secret'] ?? ''));

        $errors = [];
        if ($clientId === '') {
            $errors[] = $this->translator->trans('setup.error.oauth.client_id_required');
        }
        if ($clientSecret === '') {
            $errors[] = $this->translator->trans('setup.error.oauth.client_secret_required');
        }
        if ($hrMail === '') {
            $errors[] = $this->translator->trans('setup.error.oauth.hr_email_required');
        }

        $updates = [
            'OAUTH_CLIENT_ID' => $clientId,
            'OAUTH_CLIENT_SECRET' => $clientSecret,
            'HR_NOTIFICATION_EMAIL' => $hrMail,
        ];

        if ($provider === 'microsoft') {
            $tenant = trim((string) ($data['oauth_tenant_id'] ?? ''));
            $graphUser = trim((string) ($data['graph_calendar_user'] ?? ''));
            if (!preg_match('/^[0-9a-f-]{36}$/i', $tenant)) {
                $errors[] = $this->translator->trans('setup.error.oauth.tenant_guid');
            }
            if (!preg_match('/^[0-9a-f-]{36}$/i', $clientId)) {
                $errors[] = $this->translator->trans('setup.error.oauth.client_id_guid');
            }
            if (!filter_var($graphUser, FILTER_VALIDATE_EMAIL)) {
                $errors[] = $this->translator->trans('setup.error.oauth.shared_mailbox_invalid');
            }
            $updates['OAUTH_TENANT_ID'] = $tenant;
            $updates['GRAPH_CALENDAR_USER'] = $graphUser;
        } else {
            $workspaceDomain = trim((string) ($data['google_workspace_domain'] ?? ''));
            $calendarId = trim((string) ($data['google_calendar_id'] ?? ''));
            $calendarOwner = trim((string) ($data['google_calendar_owner'] ?? ''));
            if ($workspaceDomain === '') {
                $errors[] = $this->translator->trans('setup.error.oauth.workspace_domain_required');
            }
            if ($calendarId === '') {
                $errors[] = $this->translator->trans('setup.error.oauth.calendar_id_required');
            }
            if (!filter_var($calendarOwner, FILTER_VALIDATE_EMAIL)) {
                $errors[] = $this->translator->trans('setup.error.oauth.calendar_owner_invalid');
            }

            // Service-Account-JSON-Upload entgegennehmen und persistieren.
            $files = $request->getUploadedFiles();
            $keyFile = $files['service_account_json'] ?? null;
            if ($keyFile !== null && $keyFile->getError() === UPLOAD_ERR_OK) {
                $contents = (string) $keyFile->getStream()->getContents();
                $decoded = json_decode($contents, true);
                if (!is_array($decoded) || !isset($decoded['client_email'], $decoded['private_key'])) {
                    $errors[] = $this->translator->trans('setup.error.oauth.service_account_invalid');
                } else {
                    $this->saveServiceAccountKey($contents);
                }
            } elseif (!is_file($this->rootPath . '/var/secrets/google-service-account.json')) {
                $errors[] = $this->translator->trans('setup.error.oauth.service_account_missing');
            }

            $updates['GOOGLE_WORKSPACE_DOMAIN'] = $workspaceDomain;
            $updates['GOOGLE_CALENDAR_ID'] = $calendarId;
            $updates['GOOGLE_CALENDAR_OWNER'] = $calendarOwner;
        }

        if ($errors) {
            $_SESSION['setup_error'] = implode(' ', $errors);
            return $response->withHeader('Location', '/setup/oauth')->withStatus(302);
        }

        $this->env->update($updates);
        unset($_SESSION['setup_error']);
        return $response->withHeader('Location', '/setup/smtp')->withStatus(302);
    }

    private function saveServiceAccountKey(string $json): void
    {
        $secretsDir = $this->rootPath . '/var/secrets';
        if (!is_dir($secretsDir)) {
            mkdir($secretsDir, 0700, true);
        }
        $path = $secretsDir . '/google-service-account.json';
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $json);
        chmod($tmp, 0600);
        rename($tmp, $path);
    }

    public function smtp(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        return $this->render($response, 'smtp', [
            'env' => $this->env->readAll(),
            'error' => $_SESSION['setup_error'] ?? null,
            'device_flow' => $_SESSION['setup_device_flow'] ?? null,
            'provider' => $this->selectedProvider(),
        ]);
    }

    /**
     * Google nutzt den Service-Account fuer Mail-Send — keine separaten SMTP-
     * Credentials noetig. Endpoint sammelt nur FROM-Email + FROM-Name und
     * leitet direkt zum Admin-Step weiter.
     */
    public function smtpSkipGoogle(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $data = (array) $request->getParsedBody();
        $from = trim((string) ($data['smtp_from_email'] ?? ''));
        $name = trim((string) ($data['smtp_from_name'] ?? ''));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['setup_error'] = $this->translator->trans('setup.error.from_email_invalid');
            return $response->withHeader('Location', '/setup/smtp')->withStatus(302);
        }
        $this->env->update([
            'SMTP_FROM_EMAIL' => $from,
            'SMTP_FROM_NAME' => $name !== '' ? $name : 'afk',
        ]);
        unset($_SESSION['setup_error']);
        return $response->withHeader('Location', '/setup/admin')->withStatus(302);
    }

    public function smtpStart(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $data = (array) $request->getParsedBody();
        $host = trim((string) ($data['smtp_host'] ?? 'smtp.office365.com'));
        $port = trim((string) ($data['smtp_port'] ?? '587'));
        $from = trim((string) ($data['smtp_from_email'] ?? ''));
        $name = trim((string) ($data['smtp_from_name'] ?? ''));

        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['setup_error'] = $this->translator->trans('setup.error.smtp.from_email_invalid');
            return $response->withHeader('Location', '/setup/smtp')->withStatus(302);
        }
        $this->env->update([
            'SMTP_HOST' => $host,
            'SMTP_PORT' => $port,
            'SMTP_FROM_EMAIL' => $from,
            'SMTP_FROM_NAME' => $name,
        ]);
        $_ENV['SMTP_FROM_EMAIL'] = $from;

        $tenant = $_ENV['OAUTH_TENANT_ID'] ?? '';
        $clientId = $_ENV['OAUTH_CLIENT_ID'] ?? '';
        if ($tenant === '' || $clientId === '') {
            $_SESSION['setup_error'] = $this->translator->trans('setup.error.smtp.oauth_incomplete');
            return $response->withHeader('Location', '/setup/oauth')->withStatus(302);
        }

        try {
            $http = new HttpClient(['timeout' => 30]);
            $res = $http->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/devicecode", [
                'form_params' => [
                    'client_id' => $clientId,
                    'scope' => 'https://outlook.office.com/SMTP.Send offline_access',
                ],
            ]);
            $body = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $_SESSION['setup_device_flow'] = [
                'device_code' => $body['device_code'],
                'user_code' => $body['user_code'],
                'verification_uri' => $body['verification_uri'],
                'expires_at' => time() + (int) $body['expires_in'],
                'interval' => (int) ($body['interval'] ?? 5),
                'status' => 'pending',
            ];
            unset($_SESSION['setup_error']);
        } catch (\Throwable $e) {
            $_SESSION['setup_error'] = $this->translator->trans('setup.error.smtp.device_code_failed', ['%detail%' => $e->getMessage()]);
        }
        return $response->withHeader('Location', '/setup/smtp')->withStatus(302);
    }

    public function smtpPoll(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $flow = $_SESSION['setup_device_flow'] ?? null;
        if ($flow === null) {
            return $this->json($response, ['status' => 'no_flow'], 400);
        }
        if (($flow['status'] ?? '') === 'success') {
            return $this->json($response, ['status' => 'success']);
        }
        if (time() > ($flow['expires_at'] ?? 0)) {
            $_SESSION['setup_device_flow']['status'] = 'expired';
            return $this->json($response, ['status' => 'expired']);
        }
        $tenant = $_ENV['OAUTH_TENANT_ID'] ?? '';
        $clientId = $_ENV['OAUTH_CLIENT_ID'] ?? '';
        try {
            $http = new HttpClient(['timeout' => 30, 'http_errors' => false]);
            $res = $http->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                    'client_id' => $clientId,
                    'device_code' => $flow['device_code'],
                ],
            ]);
            $body = json_decode((string) $res->getBody(), true);
            if (isset($body['refresh_token'])) {
                $secretsDir = $this->rootPath . '/var/secrets';
                if (!is_dir($secretsDir)) {
                    mkdir($secretsDir, 0700, true);
                }
                $path = $secretsDir . '/smtp-refresh-token';
                file_put_contents($path, $body['refresh_token']);
                chmod($path, 0600);
                $_SESSION['setup_device_flow']['status'] = 'success';
                return $this->json($response, ['status' => 'success']);
            }
            $err = $body['error'] ?? 'unknown';
            if ($err === 'authorization_pending') {
                return $this->json($response, ['status' => 'pending']);
            }
            if ($err === 'slow_down') {
                return $this->json($response, ['status' => 'pending', 'slow_down' => true]);
            }
            $_SESSION['setup_device_flow']['status'] = 'error';
            return $this->json($response, ['status' => 'error', 'message' => $body['error_description'] ?? $err]);
        } catch (\Throwable $e) {
            return $this->json($response, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function smtpContinue(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        if (($_SESSION['setup_device_flow']['status'] ?? '') !== 'success') {
            $_SESSION['setup_error'] = $this->translator->trans('setup.error.smtp.token_pending');
            return $response->withHeader('Location', '/setup/smtp')->withStatus(302);
        }
        unset($_SESSION['setup_device_flow'], $_SESSION['setup_error']);
        return $response->withHeader('Location', '/setup/admin')->withStatus(302);
    }

    public function admin(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        return $this->render($response, 'admin', [
            'error' => $_SESSION['setup_error'] ?? null,
        ]);
    }

    public function adminSubmit(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $data = (array) $request->getParsedBody();
        $email = trim((string) ($data['email'] ?? ''));
        $displayName = trim((string) ($data['display_name'] ?? ''));
        $jobTitle = trim((string) ($data['job_title'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $displayName === '') {
            $_SESSION['setup_error'] = $this->translator->trans('setup.error.admin.required');
            return $response->withHeader('Location', '/setup/admin')->withStatus(302);
        }
        try {
            $envValues = $this->env->readAll();
            $pdo = $this->connectPdo(
                $envValues['DB_HOST'] ?? 'localhost',
                $envValues['DB_PORT'] ?? '3306',
                $envValues['DB_NAME'] ?? '',
                $envValues['DB_USER'] ?? '',
                $envValues['DB_PASS'] ?? '',
            );
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetchColumn()) {
                $_SESSION['setup_error'] = $this->translator->trans('setup.error.admin.duplicate');
                return $response->withHeader('Location', '/setup/admin')->withStatus(302);
            }
            $jahresanspruch = (int) ($envValues['ORG_DEFAULT_JAHRESANSPRUCH'] ?? 30);
            $stmt = $pdo->prepare(
                'INSERT INTO users (external_oid, external_provider, email, display_name,
                 job_title, jahresanspruch, ist_aktiv, ist_genehmiger, ist_hr)
                 VALUES (NULL, NULL, ?, ?, ?, ?, 1, 1, 1)'
            );
            $stmt->execute([$email, $displayName, $jobTitle ?: null, $jahresanspruch]);
            unset($_SESSION['setup_error']);
            return $response->withHeader('Location', '/setup/finish')->withStatus(302);
        } catch (\Throwable $e) {
            $_SESSION['setup_error'] = $this->translator->trans('setup.error.db_error', ['%detail%' => $e->getMessage()]);
            return $response->withHeader('Location', '/setup/admin')->withStatus(302);
        }
    }

    public function finish(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        return $this->render($response, 'finish', [
            'env' => $this->env->readAll(),
        ]);
    }

    public function finishConfirm(Request $request, Response $response): Response
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $this->env->update(['SETUP_MODE' => 'false']);
        $secretsDir = $this->rootPath . '/var/secrets';
        if (!is_dir($secretsDir)) {
            mkdir($secretsDir, 0700, true);
        }
        file_put_contents(
            $secretsDir . '/setup-completed',
            (new \DateTimeImmutable())->format('c') . "\n",
        );
        unset($_SESSION['setup_device_flow'], $_SESSION['setup_error']);
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    private function render(Response $response, string $step, array $vars): Response
    {
        $vars['step'] = $step;
        $vars['steps'] = self::STEPS;
        // Inject the slug as `key` so the template can resolve the label via
        // `('setup.step.' ~ current.key)|trans`.
        $vars['current'] = self::STEPS[$step] + ['key' => $step];
        return $this->view->render($response, 'setup/' . $step . '.twig', $vars);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    /** @return list<array{code:string,filename:string}> */
    private function discoverSeeds(): array
    {
        $out = [];
        $seedsDir = $this->rootPath . '/migrations/seeds';
        if (!is_dir($seedsDir)) {
            return $out;
        }
        foreach (glob($seedsDir . '/feiertage-*.sql') ?: [] as $path) {
            if (preg_match('/feiertage-([A-Z]{2})\.sql$/', $path, $m)) {
                $out[] = ['code' => $m[1], 'filename' => basename($path)];
            }
        }
        return $out;
    }

    private function connectPdo(string $host, string $port, string $db, string $user, string $pass): \PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);
        return new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function applySql(\PDO $pdo, string $sqlFile): void
    {
        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new \RuntimeException($this->translator->trans('setup.error.sql_unreadable', ['%file%' => $sqlFile]));
        }
        // Einfache Statement-Trennung — funktioniert fuer schema.sql und seeds,
        // die keine procedural code blocks enthalten.
        $statements = array_filter(array_map('trim', explode(';', $this->stripSqlComments($sql))));
        foreach ($statements as $stmt) {
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }

    private function stripSqlComments(string $sql): string
    {
        $out = [];
        foreach (explode("\n", $sql) as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }
}
