<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Functional\Command;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Lubomedia\Barrierepruefung\Command\AktualisierenCommand;
use Lubomedia\Barrierepruefung\Erklaerung\Abruf;
use Lubomedia\Barrierepruefung\Verbindung\Tresor;
use Lubomedia\Barrierepruefung\Verbindung\Verbindung;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Registry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class AktualisierenCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['fluid_styled_content'];

    protected array $testExtensionsToLoad = ['lubomedia/barrierepruefung-typo3'];

    protected array $configurationToUseInTestInstance = [
        'SYS' => ['caching' => ['cacheConfigurations' => ['pages' => ['backend' => Typo3DatabaseBackend::class]]]],
    ];

    /** @var array<string, Response> Antwort je Website-Kennung (steht im Pfad der Anfrage) */
    private array $antworten = [];

    protected function setUp(): void
    {
        parent::setUp();

        $container = $this->getContainer();
        \assert($container instanceof \Symfony\Component\DependencyInjection\Container);
        $container->set('barrierepruefung.guzzle', new GuzzleClient(['handler' => function (RequestInterface $anfrage): mixed {
            foreach ($this->antworten as $kennung => $antwort) {
                if (str_contains($anfrage->getUri()->getPath(), '/sites/' . $kennung . '/')) {
                    return Create::promiseFor($antwort);
                }
            }

            return Create::promiseFor(new Response(500));
        }]));
    }

    private function verbinden(string $site, string $siteId, string $schluessel = 'i-am-not-a-secure-encryption-key'): void
    {
        $this->get(Verbindungen::class)->speichern(new Verbindung($site, $siteId, 'https://dienst.example/api/v1', (new Tresor($schluessel))->verschluesseln('tok-123'), [], 'm', 'd', time()));
    }

    private static function ok(string $siteId, int $version, string $etag): Response
    {
        return new Response(200, ['ETag' => $etag, 'Cache-Control' => 'private, max-age=3600', 'Content-Type' => 'application/json'], (string)json_encode([
            'site_id' => $siteId,
            'declaration' => ['locale' => 'de', 'version' => $version, 'published_at' => '2026-10-08T21:29:51+00:00', 'html' => '<h2>Erklärung</h2>'],
        ]));
    }

    private function gespeichert(string $siteId, int $version, string $etag): void
    {
        $this->get(Registry::class)->set(Abruf::REGISTRY, 'fassung_' . $siteId, [
            'erklaerung' => ['locale' => 'de', 'version' => $version, 'published_at' => '2026-10-08T21:29:51+00:00', 'html' => '<h2>Erklärung</h2>'],
            'etag' => $etag,
            'abgerufen_am' => 1_700_000_000,
        ]);
    }

    private function ausfuehren(): CommandTester
    {
        $tester = new CommandTester($this->get(AktualisierenCommand::class));
        $tester->execute([]);

        return $tester;
    }

    #[Test]
    public function meldetJeSiteEineZeile(): void
    {
        $this->verbinden('stadt', 'S1');
        $this->verbinden('kreis', 'S2');
        $this->gespeichert('S1', 3, '"e3"');
        $this->gespeichert('S2', 5, '"e5"');
        $this->antworten = ['S1' => self::ok('S1', 4, '"e4"'), 'S2' => new Response(304, ['ETag' => '"e5"'])];
        $seiten = $this->get(CacheManager::class)->getCache('pages');
        $seiten->set('seite_s1', 'inhalt', [Abruf::tag('S1')]);
        $seiten->set('seite_s2', 'inhalt', [Abruf::tag('S2')]);

        $tester = $this->ausfuehren();

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['kreis: Fassung 5 (unverändert)', 'stadt: Fassung 4 (neu)'], array_values(array_filter(array_map('trim', explode("\n", $tester->getDisplay())))));
        self::assertFalse($seiten->has('seite_s1'), 'Die neue Fassung leert genau die Seiten dieser Site.');
        self::assertTrue($seiten->has('seite_s2'));
    }

    #[Test]
    public function ausfallIstKeinFehlercode(): void
    {
        $this->verbinden('stadt', 'S1');
        $this->gespeichert('S1', 3, '"e3"');
        $this->antworten = ['S1' => new Response(503, ['Content-Type' => 'application/problem+json'], '{"title":"Dienst nicht erreichbar","status":503}')];

        $tester = $this->ausfuehren();

        self::assertSame(0, $tester->getStatusCode(), 'Der Rückfall ist der Normalbetrieb.');
        self::assertMatchesRegularExpression('/^stadt: Fassung 3 \(Rückfall: .+\)$/m', $tester->getDisplay());
    }

    #[Test]
    public function ohneGuteFassungKeine(): void
    {
        $this->verbinden('stadt', 'S1');
        $this->antworten = ['S1' => new Response(503)];

        $tester = $this->ausfuehren();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('stadt: Fassung - (keine)', $tester->getDisplay());
    }

    #[Test]
    public function tresorFehlerWirdGenannt(): void
    {
        $this->verbinden('stadt', 'S1', 'ein-anderer-schluessel');
        $this->gespeichert('S1', 3, '"e3"');

        $tester = $this->ausfuehren();

        self::assertStringContainsString('Verbindung neu herstellen', $tester->getDisplay());
        self::assertStringContainsString('stadt: Fassung 3 (Rückfall:', $tester->getDisplay());
    }

    #[Test]
    public function einsNurWennKeineVerbindungLesbarWar(): void
    {
        $this->verbinden('stadt', 'S1', 'ein-anderer-schluessel');
        self::assertSame(1, $this->ausfuehren()->getStatusCode());

        $this->verbinden('kreis', 'S2');
        $this->antworten = ['S2' => self::ok('S2', 1, '"e1"')];
        self::assertSame(0, $this->ausfuehren()->getStatusCode(), 'Eine lesbare Verbindung genügt.');
    }

    #[Test]
    public function ohneVerbindungNichtsZuTun(): void
    {
        $tester = $this->ausfuehren();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Keine Verbindung', $tester->getDisplay());
    }

    #[Test]
    public function derBefehlIstPlanbar(): void
    {
        $befehle = $this->get(CommandRegistry::class);

        self::assertArrayHasKey('barrierepruefung:aktualisieren', iterator_to_array($befehle->getSchedulableCommands()));
        self::assertStringContainsString('stündlich', $befehle->getCommandByIdentifier('barrierepruefung:aktualisieren')->getDescription() . $befehle->getCommandByIdentifier('barrierepruefung:aktualisieren')->getHelp());
    }
}
