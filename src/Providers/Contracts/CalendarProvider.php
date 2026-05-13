<?php declare(strict_types=1);

namespace App\Providers\Contracts;

/**
 * Geteilter Abwesenheits-Kalender. Eine Implementierung pro Backend.
 *
 * - Microsoft: Events auf der Shared Mailbox (GRAPH_CALENDAR_USER)
 * - Google: Events auf einem geteilten Workspace-Kalender (GOOGLE_CALENDAR_ID)
 */
interface CalendarProvider
{
    /**
     * Legt einen All-Day- oder Zeitraum-Event an. Returns die provider-spezifische
     * Event-ID zum spaeteren Loeschen (z.B. via Storno).
     */
    public function createEvent(
        string $subject,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        bool $isAllDay,
    ): string;

    /**
     * Loescht einen Event idempotent — 404 wird silent als Erfolg behandelt.
     */
    public function deleteEvent(string $eventId): void;
}
