<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Functional\Controller;

use GuzzleHttp\Psr7\Response;
use Lubomedia\Barrierepruefung\Erklaerung\Abruf;
use Lubomedia\Barrierepruefung\Verbindung\Tresor;
use Lubomedia\Barrierepruefung\Verbindung\Verbindung;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Registry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Die Freigabe im Untermodul „Weg zur Erklärung“: Vorschau, Aussage zur Konformität und das Veröffentlichen mit
 * `Idempotency-Key`, `draft_etag`, `expected_version` und `actor_name`. Eine Fassung, die niemand gesehen hat, oder
 * zwei Fassungen aus einem Doppelklick entstehen nie; ohne das Recht des Tokens gibt es keinen Knopf.
 */
final class FreigabeTest extends FunctionalTestCase
{
    use Modulaufrufe;

    private const ROUTE = 'barrierepruefung_weg';
    private const FREIGABE = 'barrierepruefung_weg.freigabe';
    private const FREIGEBEN = 'barrierepruefung_weg.freigeben';
    private const DIENST = 'https://dienst.example/api/v1';
    private const API = '/api/v1/';
    private const ADMIN = 1;
    private const ETAG = '8b32d44b7ca691a93d982f481d5fa5cf';
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    protected array $coreExtensionsToLoad = ['fluid_styled_content'];

    protected array $testExtensionsToLoad = ['lubomedia/barrierepruefung-typo3'];

