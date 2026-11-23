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
use Lubomedia\Barrierepruefung\Verbindung\TresorFehler;
use Lubomedia\Barrierepruefung\Verbindung\Verbinden;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class VerbindenTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['fluid_styled_content'];

    protected array $testExtensionsToLoad = ['barrierepruefungde/typo3'];

    /** @var list<Request> */
    private array $anfragen = [];

    private const DIENST = 'https://dienst.example/api/v1';

    private ?MockHandler $mock = null;

    /**
     * Ersetzt den HTTP-Client beim ersten Aufruf; danach werden nur die Antworten neu gefüllt
     * (Symfony erlaubt set() nur, solange der Dienst noch nicht gebaut wurde).
     *
     * @param list<Response|\Throwable> $antworten
     */
    private function verbinden(array $antworten): Verbinden
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

        return $this->get(Verbinden::class);
    }

    /**
     * @param list<array<string, mixed>>|null $sprachen
     */
    private function site(string $basis = 'https://stadt.de/', ?array $sprachen = null): Site
    {
        return new Site('stadt', 1, [
            'base' => $basis,
            'languages' => $sprachen ?? [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/'],
            ],
        ]);
    }

    private function website(string $baseUrl = 'https://stadt.de'): Response
    {
        return new Response(200, [], (string)json_encode(['data' => [
            'id' => '01SITE', 'base_url' => $baseUrl, 'verified' => true, 'verified_at' => '2026-09-21T15:49:48+00:00',
            'token' => ['abilities' => ['sites:read', 'declaration:prepare'], 'publish' => ['state' => 'recht_fehlt']],
        ]]));
    }

    private function nachweis(): Response
    {
        return new Response(200, [], (string)json_encode(['data' => [
            'meta_tag' => ['name' => 'a11y-site-verification', 'content' => 'meta-xyz'],
            'file' => ['path' => '/.well-known/a11y-site-verification.txt', 'content' => 'datei-abc'],
        ]]));
    }

    private function zeilen(): int
    {
        return (int)$this->getConnectionPool()->getConnectionForTable(Verbindungen::TABELLE)
            ->count('*', Verbindungen::TABELLE, []);
    }

    #[Test]
    public function speichertTokenVerschluesselt(): void
    {
        $ergebnis = $this->verbinden([$this->website(), $this->nachweis()])
            ->verbinden($this->site(), self::DIENST, '01SITE', 'tok-123');

        self::assertTrue($ergebnis->ok);
        $zeile = $this->getConnectionPool()->getConnectionForTable(Verbindungen::TABELLE)
            ->select(['*'], Verbindungen::TABELLE)->fetchAssociative();
        self::assertIsArray($zeile);
        self::assertNotSame('tok-123', $zeile['token_verschluesselt']);
        self::assertStringNotContainsString('tok-123', (string)json_encode($zeile));
        self::assertSame('meta-xyz', $zeile['nachweis_meta']);
        self::assertSame('datei-abc', $zeile['nachweis_datei']);

        $verbindungen = $this->get(Verbindungen::class);
        $verbindung = $verbindungen->fuerSite('stadt');
        self::assertNotNull($verbindung);
        self::assertSame('tok-123', $verbindungen->zugang($verbindung)->token);
        self::assertSame('01SITE', $verbindungen->zugang($verbindung)->siteId);
        self::assertSame('recht_fehlt', $verbindung->freigaberecht());
        self::assertTrue($verbindung->domainBestaetigtAm !== null);
        self::assertSame('Bearer tok-123', $this->anfragen[0]->getHeaderLine('Authorization'));
    }

    #[Test]
    public function verbindenZweimalErsetztDieZeile(): void
    {
        $this->verbinden([$this->website(), $this->nachweis()])->verbinden($this->site(), self::DIENST, '01SITE', 'alt');
        $this->verbinden([$this->website(), $this->nachweis()])->verbinden($this->site(), self::DIENST, '01SITE', 'neu');

        self::assertSame(1, $this->zeilen());
        $v = $this->get(Verbindungen::class);
        $verbindung = $v->fuerSite('stadt');
        self::assertNotNull($verbindung);
        self::assertSame('neu', $v->zugang($verbindung)->token);
    }

    #[Test]
    public function lehntAbweichendeAdresseAbUndNenntBeide(): void
    {
        $ergebnis = $this->verbinden([$this->website('https://andere.de')])
            ->verbinden($this->site(), self::DIENST, '01SITE', 'tok-123');

        self::assertFalse($ergebnis->ok);
        self::assertSame('adresse_abweichend', $ergebnis->grund);
        self::assertSame('https://stadt.de/', $ergebnis->typo3Adresse);
        self::assertSame('https://andere.de', $ergebnis->dienstAdresse);
        self::assertSame(0, $this->zeilen());
    }

    #[Test]
    public function lehntSiteOhneHostAb(): void
    {
        $ergebnis = $this->verbinden([])->verbinden($this->site('/'), self::DIENST, '01SITE', 'tok-123');

        self::assertFalse($ergebnis->ok);
        self::assertSame('adresse_ohne_host', $ergebnis->grund);
        self::assertSame([], $this->anfragen);
        self::assertSame(0, $this->zeilen());
    }

    #[Test]
    public function nimmtDieAdresseDerStandardsprache(): void
    {
        $site = $this->site('https://stadt.de/', [
            ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/'],
            ['languageId' => 1, 'title' => 'English', 'locale' => 'en_GB.UTF-8', 'base' => 'https://en.stadt.de/'],
        ]);

        // Der Dienst kennt die englische Domain, die Site-Basis ist aber stadt.de: abgelehnt, mit der Standardadresse.
        $ergebnis = $this->verbinden([$this->website('https://en.stadt.de')])->verbinden($site, self::DIENST, '01SITE', 't');
        self::assertFalse($ergebnis->ok);
        self::assertSame('https://stadt.de/', $ergebnis->typo3Adresse);

        $ergebnis = $this->verbinden([$this->website('https://stadt.de'), $this->nachweis()])->verbinden($site, self::DIENST, '01SITE', 't');
        self::assertTrue($ergebnis->ok);
    }

    #[Test]
    public function lehntHttpAusserhalbDevelopmentAb(): void
    {
        $http = 'http://dienst.example/api/v1';

        $ergebnis = $this->verbinden([])->verbinden($this->site(), $http, '01SITE', 't');
        self::assertSame('dienst_unsicher', $ergebnis->grund);
        self::assertSame([], $this->anfragen);

        $vorher = Environment::getContext();
        $this->kontext('Development');
        try {
            $ergebnis = $this->verbinden([$this->website(), $this->nachweis()])->verbinden($this->site(), $http, '01SITE', 't');
            self::assertTrue($ergebnis->ok);
        } finally {
            $this->kontext((string)$vorher);
        }
    }

    #[Test]
    public function lehntUnlesbareDienstadresseAb(): void
    {
        foreach (['dienst.example/api', 'ftp://dienst.example', 'https://dienst .example/api', 'https://', ''] as $adresse) {
            $ergebnis = $this->verbinden([])->verbinden($this->site(), $adresse, '01SITE', 't');
            self::assertSame('dienst_adresse_ungueltig', $ergebnis->grund, $adresse);
            self::assertSame([], $this->anfragen);
        }
    }

    #[Test]
    public function meldetApiFehlerMitReason(): void
    {
        $antwort = new Response(403, ['Content-Type' => 'application/problem+json'], (string)json_encode([
            'status' => 403, 'detail' => 'Kein Recht.', 'reason' => 'recht_fehlt',
        ]));
        $ergebnis = $this->verbinden([$antwort])->verbinden($this->site(), self::DIENST, '01SITE', 't');

        self::assertFalse($ergebnis->ok);
        self::assertSame('api:recht_fehlt', $ergebnis->grund);
        self::assertSame(0, $this->zeilen());
    }

    #[Test]
    public function meldetNichtErreichbar(): void
    {
        $ergebnis = $this->verbinden([new ConnectException('weg', new Request('GET', self::DIENST))])
            ->verbinden($this->site(), self::DIENST, '01SITE', 't');

        self::assertFalse($ergebnis->ok);
        self::assertSame('nicht_erreichbar', $ergebnis->grund);
        self::assertSame(0, $this->zeilen());
    }

    #[Test]
    public function dienstOhneAdresseIstAbweichend(): void
    {
        $ergebnis = $this->verbinden([new Response(200, [], '{}')])->verbinden($this->site(), self::DIENST, '01SITE', 't');

        self::assertSame('adresse_abweichend', $ergebnis->grund);
    }

    #[Test]
    public function falscherSchluesselMachtDenTokenUnlesbar(): void
    {
        $this->verbinden([$this->website(), $this->nachweis()])->verbinden($this->site(), self::DIENST, '01SITE', 'tok-123');

        $alt = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'ein-ganz-anderer-schluessel';
        try {
            $verbindungen = new Verbindungen($this->get(ConnectionPool::class), Tresor::ausInstallation());
            $verbindung = $verbindungen->fuerSite('stadt');
            self::assertNotNull($verbindung);
            $this->expectException(TresorFehler::class);
            $verbindungen->zugang($verbindung);
        } finally {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $alt;
        }
    }

    #[Test]
    public function faehigkeitenAktualisierenUndRechte(): void
    {
        $this->verbinden([$this->website(), $this->nachweis()])->verbinden($this->site(), self::DIENST, '01SITE', 't');
        $v = $this->get(Verbindungen::class);
        self::assertFalse($v->fuerSite('stadt')?->darfVorbereiten());

        $v->faehigkeitenAktualisieren('stadt', [
            'abilities' => ['declaration:prepare', 'declaration:publish'],
            'publish' => ['state' => 'erlaubt', 'granted_by' => 'X'],
        ]);
        $neu = $v->fuerSite('stadt');
        self::assertTrue($neu?->darfVorbereiten());
        self::assertTrue($neu->darfFreigeben());

        $v->trennen('stadt');
        self::assertNull($v->fuerSite('stadt'));
    }

    #[Test]
    public function ungueltigeWebsiteKennungHatEigenenGrund(): void
    {
        foreach (['', '   ', '01SITE/../x', 'ä01'] as $kennung) {
            $ergebnis = $this->verbinden([])->verbinden($this->site(), self::DIENST, $kennung, 't');
            self::assertFalse($ergebnis->ok);
            self::assertSame('site_id_ungueltig', $ergebnis->grund, $kennung);
            self::assertSame([], $this->anfragen);
        }
        $ergebnis = $this->verbinden([])->verbinden($this->site(), 'kein-url', '01SITE', 't');
        self::assertSame('dienst_adresse_ungueltig', $ergebnis->grund);
    }

    #[Test]
    public function leeresTokenWirdVorDemVerschluesselnAbgewiesen(): void
    {
        foreach (['', "  \n"] as $token) {
            $ergebnis = $this->verbinden([])->verbinden($this->site(), self::DIENST, '01SITE', $token);
            self::assertFalse($ergebnis->ok);
            self::assertSame('token_fehlt', $ergebnis->grund);
            self::assertSame([], $this->anfragen);
            self::assertSame(0, $this->zeilen());
        }
    }

    #[Test]
    public function ohneEncryptionKeyKeinAusnahmeSondernGrund(): void
    {
        $this->verbinden([$this->website(), $this->nachweis()]);
        $verbinden = new Verbinden($this->get(\Lubomedia\Barrierepruefung\Api\Client::class), $this->get(Verbindungen::class), new Tresor(''));

        $ergebnis = $verbinden->verbinden($this->site(), self::DIENST, '01SITE', 'tok-123');

        self::assertFalse($ergebnis->ok);
        self::assertSame('schluessel_fehlt', $ergebnis->grund);
        self::assertSame([], $this->anfragen);
        self::assertSame(0, $this->zeilen());
    }

    private function kontext(string $name): void
    {
        $r = new \ReflectionProperty(Environment::class, 'context');
        $r->setValue(null, new ApplicationContext($name));
    }
}
