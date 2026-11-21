<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Unit\Api;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Lubomedia\Barrierepruefung\Api\Client;
use Lubomedia\Barrierepruefung\Api\Sprache;
use Lubomedia\Barrierepruefung\Api\Zugang;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ClientTest extends UnitTestCase
{
    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    private array $verlauf = [];

    private function anfrage(): RequestInterface
    {
        self::assertArrayHasKey(0, $this->verlauf);

        return $this->verlauf[0]['request'];
    }

    private function option(string $name): mixed
    {
        self::assertArrayHasKey(0, $this->verlauf);

        return $this->verlauf[0]['options'][$name] ?? null;
    }

    /**
     * @param list<Response|\Throwable> $antworten
     */
    private function client(array $antworten, string $sprache = 'de'): Client
    {
        $mock = new MockHandler($antworten);
        // Eigener Verlauf statt Middleware::history: der Aufruf bekommt die Optionen, wie Guzzle sie zusammensetzt.
        $handler = function (RequestInterface $anfrage, array $optionen) use ($mock) {
            /** @var array<string, mixed> $optionen */
            $this->verlauf[] = ['request' => $anfrage, 'options' => $optionen];

            return $mock($anfrage, $optionen);
        };

        return new Client(new GuzzleClient(['handler' => $handler]), new Sprache(static fn(): string => $sprache));
    }

    private function zugang(): Zugang
    {
        return new Zugang('https://dienst.example/api/v1/', 'site1', 't');
    }

    #[Test]
    public function sendetKennungUndSprache(): void
    {
        $this->client([new Response(200, [], '{"data":{}}')])->get($this->zugang(), '/sites/site1');

        $anfrage = $this->anfrage();
        self::assertSame('https://dienst.example/api/v1/sites/site1', (string)$anfrage->getUri());
        self::assertSame('Bearer t', $anfrage->getHeaderLine('Authorization'));
        self::assertSame('application/json', $anfrage->getHeaderLine('Accept'));
        self::assertSame('de', $anfrage->getHeaderLine('Accept-Language'));
        self::assertMatchesRegularExpression('#^Barrierepruefung-TYPO3/\S+ \(TYPO3 1[34]\.\d+\.\d+\)$#', $anfrage->getHeaderLine('User-Agent'));
        self::assertFalse($this->option('allow_redirects'));
    }

    #[Test]
    public function englischeBackendSpracheGibtEnglisch(): void
    {
        $this->client([new Response(200, [], '{}')], 'en')->get($this->zugang(), '/x');

        self::assertSame('en', $this->anfrage()->getHeaderLine('Accept-Language'));
    }

    #[Test]
    public function liestEtagUndMaxAge(): void
    {
        $antwort = $this->client([new Response(200, ['ETag' => '"abc"', 'Cache-Control' => 'private, max-age=3600'], '{"data":{}}')])
            ->get($this->zugang(), '/declaration');

        self::assertTrue($antwort->ok());
        self::assertSame('"abc"', $antwort->etag());
        self::assertSame(3600, $antwort->maxAge());
    }

    #[Test]
    public function netzfehlerWirdAntwortMitStatusNull(): void
    {
        $antwort = $this->client([new ConnectException('weg', new Request('GET', 'https://dienst.example'))])
            ->get($this->zugang(), '/x');

        self::assertSame(0, $antwort->status);
        self::assertFalse($antwort->ok());
        self::assertSame('nicht_erreichbar', $antwort->problem?->reason);
    }

    #[Test]
    public function zeitlimitGehtMit(): void
    {
        $this->client([new Response(200, [], '{}')])->get($this->zugang(), '/x', zeitlimit: 3.0);

        self::assertSame(3.0, $this->option('timeout'));
    }

    #[Test]
    public function problemRumpfWirdGelesen(): void
    {
        $antwort = $this->client([new Response(422, ['Content-Type' => 'application/problem+json'], '{"reason":"nicht_bereit","detail":"d"}')])
            ->get($this->zugang(), '/x');

        self::assertFalse($antwort->ok());
        self::assertSame('nicht_bereit', $antwort->problem?->reason);
    }

    #[Test]
    public function ungueltigerRumpfBeiFehlerGibtAllgemeinesProblem(): void
    {
        $antwort = $this->client([new Response(502, [], '<html>Bad Gateway</html>')])->get($this->zugang(), '/x');

        self::assertSame([], $antwort->daten);
        self::assertNotNull($antwort->problem);
        self::assertNull($antwort->problem->reason);
    }

    #[Test]
    public function ungueltigerRumpfBeiErfolgIstLeereDaten(): void
    {
        $antwort = $this->client([new Response(200, [], 'kein json')])->get($this->zugang(), '/x');

        self::assertSame([], $antwort->daten);
        self::assertTrue($antwort->ok());
    }

    #[Test]
    public function antwort304HatLeerenRumpfUndIstKeinFehler(): void
    {
        $antwort = $this->client([new Response(304)])->get($this->zugang(), '/x', ['If-None-Match' => '"abc"']);

        self::assertSame(304, $antwort->status);
        self::assertNull($antwort->problem);
        self::assertSame('"abc"', $this->anfrage()->getHeaderLine('If-None-Match'));
    }

    #[Test]
    public function sendenKodiertJsonMitUmlautenUndKopfzeilen(): void
    {
        $this->client([new Response(201, [], '{"data":{"id":1}}')])
            ->senden($this->zugang(), 'post', '/scans', ['kontakt' => 'Müller'], ['Idempotency-Key' => 'k1']);

        $anfrage = $this->anfrage();
        self::assertSame('POST', $anfrage->getMethod());
        self::assertSame('{"kontakt":"Müller"}', (string)$anfrage->getBody());
        self::assertSame('application/json', $anfrage->getHeaderLine('Content-Type'));
        self::assertSame('k1', $anfrage->getHeaderLine('Idempotency-Key'));
    }

    #[Test]
    public function nichtKodierbareDatenWerfenNicht(): void
    {
        $antwort = $this->client([])->senden($this->zugang(), 'POST', '/x', ['kaputt' => "\xB1\x31"]);

        self::assertSame(0, $antwort->status);
        self::assertNotNull($antwort->problem);
    }
}
