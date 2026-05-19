<?php declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\Models\AuditLogRepository;
use App\Models\UserMasterDataRepository;
use App\Models\UserRepository;
use App\Services\ResturlaubService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Symfony\Component\Translation\Translator;

/**
 * HR-Stammdaten-Pflege: Liste aller User, Editieren von Anspruch, Resturlaub,
 * Rollen, Aktiv-Flag, Eintrittsdatum.
 *
 * Gating laeuft via HrMiddleware (siehe App.php). Audit-Log mit Vorher/Nachher
 * jedem Update damit Resturlaub-Anpassungen nachvollziehbar bleiben (Mitarbeiter
 * sehen ihren Stand auf der Uebersicht und werden fragen, falls's anders aussieht
 * als beim Letzten Mal).
 */
final class HrUsersController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserMasterDataRepository $masterData,
        private readonly AuditLogRepository $audit,
        private readonly ResturlaubService $resturlaub,
        private readonly Connection $db,
        private readonly Twig $view,
        private readonly Translator $translator,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $callerId = (int) $_SESSION['user_id'];
        $caller = $this->users->findById($callerId);

        $allUsers = $this->users->listAll();

        return $this->view->render($response, 'hr/users/index.twig', [
            'user' => $caller,
            'active_nav' => 'hr-users',
            'all_users' => $allUsers,
            'flash' => $_SESSION['flash'] ?? null,
        ] + $this->consumeFlash());
    }

    public function newForm(Request $request, Response $response): Response
    {
        $callerId = (int) $_SESSION['user_id'];
        $caller = $this->users->findById($callerId);

        $form = $_SESSION['form_data'] ?? null;
        $vorschlag = null;
        if (is_array($form) && !empty($form['eintrittsdatum']) && !empty($form['jahresanspruch'])) {
            try {
                $eintritt = new \DateTimeImmutable((string) $form['eintrittsdatum']);
                $vorschlag = $this->resturlaub->berechneAnteiligenJahresanspruch(
                    (int) $form['jahresanspruch'],
                    $eintritt,
                );
            } catch (\Exception) {
                // Ungueltiges Datum — kein Vorschlag
            }
        }

        return $this->view->render($response, 'hr/users/new.twig', [
            'user' => $caller,
            'active_nav' => 'hr-users',
            'errors' => $_SESSION['form_errors'] ?? [],
            'form' => $form,
            'vorschlag_anteilig' => $vorschlag,
            'similar_users' => $_SESSION['form_similar_users'] ?? [],
        ] + $this->consumeForm());
    }

    public function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $errors = [];

        $displayName = trim((string) ($body['display_name'] ?? ''));
        if ($displayName === '') {
            $errors['display_name'] = $this->translator->trans('flash.hr.users.display_name_required');
        }

        $email = trim((string) ($body['email'] ?? ''));
        if ($email === '') {
            $errors['email'] = $this->translator->trans('flash.hr.users.email_required');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = $this->translator->trans('flash.hr.users.email_invalid');
        } elseif ($this->users->findByEmail($email) !== null) {
            $errors['email'] = $this->translator->trans('flash.hr.users.email_duplicate');
        }

        $eintritt = (string) ($body['eintrittsdatum'] ?? '');
        $eintrittClean = null;
        if ($eintritt !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eintritt)) {
                $errors['eintrittsdatum'] = $this->translator->trans('flash.hr.users.date_invalid');
            } else {
                $eintrittClean = $eintritt;
            }
        }

        $jahresanspruch = (int) ($body['jahresanspruch'] ?? -1);
        if ($jahresanspruch < 0 || $jahresanspruch > 99) {
            $errors['jahresanspruch'] = $this->translator->trans('flash.hr.users.jahresanspruch_range');
        }

        $aktuell = $this->parseDecimal($body['resturlaub_aktuell'] ?? '', $errors, 'resturlaub_aktuell');
        $vorjahr = $this->parseDecimal($body['resturlaub_vorjahr'] ?? '', $errors, 'resturlaub_vorjahr');

        if (!empty($errors)) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_data'] = $body;
            return $response->withHeader('Location', '/hr/users/new')->withStatus(302);
        }

        // Aehnliche E-Mail-Adressen finden (z.B. flavio@ wenn flavio.trillo@ existiert).
        // Wenn HR sie bewusst ignorieren will, setzt sie die "override_similarity"-Checkbox.
        if (empty($body['override_similarity'])) {
            $similar = $this->users->findSimilarByEmail($email);
            if (!empty($similar)) {
                $_SESSION['form_data'] = $body;
                $_SESSION['form_similar_users'] = $similar;
                return $response->withHeader('Location', '/hr/users/new')->withStatus(302);
            }
        }

        // Wenn HR die "Anteilig berechnen"-Checkbox aktiv gelassen hat UND ein
        // Eintrittsdatum gesetzt ist, ersetzen wir resturlaub_aktuell durch die
        // anteilige Berechnung — HR-Eingabe wird in diesem Fall ueberschrieben.
        $anteiligGenutzt = false;
        if (!empty($body['anteilig_berechnen']) && $eintrittClean !== null) {
            $aktuell = $this->resturlaub->berechneAnteiligenJahresanspruch(
                $jahresanspruch,
                new \DateTimeImmutable($eintrittClean),
            );
            $anteiligGenutzt = true;
        }

        $newId = $this->users->createPreUser([
            'display_name' => $displayName,
            'email' => $email,
            'job_title' => $this->cleanString($body['job_title'] ?? null),
            'eintrittsdatum' => $eintrittClean,
            'jahresanspruch' => $jahresanspruch,
            'resturlaub_aktuell' => $aktuell,
            'resturlaub_vorjahr' => $vorjahr,
            'ist_aktiv' => !empty($body['ist_aktiv']),
            'ist_genehmiger' => !empty($body['ist_genehmiger']),
            'ist_hr' => !empty($body['ist_hr']),
        ]);

        $this->audit->log(
            (int) $_SESSION['user_id'],
            'user.pre_created',
            'user',
            $newId,
            [
                'email' => $email,
                'display_name' => $displayName,
                'jahresanspruch' => $jahresanspruch,
                'resturlaub_aktuell' => $aktuell,
                'resturlaub_vorjahr' => $vorjahr,
                'anteilig_berechnet' => $anteiligGenutzt,
            ],
        );

        $_SESSION['flash'] = $this->translator->trans(
            'flash.hr.users.created',
            ['%name%' => $displayName]
        );
        return $response->withHeader('Location', '/hr/users')->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $callerId = (int) $_SESSION['user_id'];
        $caller = $this->users->findById($callerId);

        $editId = (int) $args['id'];
        $target = $this->users->findById($editId);
        if ($target === null) {
            return $response->withHeader('Location', '/hr/users')->withStatus(302);
        }

        $master = $this->masterData->findByUserId($editId) ?? [];

        return $this->view->render($response, 'hr/users/edit.twig', [
            'user' => $caller,
            'active_nav' => 'hr-users',
            'target' => $target,
            'master' => $master,
            'errors' => $_SESSION['form_errors'] ?? [],
            'form' => $_SESSION['form_data'] ?? null,
        ] + $this->consumeForm());
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $editId = (int) $args['id'];
        $target = $this->users->findById($editId);
        if ($target === null) {
            return $response->withHeader('Location', '/hr/users')->withStatus(302);
        }

        $body = (array) $request->getParsedBody();
        $errors = [];

        $eintritt = (string) ($body['eintrittsdatum'] ?? '');
        $eintrittClean = null;
        if ($eintritt !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eintritt)) {
                $errors['eintrittsdatum'] = $this->translator->trans('flash.hr.users.date_invalid');
            } else {
                $eintrittClean = $eintritt;
            }
        }

        $jahresanspruch = (int) ($body['jahresanspruch'] ?? -1);
        if ($jahresanspruch < 0 || $jahresanspruch > 99) {
            $errors['jahresanspruch'] = $this->translator->trans('flash.hr.users.jahresanspruch_range');
        }

        $aktuell = $this->parseDecimal($body['resturlaub_aktuell'] ?? '', $errors, 'resturlaub_aktuell');
        $vorjahr = $this->parseDecimal($body['resturlaub_vorjahr'] ?? '', $errors, 'resturlaub_vorjahr');

        $geburt = trim((string) ($body['geburtsdatum'] ?? ''));
        $geburtClean = null;
        if ($geburt !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $geburt)) {
                $errors['geburtsdatum'] = $this->translator->trans('flash.hr.users.date_invalid');
            } else {
                $geburtClean = $geburt;
            }
        }

        if (!empty($errors)) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_data'] = $body;
            return $response->withHeader('Location', "/hr/users/{$editId}/edit")->withStatus(302);
        }

        $istAktiv = !empty($body['ist_aktiv']);
        $istGenehmiger = !empty($body['ist_genehmiger']);
        $istHr = !empty($body['ist_hr']);

        $newData = [
            'job_title' => $this->cleanString($body['job_title'] ?? null),
            'eintrittsdatum' => $eintrittClean,
            'jahresanspruch' => $jahresanspruch,
            'resturlaub_aktuell' => $aktuell,
            'resturlaub_vorjahr' => $vorjahr,
            'ist_aktiv' => $istAktiv,
            'ist_genehmiger' => $istGenehmiger,
            'ist_hr' => $istHr,
        ];

        $masterDataNew = [
            'geburtsdatum' => $geburtClean,
            'telefon' => $this->cleanString($body['telefon'] ?? null),
            'strasse' => $this->cleanString($body['strasse'] ?? null),
            'plz' => $this->cleanString($body['plz'] ?? null),
            'ort' => $this->cleanString($body['ort'] ?? null),
        ];

        // Atomic: users + user_master_data + audit_log
        $dbal = $this->db->dbal();
        $dbal->beginTransaction();
        try {
            $this->users->updateStammdaten($editId, $newData);
            $this->masterData->upsert($editId, $masterDataNew);
            $this->audit->log(
                (int) $_SESSION['user_id'],
                'user.stammdaten_updated',
                'user',
                $editId,
                [
                    'before' => $this->diffSubset($target),
                    'after' => $newData,
                    'master_data' => $masterDataNew,
                ],
            );
            $dbal->commit();
        } catch (\Throwable $e) {
            $dbal->rollBack();
            throw $e;
        }

        $_SESSION['flash'] = $this->translator->trans('flash.hr.users.updated', ['%name%' => $target['display_name']]);
        return $response->withHeader('Location', '/hr/users')->withStatus(302);
    }

    private function cleanString(mixed $raw): ?string
    {
        $s = trim((string) ($raw ?? ''));
        return $s === '' ? null : $s;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function diffSubset(array $row): array
    {
        return [
            'job_title' => $row['job_title'] ?? null,
            'eintrittsdatum' => $row['eintrittsdatum'] ?? null,
            'jahresanspruch' => (int) $row['jahresanspruch'],
            'resturlaub_aktuell' => (float) $row['resturlaub_aktuell'],
            'resturlaub_vorjahr' => (float) $row['resturlaub_vorjahr'],
            'ist_aktiv' => (bool) $row['ist_aktiv'],
            'ist_genehmiger' => (bool) $row['ist_genehmiger'],
            'ist_hr' => (bool) $row['ist_hr'],
        ];
    }

    /** @param array<string, string> $errors */
    private function parseDecimal(mixed $raw, array &$errors, string $field): float
    {
        $str = trim((string) $raw);
        // Akzeptiere sowohl 21.5 als auch 21,5 — deutsche Tastatur.
        $normalized = str_replace(',', '.', $str);
        if ($normalized === '' || !is_numeric($normalized)) {
            $errors[$field] = $this->translator->trans('flash.hr.users.decimal_invalid');
            return 0.0;
        }
        $val = (float) $normalized;
        if ($val < 0 || $val > 999.9) {
            $errors[$field] = $this->translator->trans('flash.hr.users.decimal_range');
            return 0.0;
        }
        return $val;
    }

    /** @return array{flash: ?string} */
    private function consumeFlash(): array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return ['flash' => $flash];
    }

    /** @return array{} */
    private function consumeForm(): array
    {
        unset($_SESSION['form_errors'], $_SESSION['form_data'], $_SESSION['form_similar_users']);
        return [];
    }
}
