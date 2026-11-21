<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Middleware;

use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Setzt das Meta-Element des Domain-Nachweises in die Startseite einer verbundenen Site.
 *
 * Bewusst an der fertigen Antwort statt im Seitencache: Eine Startseite, die vor dem Verbinden im Cache lag,
 * trüge das Element sonst bis zu 24 Stunden nicht.
 */
final class VerifikationsMeta implements MiddlewareInterface
{
    public const NAME = 'a11y-site-verification';

    public function __construct(private readonly Verbindungen $verbindungen) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $antwort = $handler->handle($request);

        $site = $request->getAttribute('site');
        $sprache = $request->getAttribute('language');
        if (!$site instanceof Site
            || !$sprache instanceof SiteLanguage
            || $antwort->getStatusCode() !== 200
            || !str_starts_with(strtolower($antwort->getHeaderLine('Content-Type')), 'text/html')
            || !$this->istStartseite($request, $sprache)
        ) {
            return $antwort;
        }
        $verbindung = $this->verbindungen->fuerSite($site->getIdentifier());
        if ($verbindung === null || $verbindung->nachweisMeta === '') {
            return $antwort;
        }

        $koerper = $antwort->getBody();
        $koerper->rewind();
        $html = $koerper->getContents();
        if (stripos($html, '<meta name="' . self::NAME . '"') !== false) {
            return $antwort;
        }
        $ende = stripos($html, '</head>');
        if ($ende === false) {
            return $antwort;
        }

        $element = '<meta name="' . self::NAME . '" content="' . htmlspecialchars($verbindung->nachweisMeta, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5) . '">' . "\n";
        $neu = substr($html, 0, $ende) . $element . substr($html, $ende);

        $strom = new Stream('php://temp', 'rw');
        $strom->write($neu);
        $strom->rewind();
        $antwort = $antwort->withBody($strom);

        // Eine schon gesetzte Länge stimmt nicht mehr.
        return $antwort->hasHeader('Content-Length')
            ? $antwort->withHeader('Content-Length', (string)strlen($neu))
            : $antwort;
    }

    /**
     * Startseite = Pfad der Anfrage ist der Basis-Pfad der aufgelösten Sprache, mit oder ohne Schrägstrich am Ende.
     */
    private function istStartseite(ServerRequestInterface $request, SiteLanguage $sprache): bool
    {
        $basis = rtrim($sprache->getBase()->getPath(), '/');

        return rtrim($request->getUri()->getPath(), '/') === $basis;
    }
}
