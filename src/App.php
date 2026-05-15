<?php declare(strict_types=1);

namespace App;

use App\Controllers\AntragController;
use App\Controllers\ApprovalController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\AvatarController;
use App\Controllers\GenehmigungenController;
use App\Controllers\HomeController;
use App\Controllers\HrAntragController;
use App\Controllers\HrController;
use App\Controllers\HrKrankController;
use App\Controllers\HrUsersController;
use App\Controllers\KrankController;
use App\Controllers\ProfilController;
use App\Controllers\SetupController;
use App\Controllers\StornoController;
use App\Controllers\TeamController;
use App\Database\Connection;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\HrMiddleware;
use App\Models\AbsenceRepository;
use App\Models\ApprovalTokenRepository;
use App\Models\AuditLogRepository;
use App\Models\FeiertagRepository;
use App\Models\UserRepository;
use App\Providers\Contracts\CalendarProvider;
use App\Providers\Contracts\IdentityProvider;
use App\Providers\Contracts\MailTransport;
use App\Providers\Contracts\OooProvider;
use App\Providers\Google\GoogleCalendarProvider;
use App\Providers\Google\GoogleIdentityProvider;
use App\Providers\Google\GoogleMailTransport;
use App\Providers\Google\GoogleOooProvider;
use App\Providers\Google\GoogleServiceAccountAuth;
use App\Providers\Microsoft\MicrosoftCalendarProvider;
use App\Providers\Microsoft\MicrosoftGraphHttp;
use App\Providers\Microsoft\MicrosoftIdentityProvider;
use App\Providers\Microsoft\MicrosoftMailTransport;
use App\Providers\Microsoft\MicrosoftOooProvider;
use App\Services\ApprovalService;
use App\Services\AvatarService;
use App\Services\Csrf;
use App\Services\EnvWriter;
use App\Services\MailService;
use App\Services\ResturlaubService;
use App\Services\SmtpOAuthTokenProvider;
use App\Services\WerktageService;
use App\Support\DevModeLog;
use DI\Container;
use Dotenv\Dotenv;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

