<?php declare(strict_types=1);

namespace App\Providers\Contracts;

/**
 * Versendet eine fertig gerenderte HTML-Mail. Eine Implementierung pro Backend.
 *
 * - Microsoft: SMTP smtp.office365.com:587 mit XOAUTH2 (Refresh-Token aus
 *   var/secrets/smtp-refresh-token)
 * - Google: Gmail API /users/{from}/messages/send mit Service-Account-DWD
 *   impersonating $fromEmail
 *
 * Dev-Mode-File-Output ist im MailService implementiert, nicht hier — Transport
 * ist nur fuer den echten Versand zustaendig.
 *
 * @phpstan-type Recipient string
 */
interface MailTransport
{
    /**
     * @param list<Recipient> $recipients
     */
    public function send(
        array $recipients,
        string $subject,
        string $htmlBody,
        string $fromEmail,
        string $fromName,
    ): void;
}
