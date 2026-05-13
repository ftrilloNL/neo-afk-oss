<?php declare(strict_types=1);

namespace App\Providers\Microsoft;

use App\Config;
use App\Providers\Contracts\MailTransport;
use App\Services\SmtpOAuthTokenProvider;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * SMTP smtp.office365.com:587 mit XOAUTH2-Auth. Refresh-Token aus
 * var/secrets/smtp-refresh-token (initial via bin/setup-smtp-oauth.php oder
 * Setup-Wizard /setup/smtp).
 */
final class MicrosoftMailTransport implements MailTransport
{
    public function __construct(
        private readonly Config $config,
        private readonly SmtpOAuthTokenProvider $tokenProvider,
    ) {
    }

    public function send(
        array $recipients,
        string $subject,
        string $htmlBody,
        string $fromEmail,
        string $fromName,
    ): void {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config->get('SMTP_HOST');
        $mail->Port = (int) $this->config->get('SMTP_PORT', '587');
        $mail->SMTPAuth = true;
        $mail->AuthType = 'XOAUTH2';
        $mail->setOAuth($this->tokenProvider);
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        foreach ($recipients as $addr) {
            $mail->addAddress($addr);
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->send();
    }
}
