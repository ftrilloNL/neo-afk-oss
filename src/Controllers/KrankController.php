<?php declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\I18n\LocalizedDate;
use App\Models\AbsenceRepository;
use App\Models\AuditLogRepository;
use App\Models\UserRepository;
use App\Providers\Contracts\CalendarProvider;
use App\Providers\Contracts\OooProvider;
use App\Services\AbsenceEditService;
use App\Services\MailService;
use App\Services\WerktageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Symfony\Component\Translation\Translator;

final class KrankController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AbsenceRepository $absences,
        private readonly WerktageService $werktage,
        private readonly CalendarProvider $calendar,
        private readonly OooProvider $ooo,
        private readonly MailService $mail,
        private readonly AuditLogRepository $audit,
        private readonly Config $config,
        private readonly AbsenceEditService $editService,
        private readonly Twig $view,
        private readonly Translator $translator,
        private readonly LocalizedDate $dates,
    ) {
    }

    public function neu(Request $request, Response $response): Response
    {
        $userId = (int) $_SESSION['user_id'];
        $user = $this->users->findById($userId);
        if ($user === null) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        return $this->view->render($response, 'krank/neu.twig', [
            'user' => $user,
            'active_nav' => 'krank',
            'today' => date('Y-m-d'),
            'flash_error' => $_SESSION['flash_error'] ?? null,
        ]);
    }

    public function submit(Request $request, Response $response): Response
    {
        unset($_SESSION['flash_error']);
        $userId = (int) $_SESSION['user_id'];
        $user = $this->users->findById($userId);
        if ($user === null) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $startStr = trim((string) ($body['startdatum'] ?? ''));
        $endStr = trim((string) ($body['enddatum'] ?? ''));
        $halbtagStart = (string) ($body['halbtag_start'] ?? 'ganztag');
        $halbtagEnde = (string) ($body['halbtag_ende'] ?? 'ganztag');
        $notiz = trim((string) ($body['notiz'] ?? ''));
        // Google: ein einziges ooo_text-Field, das in beide Spalten gespiegelt wird.
        // Microsoft: getrennte ooo_internal + ooo_external.
        $unifiedOoo = trim((string) ($body['ooo_text'] ?? ''));
        if ($unifiedOoo !== '') {
            $oooInternal = $unifiedOoo;
            $oooExternal = $unifiedOoo;
        } else {
            $oooInternal = trim((string) ($body['ooo_internal'] ?? ''));
            $oooExternal = trim((string) ($body['ooo_external'] ?? ''));
        }

        if ($startStr === '' || $endStr === '') {
            $_SESSION['flash_error'] = $this->translator->trans('flash.krank.required_dates');
            return $response->withHeader('Location', '/krank/neu')->withStatus(302);
        }
        try {
            $start = new \DateTimeImmutable($startStr);
            $end = new \DateTimeImmutable($endStr);
        } catch (\Exception) {
            $_SESSION['flash_error'] = $this->translator->trans('flash.common.invalid_date');
            return $response->withHeader('Location', '/krank/neu')->withStatus(302);
        }
        if ($end < $start) {
            $_SESSION['flash_error'] = $this->translator->trans('flash.common.end_before_start');
            return $response->withHeader('Location', '/krank/neu')->withStatus(302);
        }
        if (!in_array($halbtagStart, ['ganztag', 'nachmittag'], true)) {
            $halbtagStart = 'ganztag';
        }
        if (!in_array($halbtagEnde, ['ganztag', 'vormittag'], true)) {
            $halbtagEnde = 'ganztag';
        }

        $tageGezaehlt = $this->werktage->compute($start, $end, $halbtagStart, $halbtagEnde);

        // DSGVO-konform: Kalender-Subject zeigt nur "Abwesend", nicht "Krank"
        $eventStart = $start;
        $eventEnd = $end->modify('+1 day');
        $subject = $this->translator->trans('calendar.subject.absent', ['%name%' => $user['display_name']]);
        $eventId = $this->calendar->createEvent($subject, $eventStart, $eventEnd, true);

        $absenceId = $this->absences->insert([
            'user_id' => $userId,
            'art' => 'krank',
            'startdatum' => $start->format('Y-m-d'),
            'enddatum' => $end->format('Y-m-d'),
            'halbtag_start' => $halbtagStart,
            'halbtag_ende' => $halbtagEnde,
            'tage_gezaehlt' => $tageGezaehlt,
            'status' => 'aktiv',
            'genehmiger_id' => null,
            'notiz' => $notiz !== '' ? $notiz : null,
            'kalender_event_id' => $eventId,
            'ooo_internal' => $oooInternal !== '' ? $oooInternal : null,
            'ooo_external' => $oooExternal !== '' ? $oooExternal : null,
        ]);

        $this->audit->log(
            $userId,
            'absence.created',
            'absence',
            $absenceId,
            ['art' => 'krank', 'tage_gezaehlt' => $tageGezaehlt]
        );

        // Optionaler Auto-OOO — anders als bei Urlaub OHNE Fallback. Wenn beide Textareas
        // leer waren, wird kein OOO gesetzt. Wenn nur einer ausgefuellt: fuer beide nutzen.
        if ($oooInternal !== '' || $oooExternal !== '') {
            $finalInt = $oooInternal !== '' ? $oooInternal : $oooExternal;
            $finalExt = $oooExternal !== '' ? $oooExternal : $oooInternal;
            $renderOoo = fn (string $plain): string =>
                '<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>';
            try {
                $this->ooo->setAutoReply(
                    (string) $user['email'],
                    $start,
                    $end,
                    $renderOoo($finalInt),
                    $renderOoo($finalExt),
                );
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'KrankController: Auto-OOO fuer %s fehlgeschlagen: %s',
                    $user['email'],
                    $e->getMessage(),
                ));
            }
        }

        // HR-Notification — mail subject + body are translated in AFK-7
        // (mail templates story). Body for now stays via mails/krank-notif.twig.
        $hrEmail = $this->config->get('HR_NOTIFICATION_EMAIL');
        $this->mail->send(
            $hrEmail,
            'mail.krank_notif.subject',
            [
                '%name%' => $user['display_name'],
                '%start%' => $this->dates->monthDay($start),
                '%end%' => $this->dates->short($end),
            ],
            'mails/krank-notif.twig',
            [
                'user' => $user,
                'startdatum' => $start,
                'enddatum' => $end,
                'tage' => $tageGezaehlt,
                'notiz' => $notiz,
            ]
        );

        $_SESSION['flash_success'] = $this->translator->trans('flash.krank.submitted');
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        unset($_SESSION['flash_error']);
        $userId = (int) $_SESSION['user_id'];
        $absenceId = (int) ($args['id'] ?? 0);
        $user = $this->users->findById($userId);
        $absence = $this->absences->findById($absenceId);
        if ($user === null || $absence === null) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        if ($absence['art'] !== 'krank') {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $isOwner = (int) $absence['user_id'] === $userId;
        $isHr = (bool) $user['ist_hr'];
        if (!$isOwner && !$isHr) {
            $_SESSION['flash_error'] = $this->translator->trans('flash.krank.edit.not_authorized');
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        if (in_array($absence['status'], ['storniert', 'abgelehnt'], true)) {
            $_SESSION['flash_error'] = $this->translator->trans(
                'flash.edit.terminal_status_krank',
                ['%status%' => $this->translator->trans('status.' . $absence['status'])]
            );
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $applicant = $isOwner ? $user : $this->users->findById((int) $absence['user_id']);

        return $this->view->render($response, 'krank/edit.twig', [
            'user' => $user,
            'applicant' => $applicant,
            'absence' => $absence,
            'is_hr_edit' => $isHr && !$isOwner,
            'active_nav' => $isHr && !$isOwner ? 'hr' : 'krank',
            'today' => date('Y-m-d'),
            'flash_error' => null,
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        unset($_SESSION['flash_error']);
        $userId = (int) $_SESSION['user_id'];
        $absenceId = (int) ($args['id'] ?? 0);

        $body = (array) ($request->getParsedBody() ?? []);
        $result = $this->editService->editKrank($absenceId, $userId, $body);

        if (!$result['ok']) {
            // editService produces already-translated messages (it injects Translator).
            $_SESSION['flash_error'] = $result['error'] ?? $this->translator->trans('flash.edit.failed');
            return $response->withHeader('Location', '/krank/' . $absenceId . '/edit')->withStatus(302);
        }

        $_SESSION['flash_success'] = $result['success'] ?? $this->translator->trans('flash.krank.updated');
        return $response->withHeader('Location', '/')->withStatus(302);
    }
}
