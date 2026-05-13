<?php declare(strict_types=1);

namespace App\Providers\Google;

use App\Config;
use App\Providers\Contracts\OooProvider;
use App\Support\AutoReplyMarker;
use App\Support\DevModeLog;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Gmail Vacation Settings — Auto-Reply am User-Postfach. Service-Account-DWD
 * impersoniert $userMailbox, dann PUT auf /gmail/v1/users/me/settings/vacation.
 *
 * Scope: https://www.googleapis.com/auth/gmail.settings.basic
 *
 * Gmail-Quirks im Vergleich zu Microsoft:
 *  - Nur eine Vacation-Config pro Mailbox; kein "scheduled future" — bei
 *    Multi-Vacation-Konflikten muss der Aufrufer (cron/ooo-sync.php) selbst
 *    zum Start-Tag die richtige Config setzen. Gilt analog zu M365.
 *  - startTime/endTime sind Unix-Millis (nicht ISO-Strings).
 *  - Es gibt zwei Bodies: responseBodyPlainText UND responseBodyHtml. Gmail
 *    waehlt automatisch je nach Empfaenger.
 *  - Kein "internal vs external" — Gmail hat nur restrictToContacts (an Kontakte)
 *    und restrictToDomain (an gleiche Workspace-Domain). Wir mappen den
 *    internalHtml/externalHtml-Contract pragmatisch: internalHtml als Body
 *    fuer Workspace-Empfaenger (restrictToDomain=true Run), externalHtml fuer
 *    Aussen — Gmail unterstuetzt aber nur einen Body pro Vacation. Bewusster
 *    Trade-off: wir nutzen den externalHtml als globalen Body, weil der
 *    sicherer ist (kein internes Detail an Externe). Domain-restrict wird
 *    nicht aktiviert, damit auch externe Mails Antworten erhalten.
 */
final class GoogleOooProvider implements OooProvider
{
    private const SCOPE = 'https://www.googleapis.com/auth/gmail.settings.basic';

    public function __construct(
        private readonly Config $config,
        private readonly GoogleServiceAccountAuth $auth,
        private readonly DevModeLog $log,
        private readonly ClientInterface $http = new Client(['timeout' => 10]),
    ) {
    }

    public function setAutoReply(
        string $userMailbox,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $internalHtml,
        string $externalHtml,
    ): void {
        if (!$this->config->isProduction()) {
            $this->log->write('ooo.log', sprintf(
                'SET_AUTOREPLY user=%s start=%s end=%s',
                $userMailbox,
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
            ));
            return;
        }

        // Wir nutzen externalHtml als Body — siehe Klassen-Kommentar zur
        // Gmail-vs-M365-Mapping-Entscheidung. internalHtml wird ignoriert
        // (oder als Plaintext-Fallback verwendet wenn extern leer).
        $html = $externalHtml !== '' ? $externalHtml : $internalHtml;
        $taggedHtml = $html . AutoReplyMarker::HTML;
        $body = [
            'enableAutoReply' => true,
            'responseSubject' => '',
            'responseBodyHtml' => $taggedHtml,
            'restrictToContacts' => false,
            'restrictToDomain' => false,
            'startTime' => $start->setTime(0, 0)->getTimestamp() * 1000,
            'endTime' => $end->setTime(23, 59, 59)->getTimestamp() * 1000,
        ];

        $token = $this->auth->getAccessToken($userMailbox, [self::SCOPE]);
        try {
            $this->http->request(
                'PUT',
                'https://gmail.googleapis.com/gmail/v1/users/me/settings/vacation',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                    'json' => $body,
                ],
            );
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Gmail Vacation set fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
    }

    public function clearAutoReplyIfOurs(string $userMailbox): void
    {
        if (!$this->config->isProduction()) {
            $this->log->write('ooo.log', sprintf('CLEAR_AUTOREPLY user=%s (dev-mode skip)', $userMailbox));
            return;
        }

        $token = $this->auth->getAccessToken($userMailbox, [self::SCOPE]);
        try {
            $response = $this->http->request(
                'GET',
                'https://gmail.googleapis.com/gmail/v1/users/me/settings/vacation',
                ['headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']],
            );
        } catch (GuzzleException $e) {
            if (str_contains($e->getMessage(), '404')) {
                return;
            }
            throw new \RuntimeException('Gmail Vacation get fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
        $current = json_decode((string) $response->getBody(), true);
        $html = (string) ($current['responseBodyHtml'] ?? '');
        $plain = (string) ($current['responseBodyPlainText'] ?? '');
        $hasMarker = str_contains($html, AutoReplyMarker::HTML)
            || str_contains($plain, AutoReplyMarker::HTML);

        if (!$hasMarker) {
            $this->log->write('ooo.log', sprintf('CLEAR_AUTOREPLY user=%s (skip: no marker, user-modified)', $userMailbox));
            return;
        }

        try {
            $this->http->request(
                'PUT',
                'https://gmail.googleapis.com/gmail/v1/users/me/settings/vacation',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                    'json' => ['enableAutoReply' => false],
                ],
            );
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Gmail Vacation disable fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
    }
}
