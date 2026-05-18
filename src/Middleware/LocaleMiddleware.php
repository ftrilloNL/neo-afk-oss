<?php declare(strict_types=1);

namespace App\Middleware;

use App\Config;
use App\I18n\LocaleResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Symfony\Component\Translation\Translator;

/**
 * Setzt fuer jeden Request die aktive Locale auf dem Translator
 * basierend auf dem Accept-Language-Header. Wird als aeusserste
 * Middleware registriert -- alle nachfolgenden Layer (Controller,
 * Twig, MailService) sehen damit die richtige Locale.
 *
 * Fallback bei unbekannter / fehlender / fehlerhafter Sprache: 'en'.
 * Bewusst nicht der Repo-Default, sondern immer Englisch -- siehe
 * Epic AFK-1.
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    private const FALLBACK_LOCALE = 'en';

    public function __construct(
        private readonly Translator $translator,
        private readonly LocaleResolver $resolver,
        private readonly Config $config,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $locale = $this->resolver->resolve(
            $request->getHeaderLine('Accept-Language'),
            $this->config->supportedLocales(),
            self::FALLBACK_LOCALE,
        );
        $this->translator->setLocale($locale);
        return $handler->handle($request);
    }
}