final class App
{
    public static function create(string $rootPath): SlimApp
    {
        Dotenv::createImmutable($rootPath)->safeLoad();

        if (session_status() === PHP_SESSION_NONE) {
            // 30 Tage absolute Lebenszeit. Beides muss gesetzt werden:
            //  - cookie_lifetime: wie lange der Browser das Cookie behaelt
            //  - gc_maxlifetime: wann PHP serverseitig die Session-Files loescht
            // Andernfalls (PHP-Default ~24 Min) wuerde der GC die Server-Side-Daten
            // wegraeumen lange bevor das Cookie ablaeuft.
            $sessionLifetime = 30 * 86400;
            ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
            session_set_cookie_params([
                'lifetime' => $sessionLifetime,
                'path' => '/',
                'secure' => ($_ENV['APP_ENV'] ?? 'production') === 'production',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        $container = self::buildContainer($rootPath);
        AppFactory::setContainer($container);
        $app = AppFactory::create();

        $app->add(TwigMiddleware::createFromContainer($app, Twig::class));
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();

        // Public routes
        $app->get('/', [HomeController::class, 'index']);
        $app->get('/login', [AuthController::class, 'login']);

        // Setup-Wizard (gated via SETUP_MODE + var/secrets/setup-completed)
        $app->get('/setup',                [SetupController::class, 'intro']);
        $app->get('/setup/provider',       [SetupController::class, 'provider']);
        $app->post('/setup/provider',      [SetupController::class, 'providerSubmit']);
        $app->get('/setup/db',             [SetupController::class, 'db']);
        $app->post('/setup/db',            [SetupController::class, 'dbSubmit']);
        $app->get('/setup/org',            [SetupController::class, 'org']);
        $app->post('/setup/org',           [SetupController::class, 'orgSubmit']);
        $app->get('/setup/oauth',          [SetupController::class, 'oauth']);
        $app->post('/setup/oauth',         [SetupController::class, 'oauthSubmit']);
        $app->get('/setup/smtp',           [SetupController::class, 'smtp']);
        $app->post('/setup/smtp',          [SetupController::class, 'smtpStart']);
        $app->post('/setup/smtp/google',   [SetupController::class, 'smtpSkipGoogle']);
        $app->get('/setup/smtp/poll',      [SetupController::class, 'smtpPoll']);
        $app->post('/setup/smtp/continue', [SetupController::class, 'smtpContinue']);
        $app->get('/setup/admin',          [SetupController::class, 'admin']);
        $app->post('/setup/admin',         [SetupController::class, 'adminSubmit']);
        $app->get('/setup/finish',         [SetupController::class, 'finish']);
        $app->post('/setup/finish',        [SetupController::class, 'finishConfirm']);

        // PWA-Manifest dynamisch aus Org-Settings.
        $app->get('/manifest.json', function ($req, $res) use ($container) {
            /** @var Config $config */
            $config = $container->get(Config::class);
            $org = $config->org();
            $logoUrl = $org['logo_url'];
            $manifest = [
                'name' => $org['name'],
                'short_name' => $org['short_name'],
                'description' => sprintf('Abwesenheits-Tracking für %s', $org['legal_name']),
                'start_url' => '/',
                'scope' => '/',
                'display' => 'standalone',
                'orientation' => 'portrait-primary',
                'theme_color' => $org['accent_color'],
                'background_color' => '#ffffff',
                'lang' => 'de-DE',
                'icons' => [
                    // PNG-Icons werden standardmaessig erwartet unter /assets/icon-{180,192,512}.png.
                    // Falls eine fremde Instanz andere PNGs hinterlegen will: SVG-only-Fallback unten.
                    ['src' => '/assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                    ['src' => '/assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                    ['src' => '/assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                    ['src' => '/assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                    ['src' => $logoUrl, 'sizes' => 'any', 'type' => str_ends_with($logoUrl, '.svg') ? 'image/svg+xml' : 'image/png'],
                ],
            ];
            $res->getBody()->write(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type', 'application/manifest+json');
        });

        $app->get('/auth/callback', [AuthController::class, 'callback']);
        $app->post('/logout', [AuthController::class, 'logout'])->add(CsrfMiddleware::class);

        // Magic-Link-Approval (public — Token ist Autorisierung, kein CSRF noetig)
        $app->get('/approval/{token}', [ApprovalController::class, 'landing']);
        $app->post('/approval/{token}', [ApprovalController::class, 'action']);

        // Protected routes — alle POSTs durch CsrfMiddleware
        $app->get('/antrag/neu', [AntragController::class, 'neu'])->add(AuthMiddleware::class);
        $app->get('/antrag/preview-tage', [AntragController::class, 'previewTage'])->add(AuthMiddleware::class);
        $app->post('/antrag', [AntragController::class, 'submit'])->add(CsrfMiddleware::class)->add(AuthMiddleware::class);
        $app->post('/antrag/{id}/storno', [StornoController::class, 'storno'])->add(CsrfMiddleware::class)->add(AuthMiddleware::class);

        $app->get('/krank/neu', [KrankController::class, 'neu'])->add(AuthMiddleware::class);
        $app->post('/krank', [KrankController::class, 'submit'])->add(CsrfMiddleware::class)->add(AuthMiddleware::class);

        $app->get('/profil', [ProfilController::class, 'index'])->add(AuthMiddleware::class);
        $app->get('/team', [TeamController::class, 'index'])->add(AuthMiddleware::class);
        $app->get('/avatar/{id}', [AvatarController::class, 'show'])->add(AuthMiddleware::class);

        $app->get('/genehmigungen', [GenehmigungenController::class, 'index'])->add(AuthMiddleware::class);
        $app->post('/genehmigungen/{id}/approve', [GenehmigungenController::class, 'approve'])->add(CsrfMiddleware::class)->add(AuthMiddleware::class);
        $app->post('/genehmigungen/{id}/reject', [GenehmigungenController::class, 'reject'])->add(CsrfMiddleware::class)->add(AuthMiddleware::class);

        $app->get('/hr', [HrController::class, 'index'])->add(HrMiddleware::class)->add(AuthMiddleware::class);
        $app->get('/hr/audit', [AuditController::class, 'index'])->add(HrMiddleware::class)->add(AuthMiddleware::class);

        $app->get('/hr/antrag/neu', [HrAntragController::class, 'neu'])->add(HrMiddleware::class)->add(AuthMiddleware::class);
        $app->get('/hr/antrag/preview-tage', [HrAntragController::class, 'previewTage'])->add(HrMiddleware::class)->add(AuthMiddleware::class);
        $app->post('/hr/antrag', [HrAntragController::class, 'submit'])->add(CsrfMiddleware::class)->add(HrMiddleware::class)->add(AuthMiddleware::class);

        $app->get('/hr/krank/neu', [HrKrankController::class, 'neu'])->add(HrMiddleware::class)->add(AuthMiddleware::class);
        $app->post('/hr/krank', [HrKrankController::class, 'submit'])->add(CsrfMiddleware::class)->add(HrMiddleware::class)->add(AuthMiddleware::class);

        $app->get('/hr/users', [HrUsersController::class, 'index'])->add(HrMiddleware::class)->add(AuthMiddleware::class);
        $app->get('/hr/users/new', [HrUsersController::class, 'newForm'])->add(HrMiddleware::class)->add(AuthMiddleware::class);
        $app->post('/hr/users', [HrUsersController::class, 'create'])->add(CsrfMiddleware::class)->add(HrMiddleware::class)->add(AuthMiddleware::class);
        $app->get('/hr/users/{id}/edit', [HrUsersController::class, 'edit'])->add(HrMiddleware::class)->add(AuthMiddleware::class);
        $app->post('/hr/users/{id}', [HrUsersController::class, 'update'])->add(CsrfMiddleware::class)->add(HrMiddleware::class)->add(AuthMiddleware::class);

        // Dev-only — fake login fuer lokales Smoke-Testen
        if (($_ENV['APP_ENV'] ?? 'production') !== 'production') {
            $app->get('/dev/test-as-hr', fn ($req, $res) =>
                self::devLoginAs($container, $res, 'hr@example.com', 'HR Testuser', true, true));
            $app->get('/dev/test-as-user', fn ($req, $res) =>
                self::devLoginAs($container, $res, 'user@example.com', 'Standard Testuser', true, false));
        }

        $app->addErrorMiddleware(
            ($_ENV['APP_ENV'] ?? 'production') !== 'production',
            true,
            true
        );

        return $app;
    }

    private static function devLoginAs(Container $c, $response, string $email, string $displayName, bool $isGenehmiger, bool $isHr)
    {
        $conn = $c->get(Connection::class)->dbal();
        $existing = $conn->fetchAssociative('SELECT id FROM users WHERE email = ?', [$email]);
        if ($existing === false) {
            $conn->insert('users', [
                'external_oid' => 'dev-' . bin2hex(random_bytes(8)),
                'external_provider' => 'microsoft',
                'email' => $email,
                'display_name' => $displayName,
                'jahresanspruch' => 30,
                'resturlaub_aktuell' => 24.5,
                'resturlaub_vorjahr' => 3,
                'ist_aktiv' => 1,
                'ist_genehmiger' => $isGenehmiger ? 1 : 0,
                'ist_hr' => $isHr ? 1 : 0,
            ]);
            $userId = (int) $conn->lastInsertId();
        } else {
            $userId = (int) $existing['id'];
        }
        $_SESSION['user_id'] = $userId;
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    public static function buildContainer(string $rootPath): Container
    {
        $c = new Container();

        // Core
        $c->set(Config::class, fn () => new Config());
        $c->set(Connection::class, fn (Container $c) => new Connection($c->get(Config::class)));
        $c->set(Csrf::class, fn () => new Csrf());
        $c->set(DevModeLog::class, fn () => new DevModeLog($rootPath . '/var/logs'));
        $c->set(Twig::class, function (Container $c) use ($rootPath) {
            $twig = Twig::create(
                $rootPath . '/src/Templates',
                ['cache' => false, 'debug' => ($_ENV['APP_ENV'] ?? 'production') !== 'production']
            );
            $csrf = $c->get(Csrf::class);
            $config = $c->get(Config::class);
            $twig->getEnvironment()->addGlobal('org', $config->org());
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'csrf_field',
                fn (): string => sprintf(
                    '<input type="hidden" name="%s" value="%s">',
                    htmlspecialchars($csrf->fieldName(), ENT_QUOTES),
                    htmlspecialchars($csrf->token(), ENT_QUOTES),
                ),
                ['is_safe' => ['html']],
            ));
            $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
                'monat_jahr_de',
                function (?\DateTimeInterface $when = null): string {
                    $when ??= new \DateTimeImmutable();
                    $months = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
                               'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
                    return $months[(int) $when->format('n') - 1] . ' ' . $when->format('Y');
                },
            ));
            return $twig;
        });

        // Repositories
        $c->set(UserRepository::class, fn (Container $c) => new UserRepository($c->get(Connection::class)));
        $c->set(FeiertagRepository::class, fn (Container $c) => new FeiertagRepository($c->get(Connection::class)));
        $c->set(AbsenceRepository::class, fn (Container $c) => new AbsenceRepository($c->get(Connection::class)));
        $c->set(ApprovalTokenRepository::class, fn (Container $c) => new ApprovalTokenRepository($c->get(Connection::class)));
        $c->set(AuditLogRepository::class, fn (Container $c) => new AuditLogRepository($c->get(Connection::class)));

        // Services
        $c->set(WerktageService::class, fn (Container $c) => new WerktageService(
            $c->get(FeiertagRepository::class),
            $c->get(Config::class),
        ));
        $c->set(ResturlaubService::class, fn (Container $c) => new ResturlaubService($c->get(UserRepository::class)));
        $c->set(AvatarService::class, fn () => new AvatarService($rootPath . '/var/avatars'));

        // Provider-Factory: IDENTITY_PROVIDER waehlt Microsoft- oder Google-Adapter
        // fuer IdentityProvider, CalendarProvider, OooProvider, MailTransport.
        // SetupController nutzt die Provider nicht (Wizard laeuft vor dem Provider-
        // Setup), deshalb darf der Container den Provider-Constructor erst lazy
        // beim ersten Get aufrufen — wir registrieren beide Familien defensiv und
        // routen ueber IDENTITY_PROVIDER.
        $provider = $_ENV['IDENTITY_PROVIDER'] ?? 'microsoft';

        // Microsoft-Stack
        $c->set(MicrosoftGraphHttp::class, fn (Container $c) => new MicrosoftGraphHttp($c->get(Config::class)));
        $c->set(SmtpOAuthTokenProvider::class, fn (Container $c) => new SmtpOAuthTokenProvider(
            $c->get(Config::class),
            $rootPath . '/var/secrets/smtp-refresh-token',
            $c->get(Config::class)->get('SMTP_FROM_EMAIL', 'noreply@example.com'),
        ));
        $c->set(MicrosoftIdentityProvider::class, fn (Container $c) => new MicrosoftIdentityProvider($c->get(Config::class)));
        $c->set(MicrosoftCalendarProvider::class, fn (Container $c) => new MicrosoftCalendarProvider(
            $c->get(Config::class),
            $c->get(MicrosoftGraphHttp::class),
            $c->get(DevModeLog::class),
        ));
        $c->set(MicrosoftOooProvider::class, fn (Container $c) => new MicrosoftOooProvider(
            $c->get(Config::class),
            $c->get(MicrosoftGraphHttp::class),
            $c->get(DevModeLog::class),
        ));
        $c->set(MicrosoftMailTransport::class, fn (Container $c) => new MicrosoftMailTransport(
            $c->get(Config::class),
            $c->get(SmtpOAuthTokenProvider::class),
        ));

        // Google-Stack
        $c->set(GoogleServiceAccountAuth::class, fn (Container $c) => new GoogleServiceAccountAuth(
            $c->get(Config::class),
            $rootPath . '/var/secrets/google-service-account.json',
        ));
        $c->set(GoogleIdentityProvider::class, fn (Container $c) => new GoogleIdentityProvider($c->get(Config::class)));
        $c->set(GoogleCalendarProvider::class, fn (Container $c) => new GoogleCalendarProvider(
            $c->get(Config::class),
            $c->get(GoogleServiceAccountAuth::class),
            $c->get(DevModeLog::class),
        ));
        $c->set(GoogleOooProvider::class, fn (Container $c) => new GoogleOooProvider(
            $c->get(Config::class),
            $c->get(GoogleServiceAccountAuth::class),
            $c->get(DevModeLog::class),
        ));
        $c->set(GoogleMailTransport::class, fn (Container $c) => new GoogleMailTransport(
            $c->get(GoogleServiceAccountAuth::class),
        ));

        // Provider-Interface-Routing
        if ($provider === 'google') {
            $c->set(IdentityProvider::class, fn (Container $c) => $c->get(GoogleIdentityProvider::class));
            $c->set(CalendarProvider::class, fn (Container $c) => $c->get(GoogleCalendarProvider::class));
            $c->set(OooProvider::class, fn (Container $c) => $c->get(GoogleOooProvider::class));
            $c->set(MailTransport::class, fn (Container $c) => $c->get(GoogleMailTransport::class));
        } else {
            $c->set(IdentityProvider::class, fn (Container $c) => $c->get(MicrosoftIdentityProvider::class));
            $c->set(CalendarProvider::class, fn (Container $c) => $c->get(MicrosoftCalendarProvider::class));
            $c->set(OooProvider::class, fn (Container $c) => $c->get(MicrosoftOooProvider::class));
            $c->set(MailTransport::class, fn (Container $c) => $c->get(MicrosoftMailTransport::class));
        }

        $c->set(MailService::class, fn (Container $c) => new MailService(
            $c->get(Twig::class),
            $c->get(Config::class),
            $c->get(MailTransport::class),
        ));
        $c->set(ApprovalService::class, fn (Container $c) => new ApprovalService(
            $c->get(AbsenceRepository::class),
            $c->get(ApprovalTokenRepository::class),
            $c->get(UserRepository::class),
            $c->get(ResturlaubService::class),
            $c->get(CalendarProvider::class),
            $c->get(OooProvider::class),
            $c->get(MailService::class),
            $c->get(AuditLogRepository::class),
            $c->get(Config::class),
            $c->get(Connection::class),
        ));

        // Middleware needs DI for repos
        $c->set(HrMiddleware::class, fn (Container $c) => new HrMiddleware($c->get(UserRepository::class)));
        $c->set(CsrfMiddleware::class, fn (Container $c) => new CsrfMiddleware($c->get(Csrf::class)));

        // Setup-Wizard
        $c->set(EnvWriter::class, fn () => new EnvWriter($rootPath . '/.env'));
        $c->set(\App\Controllers\SetupController::class, fn (Container $c) => new \App\Controllers\SetupController(
            $c->get(Config::class),
            $c->get(Twig::class),
            $c->get(EnvWriter::class),
            $rootPath,
        ));

        return $c;
    }
}
