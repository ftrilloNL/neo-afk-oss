<?php declare(strict_types=1);

namespace Tests\I18n;

use App\I18n\LocaleResolver;
use PHPUnit\Framework\TestCase;

final class LocaleResolverTest extends TestCase
{
    /** @var list<string> */
    private const SUPPORTED = ['de', 'en'];
    private const FALLBACK = 'en';

    private LocaleResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new LocaleResolver();
    }

    public function testGermanResolvesToDe(): void
    {
        self::assertSame('de', $this->resolver->resolve('de-DE,de;q=0.9', self::SUPPORTED, self::FALLBACK));
    }

    public function testEnglishResolvesToEn(): void
    {
        self::assertSame('en', $this->resolver->resolve('en-US,en;q=0.9', self::SUPPORTED, self::FALLBACK));
    }

    public function testUnsupportedLanguageFallsBackToEn(): void
    {
        self::assertSame('en', $this->resolver->resolve('fr-FR,fr;q=0.9', self::SUPPORTED, self::FALLBACK));
    }

    public function testEmptyHeaderFallsBackToFallback(): void
    {
        self::assertSame('en', $this->resolver->resolve('', self::SUPPORTED, self::FALLBACK));
    }

    public function testMalformedHeaderFallsBackToFallback(): void
    {
        self::assertSame('en', $this->resolver->resolve(';;q=invalid', self::SUPPORTED, self::FALLBACK));
    }

    public function testMixedHeaderPrefersHigherQualityMatch(): void
    {
        // German preferred (q=1.0 implicit) over English fallback.
        self::assertSame('de', $this->resolver->resolve('de,en;q=0.5', self::SUPPORTED, self::FALLBACK));
    }

    public function testRegionalVariantMatchesBaseLanguage(): void
    {
        // de-AT, de-CH not explicitly supported but should match base 'de'.
        self::assertSame('de', $this->resolver->resolve('de-CH', self::SUPPORTED, self::FALLBACK));
    }

    public function testWhitespaceOnlyHeaderTreatedAsEmpty(): void
    {
        self::assertSame('en', $this->resolver->resolve('   ', self::SUPPORTED, self::FALLBACK));
    }
}
