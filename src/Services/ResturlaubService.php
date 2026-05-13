<?php declare(strict_types=1);

namespace App\Services;

use App\Models\UserRepository;

final class ResturlaubService
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * Bucht Tage vom Resturlaub des Users ab — zuerst Vorjahr, dann Aktuell.
     *
     * @param array<string, mixed> $user
     * @return array{vorjahr_used: float, aktuell_used: float}
     */
    public function deductFromUser(array $user, float $tage): array
    {
        $verfuegbarVorjahr = (float) $user['resturlaub_vorjahr'];
        $vorjahrUsed = min($tage, $verfuegbarVorjahr);
        $aktuellUsed = $tage - $vorjahrUsed;

        $this->users->applyResturlaubChange((int) $user['id'], -$vorjahrUsed, -$aktuellUsed);

        return ['vorjahr_used' => $vorjahrUsed, 'aktuell_used' => $aktuellUsed];
    }

    /**
     * Bucht stornierte Tage zurueck — bewusst nur auf Aktuell, nicht auf Vorjahr
     * (siehe architecture.md § 4 Storno).
     */
    public function refundToAktuell(int $userId, float $tage): void
    {
        $this->users->applyResturlaubChange($userId, 0.0, $tage);
    }

    /**
     * Anteiliger Jahresurlaub fuer unterjaehrig eintretende Mitarbeiter.
     *
     * Konvention:
     *  - Eintritt am 1. eines Monats     -> dieser Monat zaehlt voll mit
     *  - Eintritt mid-month              -> erst ab dem Folgemonat 1/12
     *  - Rundung: auf halbe Tage (Schema DECIMAL(4,1))
     *  - Eintritt vor referenz-Jahr      -> voller Anspruch
     *  - Eintritt erst im naechsten Jahr -> 0
     *
     * Beispiele bei jahresanspruch=30:
     *   Eintritt 01.01. -> 30      Eintritt 15.07. -> 12,5
     *   Eintritt 01.07. -> 15      Eintritt 01.12. -> 2,5
     *   Eintritt 15.12. -> 0
     */
    public function berechneAnteiligenJahresanspruch(
        int $jahresanspruch,
        ?\DateTimeImmutable $eintritt,
        ?\DateTimeImmutable $referenz = null,
    ): float {
        if ($eintritt === null) {
            return (float) $jahresanspruch;
        }
        $referenz ??= new \DateTimeImmutable('now');

        $eintrittsJahr = (int) $eintritt->format('Y');
        $referenzJahr = (int) $referenz->format('Y');

        if ($eintrittsJahr < $referenzJahr) {
            return (float) $jahresanspruch;
        }
        if ($eintrittsJahr > $referenzJahr) {
            return 0.0;
        }

        $tag = (int) $eintritt->format('d');
        $monat = (int) $eintritt->format('m');
        $startMonat = $tag === 1 ? $monat : $monat + 1;

        if ($startMonat > 12) {
            return 0.0;
        }

        $vollendeteMonate = 13 - $startMonat;
        $anteil = $jahresanspruch * $vollendeteMonate / 12.0;
        return round($anteil * 2) / 2;
    }
}
