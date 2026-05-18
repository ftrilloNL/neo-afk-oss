<?php declare(strict_types=1);

namespace App\I18n;

use Negotiation\BaseAccept;
use Negotiation\LanguageNegotiator;

/**
 * Resolviert die UI-Locale aus dem Accept-Language-Header gegen die im
 * `Config::supportedLocales()` deklarierte Liste. Wrapper um willdurand/
 * negotiation, damit die Library hinter einer einzigen Klasse austauschbar
 * bleibt und LocaleMiddleware nur eine schmale Schnittstelle kennt.
 *
 * Fallback: bei leerem Header, bei ungueltigem Header oder wenn keine
 * gewuenschte Sprache unterstuetzt wird, gilt der uebergebene `$fallback`
 * (typischerweise 'en' -- siehe Epic AFK-1).
 */
final class LocaleResolver
{
    /**
     * @param list<string> $supported
     */
    public function resolve(string $acceptLanguageHeader, array $supported, string $fallback): string
    {
        if (trim($acceptLanguageHeader) === '') {
            return $fallback;
        }
        try {
            $best = (new LanguageNegotiator())->getBest($acceptLanguageHeader, $supported);
        } catch (\Throwable) {
            return $fallback;
        }
        // getBest() ist mit `AcceptHeader|null` typisiert (Interface).
        // Konkret kommt aber immer ein `BaseAccept`-Subklassen-Objekt zurueck,
        // das `getValue()` hat -- diesen Narrowing-Schritt verlangt PHPStan
        // explizit.
        if (! $best instanceof BaseAccept) {
            return $fallback;
        }
        $value = $best->getValue();
        return in_array($value, $supported, true) ? $value : $fallback;
    }
}
