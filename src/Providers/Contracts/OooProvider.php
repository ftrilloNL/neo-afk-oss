<?php declare(strict_types=1);

namespace App\Providers\Contracts;

/**
 * Out-of-Office / Auto-Reply am User-Postfach. Eine Implementierung pro Backend.
 *
 * - Microsoft: PATCH /users/{mailbox}/mailboxSettings
 * - Google: gmail.users.settings.updateVacation (Service Account mit DWD impersonating $userMailbox)
 *
 * Beide Implementierungen muessen einen Marker im Internal-Reply-Text einbetten,
 * damit clearAutoReplyIfOurs() erkennt ob wir den OOO gesetzt haben oder der User
 * selbst (dann nicht ueberschreiben).
 */
interface OooProvider
{
    public function setAutoReply(
        string $userMailbox,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $internalHtml,
        string $externalHtml,
    ): void;

    public function clearAutoReplyIfOurs(string $userMailbox): void;
}
