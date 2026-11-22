<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Functional\Erklaerung;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Lubomedia\Barrierepruefung\Erklaerung\Abruf;
use Lubomedia\Barrierepruefung\Verbindung\Tresor;
use Lubomedia\Barrierepruefung\Verbindung\Verbindung;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class InhaltselementTest extends FunctionalTestCase
{
    private const SITE_ID = '01STUBSITE0000000000000000';

    protected array $coreExtensionsToLoad = ['fluid_styled_content'];

    protected array $testExtensionsToLoad = ['lubomedia/barrierepruefung-typo3'];

    /**
     * Seitencache in der Datenbank (sonst NullBackend). Jede echte HTTP-Anfrage geht an einen toten Proxy:
     * Der Frontend-Aufruf baut seinen eigenen Container, die Attrappe des Tests erreicht ihn nicht. Was die
     * Seite zeigen soll, legt der Test deshalb über `Abruf` in Cache und Registry (dieselbe Datenbank); fragt
     * das Frontend doch selbst, scheitert es sofort - wie beim allerersten Abruf während eines Ausfalls.
     */
    protected array $configurationToUseInTestInstance = [
        'SYS' => ['caching' => ['cacheConfigurations' => ['pages' => ['backend' => Typo3DatabaseBackend::class]]]],
        'HTTP' => ['proxy' => 'http://127.0.0.1:9', 'connect_timeout' => 1],
    ];

    /** @var list<RequestInterface> */
    private array $anfragen = [];

    private ?Response $antwort = null;

    protected function setUp(): void
    {
        parent::setUp();

        $container = $this->getContainer();
        \assert($container instanceof \Symfony\Component\DependencyInjection\Container);
        $container->set('barrierepruefung.guzzle', new GuzzleClient(['handler' => function (RequestInterface $anfrage): mixed {
            $this->anfragen[] = $anfrage;

            return Create::promiseFor($this->antwort ?? self::ok());
        }]));

        $seiten = $this->getConnectionPool()->getConnectionForTable('pages');
        $seiten->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Barrierefreiheit', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1, 'sys_language_uid' => 0]);
        $seiten->insert('pages', ['uid' => 2, 'pid' => 0, 'title' => 'Accessibility', 'slug' => '/', 'doktype' => 1, 'sys_language_uid' => 1, 'l10n_parent' => 1]);
        $this->setUpFrontendRootPage(1, [
            'constants' => ['EXT:fluid_styled_content/Configuration/TypoScript/constants.typoscript'],
            'setup' => ['EXT:fluid_styled_content/Configuration/TypoScript/setup.typoscript'],
        ]);
        $this->addTypoScriptToTemplateRecord(1, 'config.disableAllHeaderCode = 1' . LF . 'page = PAGE' . LF . 'page.10 < styles.content.get');

        $this->get(SiteWriter::class)->write('stadt', [
            'rootPageId' => 1,
            'base' => 'https://stadt.de/',
            'languages' => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'English', 'locale' => 'en_GB.UTF-8', 'base' => '/en/', 'fallbackType' => 'strict'],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $aenderungen
     */
    private static function ok(array $aenderungen = [], string $etag = '"e4"'): Response
    {
        $html = (string)file_get_contents(__DIR__ . '/../../Fixtures/fragment/oeffentlich-bund.html');

        return new Response(200, ['ETag' => $etag, 'Cache-Control' => 'private, max-age=3600', 'Content-Type' => 'application/json'], (string)json_encode([
            'site_id' => self::SITE_ID,
            'declaration' => $aenderungen + [
                'type' => 'public_sector_statement',
                'jurisdiction' => 'DE',
                'locale' => 'de',
                'version' => 4,
                'published_at' => '2026-10-08T21:29:51+00:00',
                'html' => $html,
                'attribution' => '<p class="a11y-erklaerung-hinweis">Barrierefreiheitserklärung erstellt mit <a href="https://barrierepruefung.de">Barrierepruefung.de</a></p>',
            ],
        ]));
    }

    /** Verbindet die Site und legt die Fassung, die die Attrappe liefert, in Cache und Registry. */
    private function verbinden(bool $fassungHolen = true): void
    {
        $this->get(Verbindungen::class)->speichern(new Verbindung(
            'stadt',
            self::SITE_ID,
            'https://dienst.example/api/v1',
            Tresor::ausInstallation()->verschluesseln('tok-123'),
            [],
            'm',
            'd',
            time(),
        ));
        if ($fassungHolen) {
            $this->auffrischen();
        }
    }

    private function auffrischen(): void
    {
        $this->get(Abruf::class)->auffrischen($this->get(Verbindungen::class)->fuerSite('stadt') ?? throw new \LogicException('keine Verbindung'));
    }

    private function element(string $teil = 'komplett', int $ueberschrift = 2, int $stand = 1): void
    {
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 10,
            'pid' => 1,
            'colPos' => 0,
            'sys_language_uid' => -1,
            'CType' => 'barrierepruefung_erklaerung',
            'tx_barrierepruefung_teil' => $teil,
            'tx_barrierepruefung_ueberschrift' => $ueberschrift,
            'tx_barrierepruefung_stand' => $stand,
        ]);
    }

    private function seitenImCache(): int
    {
        return (int)$this->getConnectionPool()->getConnectionForTable('cache_pages')->count('*', 'cache_pages', []);
    }

    private function seite(string $adresse = 'https://stadt.de/'): string
    {
        $antwort = $this->executeFrontendSubRequest(new InternalRequest($adresse));
        self::assertSame(200, $antwort->getStatusCode(), (string)$antwort->getBody());

        return (string)$antwort->getBody();
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function kombinationen(): iterable
    {
        foreach (['komplett', 'maengel', 'kontakt'] as $teil) {
            foreach ([2, 3, 4] as $ebene) {
                foreach ([1, 0] as $stand) {
                    yield $teil . '/h' . $ebene . '/stand' . $stand => [$teil, $ebene, $stand];
                }
            }
        }
    }

    #[Test]
    #[DataProvider('kombinationen')]
    public function rendertAlleKombinationenOhneH1UndOhneStil(string $teil, int $ebene, int $stand): void
    {
        $this->verbinden();
        $this->element($teil, $ebene, $stand);

        $html = $this->seite();

        self::assertStringContainsString('<div class="a11y-erklaerung">', $html);
        self::assertStringContainsString('id="c10"', $html, 'Der Rahmen des Sitepackages bleibt.');
        self::assertStringNotContainsString('<h1', $html);
        preg_match_all('#<h([1-6])[\s>]#', $html, $treffer);
        $ebenen = array_map('intval', $treffer[1]);
        self::assertSame($ebene, $ebenen === [] ? null : min($ebenen));
        // Keine übersprungene Ebene: jede Überschrift höchstens eine tiefer als die vorige.
        $vorige = $ebene;
        foreach ($ebenen as $stufe) {
            self::assertLessThanOrEqual($vorige + 1, $stufe);
            $vorige = $stufe;
        }
        self::assertStringNotContainsString('style=', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<link', $html);

        if ($teil === 'maengel') {
            self::assertStringContainsString('Nicht barrierefreie Inhalte', $html);
            self::assertStringNotContainsString('Feedback und Kontaktangaben', $html);
        }
        if ($teil === 'kontakt') {
            self::assertStringContainsString('Feedback und Kontaktangaben', $html);
            self::assertStringNotContainsString('Nicht barrierefreie Inhalte', $html);
        }
        if ($teil === 'komplett') {
            self::assertStringContainsString('Nicht barrierefreie Inhalte', $html);
            self::assertStringContainsString('Feedback und Kontaktangaben', $html);
        }
    }

    #[Test]
    public function sitepackageOhneLayoutDefaultBrichtDieSeiteNichtAb(): void
    {
        // Wie Camino: eigene Layout-Pfade, darin kein „Default“. Der Rückfall auf fluid_styled_content greift.
        $this->verbinden();
        $this->element();
        $this->addTypoScriptToTemplateRecord(1, 'lib.contentElement.layoutRootPaths >' . LF . 'lib.contentElement.layoutRootPaths.10 = EXT:barrierepruefung/Resources/Private/Templates/');

        $html = $this->seite();

        self::assertStringContainsString('id="c10"', $html);
        self::assertStringContainsString('<div class="a11y-erklaerung">', $html);
    }

    #[Test]
    public function standZeileMitTime(): void
    {
        $this->verbinden();
        $this->element('komplett', 2, 1);

        $mit = $this->seite();
        self::assertStringContainsString('<p class="a11y-erklaerung-stand">Version 4, Stand <time datetime="2026-10-08">08.10.2026</time></p>', $mit);
    }

    #[Test]
    public function ohneStandKeineStandZeile(): void
    {
        $this->verbinden();
        $this->element('komplett', 2, 0);

        self::assertStringNotContainsString('a11y-erklaerung-stand', $this->seite());
    }

    #[Test]
    public function attributionNurWennGeliefert(): void
    {
        $this->antwort = self::ok(['attribution' => null]);
        $this->verbinden();
        $this->element();

        self::assertStringNotContainsString('a11y-erklaerung-hinweis', $this->seite());
    }

    #[Test]
    public function attributionWirdAngehaengt(): void
    {
        $this->verbinden();
        $this->element();

        $html = $this->seite();
        self::assertStringContainsString('<p class="a11y-erklaerung-hinweis">Barrierefreiheitserklärung erstellt mit <a href="https://barrierepruefung.de">Barrierepruefung.de</a></p></div>', $html);
    }

    #[Test]
    public function cacheTagGesetzt(): void
    {
        $this->verbinden();
        $this->element();

        $this->seite();

        $tags = $this->getConnectionPool()->getConnectionForTable('cache_pages_tags')
            ->select(['tag'], 'cache_pages_tags')->fetchFirstColumn();
        self::assertContains('barrierepruefung_site_' . self::SITE_ID, $tags);
    }

    #[Test]
    public function neueFassungWirftDieSeiteAusDemCache(): void
    {
        $this->verbinden();
        $this->element();
        self::assertStringContainsString('Version 4', $this->seite());
        self::assertStringContainsString('Version 4', $this->seite(), 'Aus dem Seitencache.');
        self::assertSame(1, $this->seitenImCache());
        self::assertCount(1, $this->anfragen, 'Nur der Abruf des Tests; das Frontend liest aus dem Cache.');

        $this->antwort = self::ok(['version' => 8], '"e8"');
        $this->auffrischen();
        self::assertSame(0, $this->seitenImCache(), 'Die neue ETag wirft die Seite aus dem Cache.');

        self::assertStringContainsString('Version 8', $this->seite());
    }

    #[Test]
    public function langNurBeiAbweichenderSeitensprache(): void
    {
        $this->verbinden();
        $this->element();

        $deutsch = $this->seite('https://stadt.de/');
        self::assertStringContainsString('<div class="a11y-erklaerung">', $deutsch);

        $englisch = $this->seite('https://stadt.de/en/');
        self::assertStringContainsString('<div class="a11y-erklaerung" lang="de">', $englisch);
        self::assertStringContainsString('Feedback und Kontaktangaben', $englisch, 'Die Erklärung wird nie übersetzt.');
        self::assertStringContainsString('Stand <time datetime="2026-10-08">08.10.2026</time>', $englisch);
    }

    #[Test]
    public function ohneFassungKurzeSeitenlebensdauer(): void
    {
        // Allererster Abruf, und der Dienst ist nicht erreichbar: das Frontend fragt selbst und scheitert.
        $this->verbinden(false);
        $this->element();

        $html = $this->seite();

        self::assertStringContainsString('<p class="a11y-erklaerung-nicht-verfuegbar">Die Erklärung zur Barrierefreiheit kann gerade nicht angezeigt werden.', $html);
        $ablauf = (int)$this->getConnectionPool()->getConnectionForTable('cache_pages')
            ->select(['expires'], 'cache_pages')->fetchOne();
        self::assertGreaterThan(0, $ablauf);
        self::assertLessThanOrEqual(time() + Abruf::RUECKFALL_SEKUNDEN, $ablauf);
    }

    #[Test]
    public function nichtVerfuegbarInDerSeitensprache(): void
    {
        $this->antwort = new Response(503);
        $this->verbinden();
        $this->element();

        $html = $this->seite('https://stadt.de/en/');

        self::assertStringContainsString('<p class="a11y-erklaerung-nicht-verfuegbar">The accessibility statement cannot be shown at the moment.', $html);
    }

    #[Test]
    public function ohneVerbindungNichtVerfuegbarUndKurz(): void
    {
        $this->element();

        $html = $this->seite();

        self::assertStringContainsString('a11y-erklaerung-nicht-verfuegbar', $html);
        $ablauf = (int)$this->getConnectionPool()->getConnectionForTable('cache_pages')
            ->select(['expires'], 'cache_pages')->fetchOne();
        self::assertLessThanOrEqual(time() + Abruf::RUECKFALL_SEKUNDEN, $ablauf);
    }

    #[Test]
    public function rueckfallZeigtDieGespeicherteFassungMitIhremDatum(): void
    {
        $this->verbinden();
        $this->element();
        $this->seite();
        $this->antwort = new Response(503);
        $this->auffrischen();
        $this->getConnectionPool()->getConnectionForTable('cache_pages')->truncate('cache_pages');

        $html = $this->seite();

        self::assertStringContainsString('Version 4, Stand <time datetime="2026-10-08">08.10.2026</time>', $html);
        self::assertStringNotContainsString('nicht-verfuegbar', $html);
    }

    #[Test]
    public function htmlAusDerApiWirdBereinigt(): void
    {
        $this->antwort = self::ok([
            'html' => '<h2>Erklärung</h2><p style="color:red">Text</p><script>alert(1)</script><img src=x onerror=alert(2)><a href="javascript:alert(3)">Link</a>',
            'attribution' => '<p class="a11y-erklaerung-hinweis" onclick="alert(4)">Hinweis</p>',
        ]);
        $this->verbinden();
        $this->element();

        $html = $this->seite();

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('alert', $html);
        self::assertStringNotContainsString('onerror', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('style=', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('<p>Text</p>', $html);
        self::assertStringContainsString('<p class="a11y-erklaerung-hinweis">Hinweis</p>', $html);
    }

    private function seitenAblauf(): int
    {
        return (int)$this->getConnectionPool()->getConnectionForTable('cache_pages')
            ->select(['expires'], 'cache_pages')->fetchOne();
    }

    #[Test]
    public function seiteLebtNichtLaengerAlsDieFassungImEigenenCache(): void
    {
        // Ohne diese Grenze läge eine Freigabe bis zu 24 h unsichtbar, wo kein Scheduler läuft.
        $this->verbinden();
        $this->element();

        $this->seite();

        $ablauf = $this->seitenAblauf();
        self::assertGreaterThan(time(), $ablauf);
        self::assertLessThanOrEqual(time() + Abruf::STANDARD_SEKUNDEN, $ablauf);
    }

    #[Test]
    public function seiteFolgtDemKuerzerenMaxAgeDerApi(): void
    {
        $this->antwort = new Response(200, ['ETag' => '"e4"', 'Cache-Control' => 'private, max-age=600', 'Content-Type' => 'application/json'], (string)json_encode([
            'site_id' => self::SITE_ID,
            'declaration' => ['locale' => 'de', 'version' => 4, 'published_at' => '2026-10-08T21:29:51+00:00', 'html' => '<h2>Erklärung</h2><p>Text</p>'],
        ]));
        $this->verbinden();
        $this->element();

        $this->seite();

        self::assertLessThanOrEqual(time() + 600, $this->seitenAblauf());
    }

    #[Test]
    public function fehlerBeimLesenDesCachesGibtKeinenFehler500(): void
    {
        // Nach einem Update ohne „Datenbank vergleichen“ fehlt die Cache-Tabelle: Abruf::fassung() wirft.
        $this->verbinden();
        $this->element();
        $this->getConnectionPool()->getConnectionForTable('cache_barrierepruefung_erklaerung')->executeStatement('DROP TABLE cache_barrierepruefung_erklaerung');
        $this->getConnectionPool()->getConnectionForTable('cache_barrierepruefung_erklaerung')->executeStatement('DROP TABLE cache_barrierepruefung_erklaerung_tags');

        $html = $this->seite();

        self::assertStringContainsString('Version 4, Stand', $html, 'Die letzte Fassung aus der Registry bleibt sichtbar.');
        self::assertLessThanOrEqual(time() + Abruf::RUECKFALL_SEKUNDEN, $this->seitenAblauf());
    }

    #[Test]
    public function fehlenCacheUndRegistryZeigtDerSatzNichtVerfuegbar(): void
    {
        $this->verbinden();
        $this->element();
        $verbindung = $this->getConnectionPool()->getConnectionForTable('cache_barrierepruefung_erklaerung');
        $verbindung->executeStatement('DROP TABLE cache_barrierepruefung_erklaerung');
        $verbindung->executeStatement('DROP TABLE cache_barrierepruefung_erklaerung_tags');
        $verbindung->executeStatement('DROP TABLE sys_registry');

        $html = $this->seite();

        self::assertStringContainsString('a11y-erklaerung-nicht-verfuegbar', $html);
        self::assertLessThanOrEqual(time() + Abruf::RUECKFALL_SEKUNDEN, $this->seitenAblauf());
    }
}
