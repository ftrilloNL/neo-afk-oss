<?php declare(strict_types=1);

namespace App;

final class Config
{
    public function get(string $key, ?string $default = null): string
    {
        $value = $_ENV[$key] ?? $default;
        if ($value === null) {
            throw new \RuntimeException("Missing required env: {$key}");
        }
        return (string) $value;
    }

    public function appUrl(): string
    {
        return rtrim($this->get('APP_URL'), '/');
    }

    public function isProduction(): bool
    {
        return $this->get('APP_ENV', 'development') === 'production';
    }

    /**
     * Org-Settings fuer das gehostete Branding. Werden als Twig-Global verfuegbar.
     * Defaults sind generische Beispielwerte — bei der eigenen Instanz via .env ueberschreiben.
     *
     * @return array{
     *     name: string,
     *     short_name: string,
     *     legal_name: string,
     *     logo_url: string,
     *     app_url: string,
     *     default_jahresanspruch: int,
     *     feiertage_bundesland: string,
     * }
     */
    public function org(): array
    {
        return [
            'name' => $this->get('ORG_NAME', 'afk'),
            'short_name' => $this->get('ORG_SHORT_NAME', 'afk'),
            'legal_name' => $this->get('ORG_LEGAL_NAME', 'Your Company GmbH'),
            'logo_url' => $this->get('ORG_LOGO_URL', '/assets/logo.svg'),
            'support_email' => $this->get('ORG_SUPPORT_EMAIL', 'support@example.com'),
            'accent_color' => $this->get('ORG_ACCENT_COLOR_HEX', '#3b82f6'),
            'app_url' => $this->appUrl(),
            'default_jahresanspruch' => (int) $this->get('ORG_DEFAULT_JAHRESANSPRUCH', '30'),
            // ISO-3166-2:DE-Code (BE, BY, HH, ...). Tabelle feiertage hat
            // diese Codes als Filter-Spalte. Seed-Files in migrations/seeds/.
            'feiertage_bundesland' => $this->get('ORG_FEIERTAGE_BUNDESLAND', 'BE'),
        ];
    }
}