    /** Ein echter Seitencache: das Testsystem merkt sich sonst nichts, und „aus dem Cache gefallen“ wäre immer wahr. */
    protected array $configurationToUseInTestInstance = [
        'SYS' => ['caching' => ['cacheConfigurations' => ['pages' => ['backend' => Typo3DatabaseBackend::class]]]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->get(SiteWriter::class)->write('a', [
            'rootPageId' => 1,
            'base' => 'https://stadt-a.de/',
            'websiteTitle' => 'Stadt A',
            'languages' => [['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/']],
        ]);
    }

    /**
     * @param list<string> $rechte
     */
    private function verbinde(string $freigabe = 'erlaubt', array $rechte = ['declaration:prepare', 'declaration:publish']): void
    {
        $this->get(Verbindungen::class)->speichern(new Verbindung(
            'a',
            '01SITEA',
            self::DIENST,
            Tresor::ausInstallation()->verschluesseln('tok-a'),
            $this->token($freigabe, $rechte),
            'meta-a',
            'datei-a',
            1762128000,
        ));
    }

    /**
     * @param list<string> $rechte
     * @return array<string, mixed>
     */
    private function token(string $freigabe, array $rechte = ['declaration:prepare', 'declaration:publish']): array
    {
        return ['abilities' => ['sites:read', 'scans:read', 'declaration:read', ...$rechte], 'publish' => ['state' => $freigabe]];
    }

    /**
     * @param array<mixed>          $daten
     * @param array<string, string> $kopf
     */
    private function json(array $daten, int $status = 200, array $kopf = []): Response
    {
        return new Response($status, $kopf + ['Content-Type' => $status >= 400 ? 'application/problem+json' : 'application/json'], (string)json_encode($daten));
    }

    /**
     * @return array<mixed>
     */
    private function aufnahme(string $name): array
    {
        $daten = json_decode((string)file_get_contents(__DIR__ . '/../../../Build/e2e/antworten/' . $name . '.json'), true);
        \assert(is_array($daten));

        return $daten;
    }

    /**
     * @param list<string> $rechte
     * @return array<string, ResponseInterface>
     */
    private function dienst(string $freigabe = 'erlaubt', array $rechte = ['declaration:prepare', 'declaration:publish']): array
    {
        $website = $this->aufnahme('site');
        $website['data']['token'] = $this->token($freigabe, $rechte);

        return [
            'GET ' . self::API . 'sites/01SITEA' => $this->json($website),
            // Alle Schritte erledigt (Aufnahme), Fassung 4 veröffentlicht.
            'GET ' . self::API . 'sites/01SITEA/declaration/path' => $this->json($this->aufnahme('path')),
            'GET ' . self::API . 'sites/01SITEA/declaration/draft' => $this->json($this->entwurf()),
        ];
    }

    /**
     * Der Entwurf der Aufnahme, mit einem Skript und einer h2, die zur h4 werden muss.
     *
     * @param array<string, mixed> $anders
     * @return array<mixed>
     */
    private function entwurf(array $anders = []): array
    {
        $daten = $this->aufnahme('draft');
        $daten['data']['html'] = '<h1>Erklärung zur Barrierefreiheit</h1><p>Beispiel GmbH ist bemüht.</p><script>alert(1)</script><h2>Geltungsbereich dieser Erklärung</h2><p>Gilt für die Website.</p>';
        $daten['data'] = $anders + $daten['data'];

        return $daten;
    }

    /**
     * @param array<string, string> $kopf
     */
    private function veroeffentlicht(int $fassung = 5, array $kopf = []): Response
    {
        return $this->json(['data' => [
            'version' => $fassung,
            'conformance_status' => 'none',
            'published_at' => '2026-11-23T10:00:00+00:00',
            'public_url' => 'https://dienst.example/e/beispiel-test',
            'pdf_url' => 'https://dienst.example/e/beispiel-test.pdf',
        ]], 201, $kopf);
    }

    private function html(ResponseInterface $antwort): string
    {
        self::assertSame(200, $antwort->getStatusCode(), substr((string)$antwort->getBody(), 0, 2000));

        return (string)$antwort->getBody();
    }

    /**
     * @return list<\DOMElement>
     */
    private function suche(string $html, string $pfad): array
    {
        $treffer = (new \DOMXPath($this->dom($html)))->query($pfad);
        $elemente = [];
        foreach ($treffer !== false ? $treffer : [] as $knoten) {
            if ($knoten instanceof \DOMElement) {
                $elemente[] = $knoten;
            }
        }

        return $elemente;
    }

    private function text(string $html): string
    {
        return (string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html)));
    }

    private function inhalt(string $html): string
    {
        $koerper = $this->suche($html, '//div[contains(concat(" ", normalize-space(@class), " "), " module-body ")]');
        self::assertCount(1, $koerper, 'genau ein .module-body');

        return (string)$koerper[0]->ownerDocument?->saveHTML($koerper[0]);
    }

    /**
     * @return list<RequestInterface>
     */
    private function freigaben(): array
    {
        return array_values(array_filter(
            $this->anfragen,
            static fn(RequestInterface $a): bool => $a->getMethod() === 'POST' && $a->getUri()->getPath() === self::API . 'sites/01SITEA/declaration/publish',
        ));
    }

    /**
     * @return array<mixed>
     */
    private function rumpf(RequestInterface $anfrage): array
    {
        $rumpf = json_decode((string)$anfrage->getBody(), true);
        self::assertIsArray($rumpf);

        return $rumpf;
    }

    private function schluessel(string $html): string
    {
        $feld = $this->suche($html, '//form[@id="barrierepruefung-freigabe"]//input[@type="hidden"][@name="idempotency_key"]');
        self::assertCount(1, $feld, 'der Schlüssel steht im Formular');

        return $feld[0]->getAttribute('value');
    }

    #[Test]
    public function sendetAlleVierAngaben(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->veroeffentlicht();
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::FREIGABE, ['site' => 'a']));
        $schluessel = $this->schluessel($html);
        self::assertMatchesRegularExpression(self::UUID, $schluessel);
        self::assertCount(1, $this->suche($html, '//form[@id="barrierepruefung-freigabe"]//button[@type="submit"][contains(.,"Fassung 5")]'), 'der Knopf nennt die Fassung');

        $antwort = $this->formularAbsenden($html, 'barrierepruefung-freigabe');
        self::assertSame(303, $antwort->getStatusCode());

        $gesendet = $this->freigaben();
        self::assertCount(1, $gesendet);
        self::assertSame($schluessel, $gesendet[0]->getHeaderLine('Idempotency-Key'));
        $rumpf = $this->rumpf($gesendet[0]);
        self::assertSame(self::ETAG, $rumpf['draft_etag']);
        self::assertSame(5, $rumpf['expected_version']);
        self::assertSame('Ada Admin', $rumpf['actor_name'], 'realName der Backend-Person');
        // Die ermittelte Aussage bleibt: keine Abweichung, also auch keine Begründung.
        self::assertArrayNotHasKey('conformance_status', $rumpf);
        self::assertArrayNotHasKey('override_reason', $rumpf);
    }

    #[Test]
    public function ohneRealNameDerBenutzername(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_users')->update('be_users', ['realName' => ''], ['uid' => self::ADMIN]);
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->veroeffentlicht();
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $this->formularAbsenden($this->html($this->aufrufen(self::FREIGABE, ['site' => 'a'])), 'barrierepruefung-freigabe');

        self::assertSame('admin', $this->rumpf($this->freigaben()[0])['actor_name']);
    }

    #[Test]
    public function schluesselEntstehtBeimAnzeigen(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->veroeffentlicht();
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $erste = $this->html($this->aufrufen(self::FREIGABE, ['site' => 'a']));
        $zweite = $this->html($this->aufrufen(self::FREIGABE, ['site' => 'a']));
        self::assertNotSame($this->schluessel($erste), $this->schluessel($zweite), 'jede Anzeige ein neuer Schlüssel');

        // Doppelklick: dasselbe Formular zweimal abgeschickt trägt zweimal denselben Schlüssel.
        $this->attrappe($antworten);
        $this->formularAbsenden($zweite, 'barrierepruefung-freigabe');
        $this->formularAbsenden($zweite, 'barrierepruefung-freigabe');
        $gesendet = $this->freigaben();
        self::assertCount(2, $gesendet);
        self::assertSame($this->schluessel($zweite), $gesendet[0]->getHeaderLine('Idempotency-Key'));
        self::assertSame($this->schluessel($zweite), $gesendet[1]->getHeaderLine('Idempotency-Key'));
    }

    #[Test]
    public function ohneSchluesselGehtNichtsRaus(): void
    {
        $this->verbinde();
        $this->attrappe($this->dienst());
        $this->anmelden(self::ADMIN);

        // Ein Formular ohne (oder mit gebasteltem) Schlüssel: ein neuer Schlüssel hier wäre bei jedem Absenden ein anderer.
        foreach (['', 'kein-schluessel'] as $wert) {
            $antwort = $this->aufrufen(self::FREIGEBEN, [], ['site' => 'a', 'draft_etag' => self::ETAG, 'expected_version' => '5', 'idempotency_key' => $wert]);
            self::assertSame(303, $antwort->getStatusCode());
            self::assertStringContainsString('freigabe', $antwort->getHeaderLine('Location'));
        }
        self::assertSame([], $this->freigaben());
    }

    #[Test]
    public function erfolgFrischtDieErklaerungAuf(): void
    {
        $this->verbinde();
        $this->get(Registry::class)->set(Abruf::REGISTRY, 'fassung_01SITEA', [
            'erklaerung' => ['locale' => 'de', 'version' => 4, 'html' => '<h1>Erklärung</h1>', 'published_at' => '2026-10-08T21:29:51+00:00'],
            'etag' => '"e4"',
            'abgerufen_am' => 1762128000,
        ]);
        $seiten = $this->get(CacheManager::class)->getCache('pages');
        $seiten->set('seite_a', 'inhalt', [Abruf::tag('01SITEA')]);
        $seiten->set('seite_andere', 'inhalt', [Abruf::tag('01SITEB')]);
        self::assertTrue($seiten->has('seite_a'), 'Vorbedingung: der Seitencache hält Einträge');

        $erklaerung = $this->aufnahme('declaration');
        $erklaerung['declaration']['version'] = 5;
        $antworten = $this->dienst();
        $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->veroeffentlicht();
        $antworten['GET ' . self::API . 'sites/01SITEA/declaration'] = $this->json($erklaerung, 200, ['ETag' => '"e5"', 'Cache-Control' => 'max-age=3600']);
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $antwort = $this->formularAbsenden($this->html($this->aufrufen(self::FREIGABE, ['site' => 'a'])), 'barrierepruefung-freigabe');

        $gespeichert = $this->get(Registry::class)->get(Abruf::REGISTRY, 'fassung_01SITEA');
        self::assertIsArray($gespeichert);
        self::assertSame('"e5"', $gespeichert['etag'], 'neue ETag in der Registry');
        self::assertSame(5, $gespeichert['erklaerung']['version'] ?? null);
        self::assertFalse($seiten->has('seite_a'), 'Seiten mit dem Element fallen aus dem Cache');
        self::assertTrue($seiten->has('seite_andere'));

        $danach = $this->html($this->folgen($antwort));
        self::assertCount(1, $this->suche($danach, '//*[@role="status"][contains(.,"Fassung 5 ist veröffentlicht")]'));
    }

    #[Test]
    public function wiederholungErgibtEineFassung(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        // Der Dienst liefert beim zweiten Absenden mit demselben Schlüssel die gespeicherte Antwort.
        $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->veroeffentlicht(5, ['Idempotent-Replayed' => 'true']);
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $danach = $this->html($this->folgen($this->formularAbsenden($this->html($this->aufrufen(self::FREIGABE, ['site' => 'a'])), 'barrierepruefung-freigabe')));
        $meldung = $this->suche($danach, '//*[@role="status"]');
        self::assertCount(1, $meldung);
        self::assertStringContainsString('Fassung 5 ist veröffentlicht', $meldung[0]->textContent);
        self::assertStringContainsString('nur einmal', $meldung[0]->textContent);
    }

    /**
     * Der Weg, wie er nach einer Freigabe ohne Antwort aussieht: veröffentlicht ist Fassung $fassung.
     */
    private function wegMitFassung(int $fassung): Response
    {
        $weg = $this->aufnahme('path');
        $weg['data']['published']['version'] = $fassung;

        return $this->json($weg);
    }

    private function zeitueberlauf(): Response
    {
        return $this->json(['message' => 'Gateway Timeout'], 504);
    }

    #[Test]
    public function ohneAntwortAberVeroeffentlichtGiltAlsErfolg(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->zeitueberlauf();
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::FREIGABE, ['site' => 'a']));
        // Die Fassung ist angelegt, nur die Antwort kam nicht an: der Weg nennt sie schon.
        $antworten['GET ' . self::API . 'sites/01SITEA/declaration/path'] = $this->wegMitFassung(5);
        $this->attrappe($antworten);

        $antwort = $this->formularAbsenden($html, 'barrierepruefung-freigabe');

        self::assertStringNotContainsString('freigabe', $antwort->getHeaderLine('Location'), 'zur Übersicht wie bei Erfolg');
        self::assertCount(1, $this->anfragenAn('/declaration/path'), 'einmal nachgefragt');
        self::assertCount(1, array_filter($this->anfragen, static fn(RequestInterface $a): bool => $a->getMethod() === 'GET' && $a->getUri()->getPath() === self::API . 'sites/01SITEA/declaration'), 'die Erklärung wird aufgefrischt');
        $danach = $this->html($this->folgen($antwort));
        self::assertCount(1, $this->suche($danach, '//*[@role="status"][contains(.,"Fassung 5 ist veröffentlicht")]'));
        self::assertStringNotContainsString('Nicht veröffentlicht', $this->text($danach));
    }

    #[Test]
    public function offenerAusgangBehaeltDenSchluessel(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->zeitueberlauf();
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::FREIGABE, ['site' => 'a']));
        $schluessel = $this->schluessel($html);
        // Der Weg steht noch bei Fassung 4: ob 5 entstanden ist, weiß niemand.
        $antwort = $this->formularAbsenden($html, 'barrierepruefung-freigabe');
        self::assertStringContainsString('freigabe', $antwort->getHeaderLine('Location'));

        $danach = $this->html($this->folgen($antwort));
        self::assertCount(1, $this->suche($danach, '//*[@role="alert"][contains(.,"ob Fassung 5 veröffentlicht ist, ist deshalb offen")]'), 'der Ausgang heißt offen');
        self::assertStringNotContainsString('Nicht veröffentlicht', $this->text($danach), 'nie „nicht veröffentlicht“, wenn es niemand weiß');
        self::assertSame($schluessel, $this->schluessel($danach), 'dasselbe Formular, derselbe Schlüssel');

        // Der zweite Versuch mit diesem Schlüssel gelingt (der Dienst liefert die Fassung oder ihre Wiederholung).
        $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->veroeffentlicht(5, ['Idempotent-Replayed' => 'true']);
        $this->attrappe($antworten);
        $erfolg = $this->formularAbsenden($danach, 'barrierepruefung-freigabe');
        $gesendet = $this->freigaben();
        self::assertCount(1, $gesendet);
        self::assertSame($schluessel, $gesendet[0]->getHeaderLine('Idempotency-Key'));
        self::assertCount(1, $this->suche($this->html($this->folgen($erfolg)), '//*[@role="status"][contains(.,"Fassung 5 ist veröffentlicht")]'));

        // Danach (Rückmeldung verbraucht) zeigt die Freigabe wieder einen neuen Schlüssel.
        $this->attrappe($antworten);
        self::assertNotSame($schluessel, $this->schluessel($this->html($this->aufrufen(self::FREIGABE, ['site' => 'a']))));
    }

    #[Test]
    public function neuerEntwurfNachOffenemAusgangBekommtNeuenSchluessel(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->zeitueberlauf();
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::FREIGABE, ['site' => 'a']));
        $schluessel = $this->schluessel($html);
        $antwort = $this->formularAbsenden($html, 'barrierepruefung-freigabe');
        // Inzwischen ein anderer Entwurf: der alte Schlüssel gehört zu einer anderen Entscheidung.
        $antworten['GET ' . self::API . 'sites/01SITEA/declaration/draft'] = $this->json($this->entwurf(['draft_etag' => 'andereKennung000000000000000000']));
        $this->attrappe($antworten);

        self::assertNotSame($schluessel, $this->schluessel($this->html($this->folgen($antwort))));
    }

    #[Test]
    public function offenerAusgangBleibtBeiSeinerSite(): void
    {
        // Zweite Site, mit dem Dienst verbunden; ihr Entwurf hat dieselbe Kennung und dieselbe nächste Fassung.
        $this->get(SiteWriter::class)->write('b', [
            'rootPageId' => 2,
            'base' => 'https://gemeinde-b.de/',
            'websiteTitle' => 'Gemeinde B',
            'languages' => [['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/']],
        ]);
        $this->verbinde();
        $this->get(Verbindungen::class)->speichern(new Verbindung('b', '01SITEB', self::DIENST, Tresor::ausInstallation()->verschluesseln('tok-b'), $this->token('erlaubt'), 'meta-b', 'datei-b', 1762128000));
        $antworten = $this->dienst();
        foreach ($this->dienst() as $schluessel => $antwort) {
            $antworten[str_replace('01SITEA', '01SITEB', $schluessel)] = $antwort;
        }
        $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->zeitueberlauf();
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::FREIGABE, ['site' => 'a']));
        $schluessel = $this->schluessel($html);
        $antwort = $this->formularAbsenden($html, 'barrierepruefung-freigabe');
        self::assertStringContainsString('freigabe', $antwort->getHeaderLine('Location'), 'Ausgang offen');

        // Statt der Freigabe von A öffnet die Person die von B: die gemerkte Rückmeldung wird dort gelesen. (Direkt über
        // anfrage(): aufrufen() speichert die Sitzung des Testprozesses und löschte die Rückmeldung.)
        $b = $this->html($this->anfrage((string)$this->get(UriBuilder::class)->buildUriFromRoute(self::FREIGABE, ['site' => 'b']), null));
        self::assertCount(1, $this->suche($b, '//*[@role="alert"][contains(.,"ist deshalb offen")]'), 'die Rückmeldung von A kommt auf B an');
        self::assertStringContainsString('Gemeinde B', $this->text($this->inhalt($b)));
        self::assertSame(self::ETAG, $this->suche($b, '//input[@name="draft_etag"]')[0]->getAttribute('value'), 'gleiche Kennung wie bei A');
        self::assertNotSame($schluessel, $this->schluessel($b), 'B bekommt nie den Schlüssel von A');
    }

    #[Test]
    public function geaenderterEntwurf(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->json([
            'message' => 'Der Entwurf hat sich seit Ihrer Vorschau geändert. Bitte sehen Sie ihn sich noch einmal an.',
            'reason' => 'entwurf_geaendert',
            'draft_etag' => 'neueKennung0000000000000000000000',
        ], 409);
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $antwort = $this->formularAbsenden($this->html($this->aufrufen(self::FREIGABE, ['site' => 'a'])), 'barrierepruefung-freigabe');
        self::assertSame(303, $antwort->getStatusCode());
        self::assertStringContainsString('freigabe', $antwort->getHeaderLine('Location'), 'zurück zur Freigabe');

        // Inzwischen hat jemand im Dienst etwas beantwortet: neuer Text, neue Kennung.
        $antworten['GET ' . self::API . 'sites/01SITEA/declaration/draft'] = $this->json($this->entwurf([
            'html' => '<h1>Erklärung zur Barrierefreiheit</h1><p>Ein geänderter Satz.</p>',
            'draft_etag' => 'neueKennung0000000000000000000000',
        ]));
        $this->attrappe($antworten);
        $danach = $this->html($this->folgen($antwort));

        self::assertCount(1, $this->suche($danach, '//*[@role="alert"][contains(.,"Der Entwurf hat sich geändert")]'));
        self::assertStringContainsString('Ein geänderter Satz.', $this->text($this->inhalt($danach)), 'neue Vorschau');
        self::assertCount(1, $this->suche($danach, '//input[@name="draft_etag"][@value="neueKennung0000000000000000000000"]'));
        self::assertSame([], $this->suche($danach, '//*[@role="status"]'), 'keine Erfolgsmeldung');
    }

    #[Test]
    public function nichtBereitNenntBlocker(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->json([
            'message' => 'Die Erklärung kann noch nicht veröffentlicht werden.',
            'reason' => 'nicht_bereit',
            'blockers' => [['key' => 'land', 'text' => 'Wählen Sie, ob die Stelle zum Bund oder zu einem Land gehört.']],
        ], 422);
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::FREIGABE, ['site' => 'a']));
        // Der Dienst weiß jetzt, wo der Grund hingehört: im Weg steht er unter „Wer erklärt“.
        $weg = $this->aufnahme('path');
        $weg['data']['steps'][2]['state'] = 'offen';
        $weg['data']['steps'][2]['reasons'] = [['key' => 'land', 'text' => 'Wählen Sie, ob die Stelle zum Bund oder zu einem Land gehört.']];
        $weg['data']['next'] = 'verantwortliche_stelle';
        $antworten['GET ' . self::API . 'sites/01SITEA/declaration/path'] = $this->json($weg);
        $this->attrappe($antworten);

        $danach = $this->html($this->folgen($this->formularAbsenden($html, 'barrierepruefung-freigabe')));

        $eintrag = $this->suche($danach, '//*[@role="alert"]//li[contains(.,"Bund oder zu einem Land")]//a');
        self::assertCount(1, $eintrag, 'der Blocker mit Verweis auf seinen Schritt');
        self::assertStringContainsString('schritt=verantwortliche_stelle', html_entity_decode($eintrag[0]->getAttribute('href')));
        self::assertSame([], $this->suche($danach, '//*[@role="status"]'));
    }

    #[Test]
    public function vorschauOhneH1(): void
    {
        $this->verbinde();
        $this->attrappe($this->dienst());
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::FREIGABE, ['site' => 'a']));
        $inhalt = $this->inhalt($html);

        self::assertCount(1, $this->suche($inhalt, '//h1'), 'nur die h1 des Moduls');
        $vorschau = $this->suche($inhalt, '//*[@id="barrierepruefung-vorschau"]');
        self::assertCount(1, $vorschau);
        $ueberschriften = $this->suche($inhalt, '//*[@id="barrierepruefung-vorschau"]//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]');
        self::assertSame(['h3', 'h4'], array_map(static fn(\DOMElement $e): string => $e->tagName, $ueberschriften), 'Vorschau beginnt bei h3');
        self::assertSame('Erklärung zur Barrierefreiheit', trim($ueberschriften[0]->textContent));
        self::assertSame('de', $vorschau[0]->getAttribute('lang'));
        self::assertStringNotContainsString('<script', $inhalt, 'durch die Bereinigung');
        self::assertStringContainsString('Beispiel GmbH ist bemüht.', $this->text($inhalt));
        // Die h2 darüber, unter der die Vorschau steht.
        self::assertCount(1, $this->suche($inhalt, '//h2[following::*[@id="barrierepruefung-vorschau"]]'));
    }

    #[Test]
    public function aussageZurKonformitaet(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->veroeffentlicht();
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::FREIGABE, ['site' => 'a']));
        $text = $this->text($this->inhalt($html));
        self::assertStringContainsString('nicht konform', $text, 'ermittelte Aussage mit dem Wort des Dienstes');
        self::assertStringContainsString('Grundlage: 412 offene Befunde.', $text, 'Begründung des Dienstes');
        // Nur die wählbaren Aussagen, die ermittelte ist gewählt; „vollständig konform“ fehlt, solange etwas offen ist.
        $optionen = $this->suche($html, '//input[@type="radio"][@name="conformance_status"]');
        self::assertSame(['partial', 'none'], array_map(static fn(\DOMElement $e): string => $e->getAttribute('value'), $optionen));
        self::assertCount(1, $this->suche($html, '//input[@name="conformance_status"][@value="none"][@checked]'));

        // Abweichen ohne Begründung: nichts geht raus, der Fehler steht am Feld.
        $antwort = $this->formularAbsenden($html, 'barrierepruefung-freigabe', ['conformance_status' => 'partial', 'override_reason' => '  ']);
        self::assertSame([], $this->freigaben());
        $danach = $this->html($this->folgen($antwort));
        $feld = $this->dom($danach)->getElementById('bp-override_reason');
        self::assertNotNull($feld);
        self::assertSame('true', $feld->getAttribute('aria-invalid'));
        self::assertTrue($feld->hasAttribute('autofocus'));
        self::assertCount(1, $this->suche($danach, '//input[@name="conformance_status"][@value="partial"][@checked]'), 'die Wahl bleibt');
        self::assertCount(1, $this->suche($danach, '//*[@role="alert"]//a[@href="#bp-override_reason"]'));

        // Mit Begründung: beides geht mit.
        $this->attrappe($antworten);
        $this->formularAbsenden($danach, 'barrierepruefung-freigabe', ['conformance_status' => 'partial', 'override_reason' => 'Die offenen Befunde betreffen nur das Archiv.']);
        $rumpf = $this->rumpf($this->freigaben()[0]);
        self::assertSame('partial', $rumpf['conformance_status']);
        self::assertSame('Die offenen Befunde betreffen nur das Archiv.', $rumpf['override_reason']);
    }

    #[Test]
    public function aussageUnzulaessigSagtDerDienst(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->json([
            'message' => 'Für eine abweichende Konformitätsaussage ist eine Begründung erforderlich.',
            'reason' => 'aussage_unzulaessig',
        ], 422);
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $antwort = $this->formularAbsenden($this->html($this->aufrufen(self::FREIGABE, ['site' => 'a'])), 'barrierepruefung-freigabe');
        self::assertStringContainsString('freigabe', $antwort->getHeaderLine('Location'));
        $danach = $this->html($this->folgen($antwort));
        self::assertCount(1, $this->suche($danach, '//*[@role="alert"][contains(.,"Für eine abweichende Konformitätsaussage ist eine Begründung erforderlich.")]'));
    }

    #[Test]
    public function nochOffenerWegZeigtDenSchrittStattDesKnopfs(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $weg = $this->aufnahme('path');
        $weg['data']['steps'][1]['state'] = 'offen';
        $weg['data']['next'] = 'pruefschritte';
        $antworten['GET ' . self::API . 'sites/01SITEA/declaration/path'] = $this->json($weg);
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::FREIGABE, ['site' => 'a']));
        self::assertSame([], $this->suche($html, '//form[@id="barrierepruefung-freigabe"]'), 'kein Knopf, solange ein Schritt offen ist');
        self::assertCount(1, $this->suche($html, '//*[@id="barrierepruefung-vorschau"]'), 'die Vorschau steht trotzdem da');
        $link = array_filter($this->suche($html, '//a'), static fn(\DOMElement $a): bool => str_contains(html_entity_decode($a->getAttribute('href')), 'schritt=pruefschritte'));
        self::assertNotSame([], $link, 'Weg zum offenen Schritt');
    }

    #[Test]
    public function uebersichtFuehrtZurFreigabe(): void
    {
        $this->verbinde();
        $this->attrappe($this->dienst());
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        $knopf = $this->suche($html, '//a[contains(@class,"btn-primary")]');
        self::assertCount(1, $knopf);
        self::assertStringContainsString('/freigabe', html_entity_decode($knopf[0]->getAttribute('href')));
        self::assertStringContainsString('freigeben', $knopf[0]->textContent);
    }

    #[Test]
    public function ohneFreigaberechtKeinKnopf(): void
    {
        // Zwei Fälle: der Dienst sagt „recht_fehlt“, oder der Token darf vorbereiten, aber nicht veröffentlichen.
        foreach ([['recht_fehlt', []], ['erlaubt', ['declaration:prepare']]] as [$stand, $rechte]) {
            $this->verbinde($stand, $rechte);
            $antworten = $this->dienst($stand, $rechte);
            $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->veroeffentlicht();
            $this->attrappe($antworten);
            $this->anmelden(self::ADMIN);

            $uebersicht = $this->inhalt($this->html($this->aufrufen(self::ROUTE, ['site' => 'a'])));
            self::assertStringNotContainsString('freigeben', $this->text($uebersicht), $stand . ': die Übersicht behauptet kein Recht');

            $html = $this->html($this->aufrufen(self::FREIGABE, ['site' => 'a']));
            $inhalt = $this->inhalt($html);
            self::assertStringNotContainsString('<form', $inhalt, $stand . ': kein Formular');
            self::assertStringNotContainsString('<button', $inhalt, $stand . ': kein Knopf');
            self::assertStringContainsString('Reiter „Einbindung“', $this->text($inhalt), $stand . ': der Satz nennt den Weg');

            // Auch ein gebasteltes Formular sendet nichts.
            $antwort = $this->aufrufen(self::FREIGEBEN, [], ['site' => 'a', 'draft_etag' => self::ETAG, 'expected_version' => '5', 'idempotency_key' => '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b']);
            self::assertSame(303, $antwort->getStatusCode());
            self::assertSame([], $this->freigaben(), $stand . ': nichts gesendet');
        }
    }

    #[Test]
    public function rechtErloschenBeimFreigebenAendertDenStand(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['POST ' . self::API . 'sites/01SITEA/declaration/publish'] = $this->json([
            'message' => 'Das Recht dieses Tokens ist erloschen.',
            'reason' => 'recht_erloschen',
            'url' => 'https://dienst.example/websites/01SITEA/einbindung',
        ], 403);
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $this->formularAbsenden($this->html($this->aufrufen(self::FREIGABE, ['site' => 'a'])), 'barrierepruefung-freigabe');

        $verbindung = $this->get(Verbindungen::class)->fuerSite('a');
        self::assertNotNull($verbindung);
        self::assertSame('recht_erloschen', $verbindung->freigaberecht());
        self::assertFalse($verbindung->darfFreigeben());
    }

    #[Test]
    public function fremdeSiteWirdAbgewiesen(): void
    {
        $this->verbinde();
        $this->attrappe($this->dienst());
        $this->anmelden(2);

        self::assertSame(403, $this->aufrufen(self::FREIGEBEN, [], ['site' => 'gibt-es-nicht', 'idempotency_key' => '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b'])->getStatusCode());
        self::assertSame([], $this->anfragen);
    }
}
