<?php declare(strict_types=1);

namespace App\Support;

/**
 * Marker im OOO-Text (HTML-Kommentar). Sichtbar nur in Source, nicht im Mail-Body.
 * Wird sowohl beim Setzen eingebettet als auch beim Clearing geprueft — wenn der
 * Marker fehlt, hat der User selbst einen anderen OOO gesetzt und wir lassen ihn
 * unangetastet.
 */
final class AutoReplyMarker
{
    public const HTML = '<!-- neo-afk:auto-ooo -->';
}
