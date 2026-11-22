<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Functional\Erklaerung;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Lubomedia\Barrierepruefung\Erklaerung\Abruf;
use Lubomedia\Barrierepruefung\Erklaerung\Fassung;
use Lubomedia\Barrierepruefung\Verbindung\Tresor;
use Lubomedia\Barrierepruefung\Verbindung\Verbindung;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Registry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class AbrufTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['fluid_styled_content'];

    protected array $testExtensionsToLoad = ['lubomedia/barrierepruefung-typo3'];

    protected array $configurationToUseInTestInstance = [
        'SYS' => ['caching' => ['cacheConfigurations' => ['pages' => ['backend' => Typo3DatabaseBackend::class]]]],
    ];

    /** @var list<RequestInterface> */
    private array $anfragen = [];

    /** @var list<Response|ConnectException> Antworten der Reihe nach; die letzte gilt für alle weiteren Anfragen */
    private array $antworten = [];

    protected function setUp(): void
    {
        parent::setUp();

        $container = $this->getContainer();
        \assert($container instanceof \Symfony\Component\DependencyInjection\Container);
        $container->set('barrierepruefung.guzzle', new GuzzleClient(['handler' => function (RequestInterface $anfrage): mixed {
            $this->anfragen[] = $anfrage;
            $antwort = count($this->antworten) > 1 ? array_shift($this->antworten) : ($this->antworten[0] ?? new Response(500));

            return $antwort instanceof ConnectException ? Create::rejectionFor($antwort) : Create::promiseFor($antwort);
        }]));
    }

    /**
     * @param array<string, mixed> $aenderungen
     *
     * @return array<string, mixed>
     */
    private static function erklaerung(array $aenderungen = []): array
    {
        return $aenderungen + [
            'type' => 'public_sector_statement',
            'jurisdiction' => 'DE',
            'locale' => 'de',
            'version' => 4,
            'published_at' => '2026-10-08T21:29:51+00:00',
            'html' => '<h1>Erklärung zur Barrierefreiheit</h1><p>Text</p>',
            'attribution' => null,
        ];
    }

    /**
     * @param array<string, mixed> $erklaerung
     */
    private static function ok(array $erklaerung, string $etag = '"e2"', string $cache = 'private, max-age=3600'): Response
    {
        return new Response(200, ['ETag' => $etag, 'Cache-Control' => $cache, 'Content-Type' => 'application/json'], (string)json_encode(['site_id' => 'S1', 'declaration' => $erklaerung]));
    }

    private function verbindung(string $siteId = 'S1', string $schluessel = 'i-am-not-a-secure-encryption-key'): Verbindung
    {
        return new Verbindung('stadt', $siteId, 'https://dienst.example/api/v1', (new Tresor($schluessel))->verschluesseln('tok-123'), [], 'm', 'd', time());
    }

    /**
     * @param array<string, mixed> $erklaerung
     */
    private function registryMit(string $siteId, array $erklaerung, string $etag, int $abgerufenAm): void
    {
        $this->get(Registry::class)->set(Abruf::REGISTRY, 'fassung_' . $siteId, ['erklaerung' => $erklaerung, 'etag' => $etag, 'abgerufen_am' => $abgerufenAm]);
    }

    private function abruf(): Abruf
    {
        return $this->get(Abruf::class);
    }

    #[Test]
    public function speichertUndNutztCache(): void
    {
        $this->antworten = [self::ok(self::erklaerung(), '"e2"', 'private, max-age=1800')];

        $erste = $this->abruf()->fassung($this->verbindung());
        $zweite = $this->abruf()->fassung($this->verbindung());

        self::assertCount(1, $this->anfragen);
        self::assertSame('/api/v1/sites/S1/declaration', $this->anfragen[0]->getUri()->getPath());
        self::assertSame(Fassung::AKTUELL, $erste->zustand);
        self::assertSame(Fassung::AKTUELL, $zweite->zustand);
        self::assertSame(4, $zweite->erklaerung['version'] ?? null);

        $gespeichert = $this->get(Registry::class)->get(Abruf::REGISTRY, 'fassung_S1');
        self::assertIsArray($gespeichert);
        self::assertSame('"e2"', $gespeichert['etag']);
        self::assertSame(4, $gespeichert['erklaerung']['version']);
        self::assertGreaterThan(time() - 60, $gespeichert['abgerufen_am']);

        // Lebensdauer aus max-age der API.
        $ablauf = (int)$this->getConnectionPool()->getConnectionForTable('cache_barrierepruefung_erklaerung')
            ->select(['expires'], 'cache_barrierepruefung_erklaerung')->fetchOne();
        self::assertLessThanOrEqual(time() + 1800, $ablauf);
        self::assertGreaterThan(time() + 1700, $ablauf);
    }

    #[Test]
    public function sendetIfNoneMatchUnd304ErneuertNur(): void
    {
        $this->registryMit('S1', self::erklaerung(['version' => 3]), '"e1"', 1_700_000_000);
        $seiten = $this->get(CacheManager::class)->getCache('pages');
        $seiten->set('seite_s1', 'inhalt', [Abruf::tag('S1')]);
        $this->antworten = [new Response(304, ['ETag' => '"e1"'])];

        $fassung = $this->abruf()->fassung($this->verbindung());

        self::assertCount(1, $this->anfragen);
        self::assertSame('"e1"', $this->anfragen[0]->getHeaderLine('If-None-Match'));
        self::assertSame(Fassung::AKTUELL, $fassung->zustand);
        self::assertSame(3, $fassung->erklaerung['version'] ?? null);
        self::assertTrue($seiten->has('seite_s1'), 'Eine unveränderte Fassung leert keine Seite.');
        $gespeichert = $this->get(Registry::class)->get(Abruf::REGISTRY, 'fassung_S1');
        self::assertGreaterThan(1_700_000_000, $gespeichert['abgerufen_am'], 'Der Abruf zählt als Kontakt mit dem Dienst.');
    }

    #[Test]
    public function neueEtagWirftGenauDieSeitenMitDemElement(): void
    {
        $this->registryMit('S1', self::erklaerung(['version' => 3]), '"e1"', 1_700_000_000);
        $seiten = $this->get(CacheManager::class)->getCache('pages');
        $seiten->set('seite_s1', 'inhalt', [Abruf::tag('S1')]);
        $seiten->set('seite_s2', 'inhalt', [Abruf::tag('S2')]);
        $seiten->set('seite_ohne', 'inhalt', ['pageId_9']);
        $this->antworten = [self::ok(self::erklaerung(['version' => 4]), '"e2"')];

        $fassung = $this->abruf()->fassung($this->verbindung());

        self::assertSame(4, $fassung->erklaerung['version'] ?? null);
        self::assertFalse($seiten->has('seite_s1'));
        self::assertTrue($seiten->has('seite_s2'));
        self::assertTrue($seiten->has('seite_ohne'));
    }

    #[Test]
    public function rueckfallMitStandDatumBeiAusfall(): void
    {
        $this->registryMit('S1', self::erklaerung(['version' => 7, 'published_at' => '2026-11-14T09:00:00+00:00']), '"e7"', 1_763_110_800);
        $this->antworten = [new Response(503, ['Content-Type' => 'application/problem+json'], '{"title":"Dienst nicht erreichbar","status":503}')];

        $fassung = $this->abruf()->fassung($this->verbindung());

        self::assertSame(Fassung::RUECKFALL, $fassung->zustand);
        self::assertSame(7, $fassung->erklaerung['version'] ?? null);
        self::assertSame('2026-11-14T09:00:00+00:00', $fassung->erklaerung['published_at'] ?? null);
        self::assertSame(1_763_110_800, $fassung->abgerufenAm);
        self::assertNotNull($fassung->letzterFehler);
        self::assertNotNull($fassung->letzterFehlerAm);

        $gespeichert = $this->get(Registry::class)->get(Abruf::REGISTRY, 'fassung_S1');
        self::assertSame(7, $gespeichert['erklaerung']['version'], 'Die gute Fassung bleibt.');
        self::assertSame('"e7"', $gespeichert['etag']);
        self::assertIsString($gespeichert['letzter_fehler']);
        self::assertSame(Fassung::RUECKFALL, $this->abruf()->stand('S1')->zustand);
    }

    #[Test]
    public function rueckfallBeiTresorFehler(): void
    {
        $this->registryMit('S1', self::erklaerung(['version' => 7]), '"e7"', 1_763_110_800);
        $this->antworten = [self::ok(self::erklaerung())];

        $fassung = $this->abruf()->fassung($this->verbindung('S1', 'ein-anderer-schluessel'));

        self::assertSame(Fassung::RUECKFALL, $fassung->zustand);
        self::assertSame(7, $fassung->erklaerung['version'] ?? null);
        self::assertCount(0, $this->anfragen, 'Ohne lesbaren Token geht keine Anfrage hinaus.');
        self::assertNotNull($fassung->letzterFehler);
    }

    #[Test]
    public function ausfallFragtNichtBeiJedemAufrufNach(): void
    {
        $this->registryMit('S1', self::erklaerung(['version' => 7]), '"e7"', 1_763_110_800);
        $this->antworten = [new Response(503)];

        $this->abruf()->fassung($this->verbindung());
        $zweite = $this->abruf()->fassung($this->verbindung());

        self::assertCount(1, $this->anfragen);
        self::assertSame(Fassung::RUECKFALL, $zweite->zustand);
        self::assertSame(7, $zweite->erklaerung['version'] ?? null);
        $ablauf = (int)$this->getConnectionPool()->getConnectionForTable('cache_barrierepruefung_erklaerung')
            ->select(['expires'], 'cache_barrierepruefung_erklaerung')->fetchOne();
        self::assertLessThanOrEqual(time() + Abruf::RUECKFALL_SEKUNDEN, $ablauf);
    }

    #[Test]
    public function ohneFassungUndAusfallKeine(): void
    {
        $this->antworten = [new Response(503)];

        $fassung = $this->abruf()->fassung($this->verbindung());

        self::assertSame(Fassung::KEINE, $fassung->zustand);
        self::assertNull($fassung->erklaerung);
        self::assertNotNull($fassung->letzterFehler);
        self::assertSame(Fassung::KEINE, $this->abruf()->stand('S1')->zustand);
    }

    #[Test]
    public function nichtVeroeffentlichtIstRueckfall(): void
    {
        $this->registryMit('S1', self::erklaerung(['version' => 7]), '"e7"', 1_763_110_800);

        $this->antworten = [new Response(404, ['Content-Type' => 'application/json'], '{"message":"Keine veröffentlichte Erklärung."}')];
        $fassung = $this->abruf()->fassung($this->verbindung());

        self::assertSame(Fassung::RUECKFALL, $fassung->zustand);
        self::assertSame('Keine veröffentlichte Erklärung.', $fassung->letzterFehler);
    }

    #[Test]
    public function netzfehlerIstRueckfall(): void
    {
        $this->registryMit('S1', self::erklaerung(['version' => 7]), '"e7"', 1_763_110_800);
        $this->antworten = [new ConnectException('Zeitlimit', new Request('GET', 'https://dienst.example/'))];

        $fassung = $this->abruf()->fassung($this->verbindung());

        self::assertSame(Fassung::RUECKFALL, $fassung->zustand);
        self::assertSame(7, $fassung->erklaerung['version'] ?? null);
    }

    #[Test]
    public function auffrischenFragtTrotzCacheNeu(): void
    {
        $this->antworten = [self::ok(self::erklaerung(['version' => 4]), '"e4"'), self::ok(self::erklaerung(['version' => 5]), '"e5"')];

        $this->abruf()->fassung($this->verbindung());
        $neu = $this->abruf()->auffrischen($this->verbindung());

        self::assertCount(2, $this->anfragen);
        self::assertSame('"e4"', $this->anfragen[1]->getHeaderLine('If-None-Match'));
        self::assertSame(5, $neu->erklaerung['version'] ?? null);
        self::assertSame(5, $this->abruf()->fassung($this->verbindung())->erklaerung['version'] ?? null);
    }

    #[Test]
    public function neuIstNurEineVeraenderteFassung(): void
    {
        $this->registryMit('S1', self::erklaerung(['version' => 3]), '"e1"', 1_700_000_000);
        $this->antworten = [self::ok(self::erklaerung(['version' => 4]), '"e2"'), new Response(304, ['ETag' => '"e2"'])];

        $neu = $this->abruf()->auffrischen($this->verbindung());
        $gleich = $this->abruf()->auffrischen($this->verbindung());

        self::assertTrue($neu->neu);
        self::assertFalse($gleich->neu);
        self::assertFalse($this->abruf()->fassung($this->verbindung())->neu, 'Aus dem Cache gilt sie nicht mehr als neu.');
    }

    #[Test]
    public function fassungKenntIhrenAblauf(): void
    {
        $this->antworten = [self::ok(self::erklaerung(), '"e2"', 'private, max-age=1800')];

        $erste = $this->abruf()->fassung($this->verbindung());
        $zweite = $this->abruf()->fassung($this->verbindung());

        self::assertEqualsWithDelta(time() + 1800, $erste->gueltigBis, 2);
        self::assertSame($erste->gueltigBis, $zweite->gueltigBis, 'Der Ablauf bleibt der des Eintrags, er wandert nicht mit.');
    }
}
