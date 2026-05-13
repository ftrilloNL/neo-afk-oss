<?php declare(strict_types=1);

namespace App\Providers\Google;

use App\Providers\Contracts\MailTransport;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Gmail API messages.send. Service-Account-DWD impersoniert das fromEmail-Postfach,
 * dann POST auf /gmail/v1/users/me/messages/send mit base64url(raw RFC 5322).
 *
 * Scope: https://www.googleapis.com/auth/gmail.send
 *
 * Vorteil gegenueber SMTP-XOAUTH2: kein langlebiger Refresh-Token noetig,
 * Service-Account-Key uebernimmt das Auth-Setup einmalig. Kein Device-Code-Flow.
 */
final class GoogleMailTransport implements MailTransport
{
    private const SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    public function __construct(
        private readonly GoogleServiceAccountAuth $auth,
        private readonly ClientInterface $http = new Client(['timeout' => 10]),
    ) {
    }

    public function send(
        array $recipients,
        string $subject,
        string $htmlBody,
        string $fromEmail,
        string $fromName,
    ): void {
        $token = $this->auth->getAccessToken($fromEmail, [self::SCOPE]);
        $raw = $this->buildRfc822($recipients, $subject, $htmlBody, $fromEmail, $fromName);
        $rawB64 = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        try {
            $this->http->request(
                'POST',
                'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                    'json' => ['raw' => $rawB64],
                ],
            );
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Gmail send fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param list<string> $recipients
     */
    private function buildRfc822(
        array $recipients,
        string $subject,
        string $htmlBody,
        string $fromEmail,
        string $fromName,
    ): string {
        $headers = [];
        $fromEncoded = $this->encodePhrase($fromName);
        $headers[] = sprintf('From: %s <%s>', $fromEncoded, $fromEmail);
        $headers[] = 'To: ' . implode(', ', $recipients);
        $headers[] = 'Subject: ' . $this->encodeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';

        return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody), 76, "\r\n");
    }

    /** RFC 2047 encoded-word fuer Header mit Non-ASCII */
    private function encodeHeader(string $value): string
    {
        if (preg_match('//u', $value) && $value === mb_convert_encoding($value, 'ASCII', 'UTF-8')) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function encodePhrase(string $value): string
    {
        // Falls Name Non-ASCII enthaelt: encoded-word. Sonst: in Quotes wenn
        // Sonderzeichen wie "(", ",", "<", ">" drinstecken.
        if (preg_match('/[^\x20-\x7E]/', $value) === 1) {
            return $this->encodeHeader($value);
        }
        if (preg_match('/[",<>()@:;.\[\]\\\\]/', $value) === 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }
        return $value;
    }
}
