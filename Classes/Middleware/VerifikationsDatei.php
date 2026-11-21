<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Middleware;

use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Beantwortet `/.well-known/a11y-site-verification.txt` für jede Site mit Verbindung, auf jedem Host,
 * auf den die Site auflöst. Der Rumpf ist der Dateitoken – nicht der Meta-Token.
 *
 * Grenze: Die Datei gibt es nur am Wurzelpfad des Hosts. Liegt die Site unter einem Pfad (Basis
 * `https://stadt.de/museum/`), löst `/.well-known/…` nicht auf diese Site auf – und der Dienst fragt die Datei
 * ohnehin immer am Wurzelpfad des Hosts ab. Für solche Sites gelingt der Nachweis nur über das Meta-Element
 * der Startseite (VerifikationsMeta); das Modul sagt das an „Domain“.
 */
final class VerifikationsDatei implements MiddlewareInterface
{
    public const PFAD = '/.well-known/a11y-site-verification.txt';

    public function __construct(
        private readonly Verbindungen $verbindungen,
        private readonly ResponseFactoryInterface $antworten,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site || $request->getUri()->getPath() !== self::PFAD || !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }
        $verbindung = $this->verbindungen->fuerSite($site->getIdentifier());
        if ($verbindung === null || $verbindung->nachweisDatei === '') {
            return $handler->handle($request);
        }

        $antwort = $this->antworten->createResponse(200)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            // Ein Zwischenspeicher darf einen überholten Token nicht festhalten.
            ->withHeader('Cache-Control', 'no-store');
        if ($request->getMethod() === 'GET') {
            $antwort->getBody()->write($verbindung->nachweisDatei);
        }

        return $antwort;
    }
}
