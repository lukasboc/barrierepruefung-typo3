<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Functional\Middleware;

use Lubomedia\Barrierepruefung\Verbindung\Verbindung;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class VerifikationTest extends FunctionalTestCase
{
    private const DATEI = '/.well-known/a11y-site-verification.txt';

    protected array $coreExtensionsToLoad = ['fluid_styled_content'];

    protected array $testExtensionsToLoad = ['barrierepruefungde/typo3'];

    protected function setUp(): void
    {
        parent::setUp();

        $seiten = $this->getConnectionPool()->getConnectionForTable('pages');
        // Seite 1: Wurzel der Site „stadt“, 2: /kontakt darunter, 3: Wurzel der unverbundenen Site „andere“.
        $seiten->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Start', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1, 'sys_language_uid' => 0]);
        $seiten->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Kontakt', 'slug' => '/kontakt', 'doktype' => 1, 'sys_language_uid' => 0]);
        $seiten->insert('pages', ['uid' => 4, 'pid' => 1, 'title' => 'Start', 'slug' => '/', 'doktype' => 1, 'sys_language_uid' => 1, 'l10n_parent' => 1]);
        $seiten->insert('pages', ['uid' => 3, 'pid' => 0, 'title' => 'Andere', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1, 'sys_language_uid' => 0]);
        foreach ([1, 3] as $wurzel) {
            $this->setUpFrontendRootPage($wurzel);
            $this->addTypoScriptToTemplateRecord($wurzel, 'page = PAGE' . LF . 'page.10 = TEXT' . LF . 'page.10.value = Hallo');
        }

        $schreiber = $this->get(SiteWriter::class);
        $schreiber->write('stadt', [
            'rootPageId' => 1,
            'base' => 'https://stadt.de/',
            'languages' => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'English', 'locale' => 'en_GB.UTF-8', 'base' => 'https://en.stadt.de/'],
            ],
        ]);
        $schreiber->write('andere', [
            'rootPageId' => 3,
            'base' => 'https://andere.de/',
            'languages' => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/'],
            ],
        ]);
    }

    private function verbinden(string $meta = 'meta-xyz', string $datei = 'datei-abc'): void
    {
        $this->get(Verbindungen::class)->speichern(new Verbindung('stadt', '01SITE', 'https://dienst.example/api/v1', 'x', [], $meta, $datei, time()));
    }

    private function abrufen(string $adresse): \Psr\Http\Message\ResponseInterface
    {
        return $this->executeFrontendSubRequest(new InternalRequest($adresse));
    }

    #[Test]
    public function lieferDateiNurFuerVerbundeneSite(): void
    {
        $this->verbinden();

        $antwort = $this->abrufen('https://stadt.de' . self::DATEI);
        self::assertSame(200, $antwort->getStatusCode());
        self::assertSame('datei-abc', (string)$antwort->getBody());
        self::assertStringStartsWith('text/plain', $antwort->getHeaderLine('Content-Type'));
        self::assertStringContainsString('no-store', $antwort->getHeaderLine('Cache-Control'));

        $andere = $this->abrufen('https://andere.de' . self::DATEI);
        self::assertSame(404, $andere->getStatusCode());
        self::assertStringNotContainsString('datei-abc', (string)$andere->getBody());
    }

    #[Test]
    public function dateiTraegtDenDateiTokenNichtDenMetaToken(): void
    {
        $this->verbinden();

        $rumpf = (string)$this->abrufen('https://stadt.de' . self::DATEI)->getBody();
        self::assertNotSame('meta-xyz', $rumpf);
        self::assertSame('datei-abc', $rumpf);
    }

    #[Test]
    public function dateiAuchAufSprachHost(): void
    {
        $this->verbinden();

        $antwort = $this->abrufen('https://en.stadt.de' . self::DATEI);
        self::assertSame(200, $antwort->getStatusCode());
        self::assertSame('datei-abc', (string)$antwort->getBody());
    }

    #[Test]
    public function metaNurAufDerStartseite(): void
    {
        $this->verbinden();

        $start = (string)$this->abrufen('https://stadt.de/')->getBody();
        self::assertSame(1, substr_count($start, '<meta name="a11y-site-verification" content="meta-xyz">'));
        self::assertLessThan(strpos($start, '</head>'), strpos($start, 'a11y-site-verification'));

        $unterseite = (string)$this->abrufen('https://stadt.de/kontakt')->getBody();
        self::assertStringNotContainsString('a11y-site-verification', $unterseite);

        $andere = (string)$this->abrufen('https://andere.de/')->getBody();
        self::assertStringNotContainsString('a11y-site-verification', $andere);
    }

    #[Test]
    public function metaAuchAufDerStartseiteDerSprache(): void
    {
        $this->verbinden();

        $englisch = (string)$this->abrufen('https://en.stadt.de/')->getBody();
        self::assertSame(1, substr_count($englisch, 'a11y-site-verification'));
    }

    #[Test]
    public function metaAuchAusDemSeitencache(): void
    {
        $this->verbinden();

        $erst = (string)$this->abrufen('https://stadt.de/')->getBody();
        $zweit = (string)$this->abrufen('https://stadt.de/')->getBody();
        self::assertSame(1, substr_count($erst, 'a11y-site-verification'));
        self::assertSame(1, substr_count($zweit, 'a11y-site-verification'));
    }

    #[Test]
    public function metaErscheintNachDemVerbindenAuchWennDieSeiteImCacheLag(): void
    {
        $vorher = (string)$this->abrufen('https://stadt.de/')->getBody();
        self::assertStringNotContainsString('a11y-site-verification', $vorher);

        $this->verbinden();
        $nachher = (string)$this->abrufen('https://stadt.de/')->getBody();
        self::assertSame(1, substr_count($nachher, 'a11y-site-verification'));
    }

    #[Test]
    public function metaWirdEscaped(): void
    {
        $this->verbinden('a"b');

        $start = (string)$this->abrufen('https://stadt.de/')->getBody();
        self::assertStringContainsString('content="a&quot;b"', $start);
    }

    #[Test]
    public function metaKommtNurEinmal(): void
    {
        $this->verbinden();
        $this->addTypoScriptToTemplateRecord(1, 'page.headerData.10 = TEXT' . LF . 'page.headerData.10.value = <meta name="a11y-site-verification" content="schon-da">');

        $start = (string)$this->abrufen('https://stadt.de/')->getBody();
        self::assertSame(1, substr_count($start, 'a11y-site-verification'));
        self::assertStringContainsString('schon-da', $start);
    }
}
