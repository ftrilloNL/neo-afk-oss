<?php declare(strict_types=1);

namespace App\Providers\Microsoft;

use App\Config;
use App\Providers\Contracts\CalendarProvider;
use App\Support\DevModeLog;

/**
 * Microsoft Graph Calendar-API. Target ist eine Shared Mailbox (GRAPH_CALENDAR_USER).
 * App-only mit Calendars.ReadWrite (Application, Admin-Konsens) — siehe
 * docs/entra-id-setup.md.
 *
 * Dev-Mode (APP_ENV != production): schreibt nach var/logs/calendar.log und gibt
 * STUB-Event-IDs zurueck. Storno-/Delete-Flows behandeln STUB-IDs idempotent.
 */
final class MicrosoftCalendarProvider implements CalendarProvider
{
    public function __construct(
        private readonly Config $config,
        private readonly MicrosoftGraphHttp $http,
        private readonly DevModeLog $log,
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

        $response = $this->http->request('POST', "/users/{$mailbox}/calendar/events", ['json' => $body]);
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || !isset($data['id'])) {
            throw new \RuntimeException('Graph create-event: unerwartete Antwort ohne id');
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

        $mailbox = $this->config->get('GRAPH_CALENDAR_USER');
        try {
            $this->http->request('DELETE', "/users/{$mailbox}/calendar/events/{$eventId}");
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), '404')) {
                $this->log->write('calendar.log', sprintf('DELETE_EVENT id=%s (already gone, 404)', $eventId));
                return;
            }
            throw $e;
        }
    }
}
