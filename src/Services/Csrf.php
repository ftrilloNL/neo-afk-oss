<?php declare(strict_types=1);

namespace App\Services;

/**
 * Session-gebundenes CSRF-Synchronizer-Token-Pattern.
 *
 * Token wird einmal pro Session generiert (bin2hex(random_bytes(32)) = 64 hex chars)
 * und in $_SESSION persistiert. Bei jedem schutzbeduerftigen POST muss das Token
 * im Body mitgeschickt werden — typischerweise als hidden field `_csrf`.
 *
 * Bewusst keine Token-Rotation pro Request: einfacher fuer mehrere Browser-Tabs
 * und Browser-Back-Navigation, ausreichend solange Session und Token zusammen
 * bei Logout invalidiert werden.
 */
final class Csrf
{
    private const SESSION_KEY = 'csrf_token';
    private const FIELD_NAME = '_csrf';

    public function token(): string
    {
        $current = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($current) || strlen($current) !== 64) {
            $current = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $current;
        }
        return $current;
    }

    public function fieldName(): string
    {
        return self::FIELD_NAME;
    }

    public function validate(?string $submitted): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($expected) || $submitted === null) {
            return false;
        }
        return hash_equals($expected, $submitted);
    }
}
