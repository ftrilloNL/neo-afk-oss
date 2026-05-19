<?php declare(strict_types=1);

namespace App\I18n;

use Symfony\Component\Translation\Translator;

/**
 * Catalog-basierte Datums-Formatierung — Ersatz fuer `IntlDateFormatter`,
 * das auf Hetzner ohne `ext-intl` nicht verfuegbar ist.
 *
 * Monatsnamen und Format-Pattern liegen im Translation-Catalog
 * (`month.1..12`, `date.format.short`, `date.format.month_day`,
 * `date.format.month_year`). Helper liefert nur den Lookup +
 * `DateTimeInterface::format()`-Aufruf.
 */
final class LocalizedDate
{
    public function __construct(
        private readonly Translator $translator,
    ) {
    }

    public function short(\DateTimeInterface $d): string
    {
        return $d->format($this->translator->trans('date.format.short'));
    }

    public function monthDay(\DateTimeInterface $d): string
    {
        return $d->format($this->translator->trans('date.format.month_day'));
    }

    public function shortWithTime(\DateTimeInterface $d): string
    {
        return $d->format($this->translator->trans('date.format.short_with_time'));
    }

    public function monthYear(\DateTimeInterface $d): string
    {
        return $this->translator->trans('date.format.month_year', [
            '%month%' => $this->translator->trans('month.' . (int) $d->format('n')),
            '%year%' => $d->format('Y'),
        ]);
    }
}
