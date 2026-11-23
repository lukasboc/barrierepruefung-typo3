<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Functional\Verbindung;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Lubomedia\Barrierepruefung\Verbindung\Tresor;
use Lubomedia\Barrierepruefung\Verbindung\Verbindung;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use Lubomedia\Barrierepruefung\Verbindung\Verifikation;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class VerifikationBestaetigenTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['fluid_styled_content'];

    protected array $testExtensionsToLoad = ['barrierepruefungde/typo3'];

    /** @var list<Request> */
    private array $anfragen = [];

    private ?MockHandler $mock = null;

    /**
     * @param list<Response|\Throwable> $antworten
     */
    private function verifikation(array $antworten): Verifikation
    {
        $this->anfragen = [];
        if ($this->mock === null) {
            $this->mock = new MockHandler();
            $stapel = HandlerStack::create($this->mock);
            $stapel->push(function (callable $weiter) {
                return function (Request $anfrage, array $optionen) use ($weiter) {
                    $this->anfragen[] = $anfrage;

                    return $weiter($anfrage, $optionen);
                };
            });
            $container = $this->getContainer();
            \assert($container instanceof \Symfony\Component\DependencyInjection\Container);
            $container->set('barrierepruefung.guzzle', new GuzzleClient(['handler' => $stapel]));
        }
        $this->mock->reset();
        foreach ($antworten as $antwort) {
            $this->mock->append($antwort);
        }

        $verbindungen = $this->get(Verbindungen::class);
        $verbindungen->speichern(new Verbindung(
            'stadt',
            '01SITE',
            'https://dienst.example/api/v1',
            Tresor::ausInstallation()->verschluesseln('tok'),
            [],
            'meta-xyz',
            'datei-abc',
            time(),
        ));

        return $this->get(Verifikation::class);
    }

    private function antwort(bool $bestaetigt, string $methode, ?string $grund = null): Response
    {
        return new Response(200, [], (string)json_encode(['data' => [
            'verified' => $bestaetigt, 'method' => $bestaetigt ? $methode : null, 'failure_reason' => $grund, 'site' => ['id' => '01SITE'],
        ]]));
    }

    private function verbindung(): Verbindung
    {
        $v = $this->get(Verbindungen::class)->fuerSite('stadt');
        self::assertNotNull($v);

        return $v;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function methoden(): array
    {
        return array_map(
            static fn(Request $a): array => ['pfad' => $a->getUri()->getPath(), 'methode' => $a->getMethod(), 'rumpf' => json_decode((string)$a->getBody(), true)],
            $this->anfragen,
        );
    }

    #[Test]
    public function erstMetaDannDatei(): void
    {
        $verifikation = $this->verifikation([
            $this->antwort(false, 'meta_tag', 'meta_nicht_gefunden'),
            $this->antwort(true, 'file'),
        ]);

        $ergebnis = $verifikation->bestaetigen($this->verbindung());

        self::assertTrue($ergebnis->bestaetigt);
        self::assertSame('file', $ergebnis->methode);
        self::assertSame(['meta_tag:meta_nicht_gefunden'], $ergebnis->gruende);
        self::assertSame([
            ['pfad' => '/api/v1/sites/01SITE/verify', 'methode' => 'POST', 'rumpf' => ['method' => 'meta_tag']],
            ['pfad' => '/api/v1/sites/01SITE/verify', 'methode' => 'POST', 'rumpf' => ['method' => 'file']],
        ], $this->methoden());
        self::assertNotNull($this->verbindung()->domainBestaetigtAm);
    }

    #[Test]
    public function metaGenuegtUndSparstDieDateiAnfrage(): void
    {
        $ergebnis = $this->verifikation([$this->antwort(true, 'meta_tag')])->bestaetigen($this->verbindung());

        self::assertTrue($ergebnis->bestaetigt);
        self::assertSame('meta_tag', $ergebnis->methode);
        self::assertCount(1, $this->anfragen);
    }

    #[Test]
    public function beideScheiternNenntBeideGruendeUndSetztNichts(): void
    {
        $ergebnis = $this->verifikation([
            $this->antwort(false, 'meta_tag', 'meta_nicht_gefunden'),
            $this->antwort(false, 'file', 'token_nicht_gefunden'),
        ])->bestaetigen($this->verbindung());

        self::assertFalse($ergebnis->bestaetigt);
        self::assertNull($ergebnis->methode);
        self::assertSame(['meta_tag:meta_nicht_gefunden', 'file:token_nicht_gefunden'], $ergebnis->gruende);
        self::assertNull($this->verbindung()->domainBestaetigtAm);
    }

    #[Test]
    public function netzfehlerIstEinGrundKeineAusnahme(): void
    {
        $ergebnis = $this->verifikation([
            new ConnectException('weg', new Request('POST', 'https://dienst.example')),
            new ConnectException('weg', new Request('POST', 'https://dienst.example')),
        ])->bestaetigen($this->verbindung());

        self::assertFalse($ergebnis->bestaetigt);
        self::assertSame(['meta_tag:nicht_erreichbar', 'file:nicht_erreichbar'], $ergebnis->gruende);
    }
}
