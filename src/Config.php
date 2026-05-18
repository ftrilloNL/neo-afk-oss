<?php declare(strict_types=1);

namespace App;

final class Config
{
    /**
     * Repo-Default fuer die UI-Sprache. OSS-Repo: 'en'. Privates Repo: 'de'.
     * Wird durch DEFAULT_LOCALE env-Var ueberschrieben, falls gesetzt.
     */
    private const REPO_DEFAULT_LOCALE = 'en';

    /**
     * UI-Sprachen, fuer die `translations/messages.<locale>.po` existiert.
     * Erweitern um z.B. 'fr' braucht NUR einen neuen .po-File + Eintrag hier.
     *
     * @var list<string>
     */
    private const SUPPORTED_LOCALES = ['de', 'en'];

    public function get(string $key, ?string $default = null): string
    {
        $value = $_ENV[$key] ?? $default;
        if ($value === null) {
            throw new \RuntimeException("Missing required env: {$key}");
        }
        return (string) $value;
    }

    /**
     * Default-Locale, wenn keine andere Quelle (z.B. Accept-Language) greift.
     * AFK-2 setzt diesen Wert beim Translator-Bau; AFK-3-Middleware mutiert ihn
     * spaeter pro Request via `Translator::setLocale()`.
     */
    public function defaultLocale(): string
    {
        $value = $_ENV['DEFAULT_LOCALE'] ?? self::REPO_DEFAULT_LOCALE;
        return in_array($value, self::SUPPORTED_LOCALES, true) ? $value : self::REPO_DEFAULT_LOCALE;
    }

    /**
     * @return list<string>
     */
    public function supportedLocales(): array
    {
        return self::SUPPORTED_LOCALES;
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
     * Identitaets-Provider: 'microsoft' (Default) oder 'google'. Steuert Backend-
     * Provider-Auswahl im DI und UI-Verhalten (z.B. ein OOO-Feld bei Google
     * statt zwei, weil Gmail Vacation API nur einen Body unterstuetzt).
     */
    public function identityProvider(): string
    {
        $value = $this->get('IDENTITY_PROVIDER', 'microsoft');
        return in_array($value, ['microsoft', 'google'], true) ? $value : 'microsoft';
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
     *     support_email: string,
     *     accent_color: string,
     *     app_url: string,
     *     default_jahresanspruch: int,
     *     feiertage_bundesland: string,
     *     identity_provider: string,
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
            'identity_provider' => $this->identityProvider(),
        ];
    }
}
