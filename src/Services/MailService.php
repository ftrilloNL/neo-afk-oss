<?php declare(strict_types=1);

namespace App\Services;

use App\Config;
use PHPMailer\PHPMailer\PHPMailer;
use Slim\Views\Twig;

/**
 * Versendet HTML-Mails per SMTP mit OAuth2/XOAUTH2-Auth gegen Office365.
 *
 * Im Dev-Modus (APP_ENV != production) werden Mails als HTML-Files in
 * `var/mails/` geschrieben statt versendet — Magic-Links lassen sich daraus
 * im Browser oeffnen, ohne SMTP-Setup zu brauchen.
 *
 * Production-Auth laeuft via SmtpOAuthTokenProvider: kein Passwort in .env,
 * sondern persistierter Refresh-Token unter var/secrets/. Erstmaliges Setup
 * via bin/setup-smtp-oauth.php (siehe docs/smtp-setup.md).
 */
final class MailService
{
    public function __construct(
        private readonly Twig $view,
        private readonly Config $config,
        private readonly SmtpOAuthTokenProvider $tokenProvider,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @param string $to Eine Adresse, oder komma-/semikolon-separierte Liste mehrerer
     *                   Adressen. Beispiel: "hr@firma.de, geschaeftsfuehrung@firma.de".
     */
    public function send(string $to, string $subject, string $template, array $context = []): void
    {
        $body = $this->view->fetch($template, $context);

        if (!$this->config->isProduction()) {
            $this->writeToFile($to, $subject, $body);
            return;
        }

        $recipients = $this->parseRecipients($to);
        if ($recipients === []) {
            return;
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config->get('SMTP_HOST');
        $mail->Port = (int) $this->config->get('SMTP_PORT', '587');
        $mail->SMTPAuth = true;
        $mail->AuthType = 'XOAUTH2';
        $mail->setOAuth($this->tokenProvider);
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(
            $this->config->get('SMTP_FROM_EMAIL'),
            $this->config->get('SMTP_FROM_NAME', 'neo:afk'),
        );
        foreach ($recipients as $addr) {
            $mail->addAddress($addr);
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
    }

    /**
     * Akzeptiert Komma oder Semikolon als Trenner — beide Konventionen sind
     * in Outlook/M365 verbreitet. Doppelte und leere Eintraege werden gefiltert.
     *
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
