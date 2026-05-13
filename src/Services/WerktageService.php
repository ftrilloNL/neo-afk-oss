<?php declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Models\FeiertagRepository;

final class WerktageService
{
    public function __construct(
        private readonly FeiertagRepository $feiertage,
        private readonly Config $config,
    ) {
    }

    /**
     * Berechnet Werktage zwischen Start und Ende inkl., minus Feiertage des
     * konfigurierten Bundeslands (ORG_FEIERTAGE_BUNDESLAND), minus Halbtags-
     * Korrekturen.
     *
     * @param string $halbtagStart 'ganztag' | 'nachmittag'
     * @param string $halbtagEnde  'ganztag' | 'vormittag'
     */
    public function compute(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $halbtagStart,
        string $halbtagEnde,
    ): float {
        if ($end < $start) {
            return 0.0;
        }

        $bundesland = $this->config->org()['feiertage_bundesland'];
        $startYear = (int) $start->format('Y');
        $endYear = (int) $end->format('Y');
        $feiertage = $this->feiertage->listDates($startYear, $endYear, $bundesland);

        $tage = 0.0;
        $current = $start;
        while ($current <= $end) {
            $weekday = (int) $current->format('N'); // 1=Mo .. 7=So
            $dateStr = $current->format('Y-m-d');
            if ($weekday <= 5 && !in_array($dateStr, $feiertage, true)) {
                $tage += 1.0;
            }
            $current = $current->modify('+1 day');
        }

        if ($halbtagStart === 'nachmittag') {
            $tage -= 0.5;
        }
        if ($halbtagEnde === 'vormittag') {
            $tage -= 0.5;
        }

        return max(0.0, $tage);
    }
}
