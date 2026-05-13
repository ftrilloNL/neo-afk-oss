<?php declare(strict_types=1);

namespace App\Providers\Microsoft;

use App\Config;
use App\Providers\Contracts\OooProvider;
use App\Support\AutoReplyMarker;
use App\Support\DevModeLog;

/**
 * Microsoft Graph MailboxSettings — Out-of-Office am User-Postfach.
 * App-only mit MailboxSettings.ReadWrite (Application, Admin-Konsens).
 */
final class MicrosoftOooProvider implements OooProvider
{
    public function __construct(
        private readonly Config $config,
        private readonly MicrosoftGraphHttp $http,
        private readonly DevModeLog $log,
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
                'internalReplyMessage' => $internalHtml . AutoReplyMarker::HTML,
                'externalReplyMessage' => $externalHtml . AutoReplyMarker::HTML,
            ],
        ];

        $this->http->request('PATCH', "/users/{$userMailbox}/mailboxSettings", ['json' => $body]);
    }

    public function clearAutoReplyIfOurs(string $userMailbox): void
    {
        if (!$this->config->isProduction()) {
            $this->log->write('ooo.log', sprintf('CLEAR_AUTOREPLY user=%s (dev-mode skip)', $userMailbox));
            return;
        }

        try {
            $response = $this->http->request('GET', "/users/{$userMailbox}/mailboxSettings/automaticRepliesSetting");
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), '404')) {
                return;
            }
            throw $e;
        }
        $current = json_decode((string) $response->getBody(), true);
        $internalMsg = (string) ($current['internalReplyMessage'] ?? '');

        if (!str_contains($internalMsg, AutoReplyMarker::HTML)) {
            $this->log->write('ooo.log', sprintf('CLEAR_AUTOREPLY user=%s (skip: no marker, user-modified)', $userMailbox));
            return;
        }

        $this->http->request('PATCH', "/users/{$userMailbox}/mailboxSettings", [
            'json' => ['automaticRepliesSetting' => ['status' => 'disabled']],
        ]);
    }
}
