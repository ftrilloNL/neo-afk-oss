<?php declare(strict_types=1);

namespace App\Services;

use App\Config;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Microsoft-Graph-Client fuer Kalender-Operationen am geteilten Kalender.
 *
 * Target ist eine Shared Mailbox (konfiguriert via GRAPH_CALENDAR_USER) —
 * historisch lief das ueber eine M365-Gruppe,
 * Shared Mailbox war jedoch der bessere Pattern: weniger Microsoft-Quirks,
 * geringere RBAC-Restriktionen.
 *
 * Production: Client-Credentials-Flow (App-only Token) gegen
 * /users/{GRAPH_CALENDAR_USER}/calendar/events. Token wird in-memory pro
 * Request gecached. Anlage: Calendars.ReadWrite (Application Permission)
 * mit Admin-Konsens, siehe docs/entra-id-setup.md.
 *
 * Dev-Mode (APP_ENV != production): schreibt Operationen nach var/logs/graph.log
 * und gibt synthetische Event-IDs (Praefix STUB-) zurueck, damit Folge-Flows
 * (Event-ID speichern, spaeter loeschen) wie in Production durchlaufen koennen
 * ohne dass echte Kalender-Eintraege entstehen.
 */
final class GraphClient
{
    private ?string $cachedToken = null;
    private ?int $tokenExpiresAt = null;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http = new Client(['timeout' => 10]),
    ) {
    }

    public function createCalendarEvent(
        string $subject,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        bool $isAllDay = true,
    ): string {
        if (!$this->config->isProduction()) {
            $eventId = 'STUB-' . bin2hex(random_bytes(8));
            $this->log(sprintf(
                'CREATE_EVENT subject=%s start=%s end=%s allDay=%s -> id=%s',
                $subject,
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
                $isAllDay ? 'yes' : 'no',
                $eventId,
            ));
            return $eventId;
        }

        $mailbox = $this->config->get('GRAPH_CALENDAR_USER');
        $body = [
            'subject' => $subject,
            'isAllDay' => $isAllDay,
            'showAs' => 'oof',
            'start' => [
                'dateTime' => $start->format('Y-m-d\T00:00:00'),
                'timeZone' => 'Europe/Berlin',
            ],
            'end' => [
                'dateTime' => $end->format('Y-m-d\T00:00:00'),
                'timeZone' => 'Europe/Berlin',
            ],
        ];

        $response = $this->graphRequest(
            'POST',
            "/users/{$mailbox}/calendar/events",
            ['json' => $body],
        );
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || !isset($data['id'])) {
            throw new \RuntimeException('Graph create-event: unerwartete Antwort ohne id');
        }
        return (string) $data['id'];
    }

    /**
     * Auto-Reply (Out-of-Office) am Mailbox des Antragstellers fuer den Zeitraum
     * setzen. Marker im Text damit Storno spaeter zuverlaessig nur unsere eigenen
     * Settings zuruecksetzt (und nicht User-eigene OOOs zerstoert).
     *
     * Production: PATCH /users/{mailbox}/mailboxSettings via App-only Token.
     * Permission noetig: MailboxSettings.ReadWrite (Application, Admin-Konsens).
     *
     * Dev-Mode: log in graph.log, kein echter Call.
     */
    public function setAutoReply(
        string $userMailbox,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $internalMessageHtml,
        string $externalMessageHtml,
    ): void {
        if (!$this->config->isProduction()) {
            $this->log(sprintf(
                'SET_AUTOREPLY user=%s start=%s end=%s',
                $userMailbox,
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
            ));
            return;
        }

        $taggedInternal = $internalMessageHtml . self::autoReplyMarker();
        $taggedExternal = $externalMessageHtml . self::autoReplyMarker();

        $body = [
            'automaticRepliesSetting' => [
                'status' => 'scheduled',
                'externalAudience' => 'all',
                'scheduledStartDateTime' => [
                    'dateTime' => $start->format('Y-m-d\T00:00:00'),
                    'timeZone' => 'Europe/Berlin',
                ],
                'scheduledEndDateTime' => [
                    'dateTime' => $end->format('Y-m-d\T23:59:59'),
                    'timeZone' => 'Europe/Berlin',
                ],
                'internalReplyMessage' => $taggedInternal,
                'externalReplyMessage' => $taggedExternal,
            ],
        ];

        $this->graphRequest('PATCH', "/users/{$userMailbox}/mailboxSettings", ['json' => $body]);
    }

    /**
     * Auto-Reply zuruecksetzen — nur wenn unser Marker im aktuellen Text steht.
     * Wenn der User zwischenzeitlich manuell einen anderen OOO gesetzt hat
     * (z.B. fuer einen ueberlappenden Krankheitsfall), lassen wir den unangetastet.
     */
    public function clearAutoReplyIfOurs(string $userMailbox): void
    {
        if (!$this->config->isProduction()) {
            $this->log(sprintf('CLEAR_AUTOREPLY user=%s (dev-mode skip)', $userMailbox));
            return;
        }

        try {
            $response = $this->graphRequest('GET', "/users/{$userMailbox}/mailboxSettings/automaticRepliesSetting");
        } catch (\RuntimeException $e) {
            // 404 = User hat keine MailboxSettings (z.B. Account ohne Postfach).
            if (str_contains($e->getMessage(), '404')) {
                return;
            }
            throw $e;
        }
        $current = json_decode((string) $response->getBody(), true);
        $internalMsg = (string) ($current['internalReplyMessage'] ?? '');

        if (!str_contains($internalMsg, self::autoReplyMarker())) {
            // Nicht unsere — User hat manuell veraendert. Nicht anfassen.
            $this->log(sprintf('CLEAR_AUTOREPLY user=%s (skip: no marker, user-modified)', $userMailbox));
            return;
        }

        $this->graphRequest('PATCH', "/users/{$userMailbox}/mailboxSettings", [
            'json' => [
                'automaticRepliesSetting' => ['status' => 'disabled'],
            ],
        ]);
    }

    /** Marker im OOO-Text (HTML-Kommentar). Sichtbar nur in Source, nicht im Mail-Body. */
    private static function autoReplyMarker(): string
    {
        return '<!-- neo-afk:auto-ooo -->';
    }

    public function deleteCalendarEvent(string $eventId): void
    {
        // STUB-IDs stammen aus Dev-Mode oder aus Daten vor Code-Commit-6.
        // Niemals an Graph weitergeben, sonst 400 BadRequest.
        if (str_starts_with($eventId, 'STUB-')) {
            $this->log(sprintf('DELETE_EVENT id=%s (skip: stub id)', $eventId));
            return;
        }

        if (!$this->config->isProduction()) {
            $this->log(sprintf('DELETE_EVENT id=%s', $eventId));
            return;
        }

        $mailbox = $this->config->get('GRAPH_CALENDAR_USER');
        try {
            $this->graphRequest('DELETE', "/users/{$mailbox}/calendar/events/{$eventId}");
        } catch (\RuntimeException $e) {
            // 404 = Event existiert nicht mehr (z.B. manuell in Outlook geloescht
            // oder vorheriger Delete teilweise durchgegangen). Idempotent behandeln.
            if (str_contains($e->getMessage(), '404')) {
                $this->log(sprintf('DELETE_EVENT id=%s (already gone, 404)', $eventId));
                return;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function graphRequest(string $method, string $path, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $this->getAppToken(),
            'Accept' => 'application/json',
        ]);

        try {
            return $this->http->request($method, 'https://graph.microsoft.com/v1.0' . $path, $options);
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "Graph {$method} {$path} fehlgeschlagen: " . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    private function getAppToken(): string
    {
        if ($this->cachedToken !== null
            && $this->tokenExpiresAt !== null
            && $this->tokenExpiresAt > time() + 60
        ) {
            return $this->cachedToken;
        }

        $tenant = $this->config->get('OAUTH_TENANT_ID');
        try {
            $response = $this->http->request(
                'POST',
                "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
                [
                    'form_params' => [
                        'client_id' => $this->config->get('OAUTH_CLIENT_ID'),
                        'client_secret' => $this->config->get('OAUTH_CLIENT_SECRET'),
                        'scope' => 'https://graph.microsoft.com/.default',
                        'grant_type' => 'client_credentials',
                    ],
                ],
            );
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Graph App-Token-Request fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || !isset($data['access_token'])) {
            throw new \RuntimeException('Graph App-Token-Response ohne access_token');
        }

        $this->cachedToken = (string) $data['access_token'];
        $this->tokenExpiresAt = time() + (int) ($data['expires_in'] ?? 3600);
        return $this->cachedToken;
    }

    private function log(string $line): void
    {
        $dir = dirname(__DIR__, 2) . '/var/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $dir . '/graph.log',
            sprintf("[%s] %s\n", date('c'), $line),
            FILE_APPEND,
        );
    }
}
