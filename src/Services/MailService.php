<?php declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Providers\Contracts\MailTransport;
use Slim\Views\Twig;
use Symfony\Component\Translation\Translator;

/**
 * Versendet Twig-gerenderte HTML-Mails. Provider-Auswahl (Microsoft SMTP-XOAUTH2
 * oder Google Gmail-API) erfolgt im DI-Container ueber das MailTransport-Interface.
 *
 * Im Dev-Modus (APP_ENV != production) werden Mails als HTML-Files in `var/mails/`
 * geschrieben — Magic-Links lassen sich daraus im Browser oeffnen, ohne dass ein
 * echter Mail-Versand stattfindet.
 *
 * Locale: Subject + Body werden in der aktuellen Translator-Locale gerendert.
 * Web-Aufrufe: Locale wurde durch LocaleMiddleware aus Accept-Language gesetzt.
 * Cron-Aufrufe: keine Middleware -> Locale steht auf Config::defaultLocale()
 * (Initial-Wert des Translators in App.php).
 */
final class MailService
{
    public function __construct(
        private readonly Twig $view,
        private readonly Config $config,
        private readonly MailTransport $transport,
        private readonly Translator $translator,
    ) {
    }

    /**
     * @param array<string, scalar|\Stringable> $subjectVars Interpolations-Vars fuer den Subject-Key.
     * @param array<string, mixed> $context Twig-Render-Kontext fuer den Body.
     * @param string $to Eine Adresse, oder komma-/semikolon-separierte Liste.
     */
    public function send(string $to, string $subjectKey, array $subjectVars, string $template, array $context = []): void
    {
        $subject = $this->translator->trans($subjectKey, $subjectVars);
        $body = $this->view->fetch($template, $context);

        if (!$this->config->isProduction()) {
            $this->writeToFile($to, $subject, $body);
            return;
        }

        $recipients = $this->parseRecipients($to);
        if ($recipients === []) {
            return;
        }

        $this->transport->send(
            $recipients,
            $subject,
            $body,
            $this->config->get('SMTP_FROM_EMAIL'),
            $this->config->get('SMTP_FROM_NAME', 'neo-afk'),
        );
    }

    /**
     * @return list<string>
     */
    private function parseRecipients(string $to): array
    {
        $parts = preg_split('/[,;]/', $to) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $addr = trim($part);
            if ($addr !== '' && !in_array($addr, $clean, true)) {
                $clean[] = $addr;
            }
        }
        return $clean;
    }

    private function writeToFile(string $to, string $subject, string $body): void
    {
        $dir = dirname(__DIR__, 2) . '/var/mails';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = sprintf(
            '%s_%s_%s.html',
            date('Y-m-d_His'),
            preg_replace('/[^a-zA-Z0-9]+/', '-', $to),
            substr(md5($subject . microtime(true)), 0, 6),
        );
        $html = sprintf(
            "<!--\nTO: %s\nSUBJECT: %s\nDATE: %s\n-->\n%s",
            $to,
            $subject,
            date('c'),
            $body,
        );
        file_put_contents($dir . '/' . $filename, $html);
    }
}
