<?php declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserMasterDataRepository;
use App\Models\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Read-only Profil-Seite fuer den eingeloggten User. Zeigt eigene Urlaubs-Daten
 * (Anspruch, Resturlaub) und persoenliche Stammdaten — alles editierbar nur
 * durch HR via /hr/users/{id}/edit.
 *
 * OOO-Texte werden pro Urlaubsantrag im Antrags-Form gesetzt, nicht zentral
 * vorgehalten — die relevanten Daten (Zeitraum, Vertretung) variieren ohnehin
 * pro Antrag.
 */
final class ProfilController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserMasterDataRepository $masterData,
        private readonly Twig $view,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = (int) $_SESSION['user_id'];
        $user = $this->users->findById($userId);
        $master = $this->masterData->findByUserId($userId) ?? [];

        return $this->view->render($response, 'profil/index.twig', [
            'user' => $user,
            'active_nav' => 'profil',
            'master' => $master,
        ]);
    }
}
