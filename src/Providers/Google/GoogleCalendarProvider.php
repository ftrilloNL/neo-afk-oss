<?php declare(strict_types=1);

namespace App\Providers\Google;

use App\Config;
use App\Providers\Contracts\CalendarProvider;
use App\Support\DevModeLog;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Google Calendar API. Schreibt Events in einen geteilten Workspace-Kalender
 * (GOOGLE_CALENDAR_ID), impersoniert dafuer einen Service-User
 * (GOOGLE_CALENDAR_OWNER) via Service-Account-DWD.
 *
 * Scope: https://www.googleapis.com/auth/calendar
 *
 * Wichtig zu Google's Datenformat: bei all-day-Events ist `end.date` exklusiv
 * — fuer einen Event 5.–6. Mai muss end.date = 7.5. sein. Wir bekommen aber
 * inklusiv-end vom Aufrufer, also +1 Tag fuer den API-Call.
 */
final class GoogleCalendarProvider implements CalendarProvider
{
    private const SCOPE = 'https://www.googleapis.com/auth/calendar';

    public function __construct(
        private readonly Config $config,
        private readonly GoogleServiceAccountAuth $auth,
        private readonly DevModeLog $log,
        private readonly ClientInterface $http = new Client(['timeout' => 10]),
    ) {
    }

    public function createEvent(
        string $subject,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        bool $isAllDay,
    ): string {
        if (!$this->config->isProduction()) {
            $eventId = 'STUB-' . bin2hex(random_bytes(8));
            $this->log->write('calendar.log', sprintf(
                'CREATE_EVENT subject=%s start=%s end=%s allDay=%s -> id=%s',
                $subject,
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
                $isAllDay ? 'yes' : 'no',
                $eventId,
            ));
            return $eventId;
        }

        $calendarId = $this->config->get('GOOGLE_CALENDAR_ID');
        $body = [
            'summary' => $subject,
            'transparency' => 'opaque',
            'visibility' => 'default',
        ];
        if ($isAllDay) {
            $body['start'] = ['date' => $start->format('Y-m-d')];
            // Google all-day end ist exklusiv → +1 Tag
            $body['end'] = ['date' => $end->modify('+1 day')->format('Y-m-d')];
        } else {
            $body['start'] = ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => 'Europe/Berlin'];
            $body['end'] = ['dateTime' => $end->format('Y-m-d\TH:i:s'), 'timeZone' => 'Europe/Berlin'];
        }

        $token = $this->auth->getAccessToken(
            $this->config->get('GOOGLE_CALENDAR_OWNER'),
            [self::SCOPE],
        );
        try {
            $response = $this->http->request(
                'POST',
                sprintf('https://www.googleapis.com/calendar/v3/calendars/%s/events', rawurlencode($calendarId)),
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                    'json' => $body,
                ],
            );
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Google Calendar create-event fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || !isset($data['id'])) {
            throw new \RuntimeException('Google Calendar create-event: Antwort ohne id');
        }
        return (string) $data['id'];
    }

    public function deleteEvent(string $eventId): void
    {
        if (str_starts_with($eventId, 'STUB-')) {
            $this->log->write('calendar.log', sprintf('DELETE_EVENT id=%s (skip: stub id)', $eventId));
            return;
        }
        if (!$this->config->isProduction()) {
            $this->log->write('calendar.log', sprintf('DELETE_EVENT id=%s', $eventId));
            return;
        }

        $calendarId = $this->config->get('GOOGLE_CALENDAR_ID');
        $token = $this->auth->getAccessToken(
            $this->config->get('GOOGLE_CALENDAR_OWNER'),
            [self::SCOPE],
        );
        try {
            $this->http->request(
                'DELETE',
                sprintf(
                    'https://www.googleapis.com/calendar/v3/calendars/%s/events/%s',
                    rawurlencode($calendarId),
                    rawurlencode($eventId),
                ),
                ['headers' => ['Authorization' => 'Bearer ' . $token]],
            );
        } catch (GuzzleException $e) {
            // 404 / 410 (gone) idempotent behandeln
            $msg = $e->getMessage();
            if (str_contains($msg, '404') || str_contains($msg, '410')) {
                $this->log->write('calendar.log', sprintf('DELETE_EVENT id=%s (already gone)', $eventId));
                return;
            }
            throw new \RuntimeException('Google Calendar delete-event fehlgeschlagen: ' . $msg, 0, $e);
        }
    }
}
