<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Functional\Controller;

use GuzzleHttp\Psr7\Response;
use Lubomedia\Barrierepruefung\Verbindung\Tresor;
use Lubomedia\Barrierepruefung\Verbindung\Verbindung;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class UebersichtControllerTest extends FunctionalTestCase
{
    use Modulaufrufe;

    private const ROUTE = 'barrierepruefung_uebersicht';
    private const DIENST = 'https://dienst.example/api/v1';
    private const ADMIN = 1;
    private const REDAKTEURIN = 2;
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    protected array $coreExtensionsToLoad = ['fluid_styled_content'];

    protected array $testExtensionsToLoad = ['lubomedia/barrierepruefung-typo3'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');

        $schreiber = $this->get(SiteWriter::class);
        foreach ([['a', 1, 'https://stadt-a.de/', 'Stadt A'], ['b', 2, 'https://gemeinde-b.de/', 'Gemeinde B'], ['c', 3, 'https://stadt-a.de/museum/', 'Museum']] as [$kennung, $wurzel, $basis, $titel]) {
            $schreiber->write($kennung, [
                'rootPageId' => $wurzel,
                'base' => $basis,
                'websiteTitle' => $titel,
                'languages' => [['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/']],
            ]);
        }
    }

    private function verbinde(string $site, string $siteId, ?Tresor $tresor = null): void
    {
        $tresor ??= Tresor::ausInstallation();
        $this->get(Verbindungen::class)->speichern(new Verbindung(
            $site,
            $siteId,
            self::DIENST,
            $tresor->verschluesseln('tok-' . $site),
            ['abilities' => ['sites:read'], 'publish' => ['state' => 'recht_fehlt']],
            'meta-' . $site,
            'datei-' . $site,
            1762128000,
        ));
    }

    /**
     * @param array<string, mixed> $ueber Felder, die `data` der Aufnahme site.json überschreiben
     */
    private function website(string $siteId = '01SITEA', array $ueber = []): Response
    {
        $daten = json_decode((string)file_get_contents(__DIR__ . '/../../../Build/e2e/antworten/site.json'), true);
        \assert(is_array($daten) && is_array($daten['data']));
        $daten['data'] = array_replace($daten['data'], ['id' => $siteId, 'base_url' => 'https://stadt-a.de'], $ueber);

        return new Response(200, ['Content-Type' => 'application/json'], (string)json_encode($daten));
    }

    private function aufnahme(string $name, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string)file_get_contents(__DIR__ . '/../../../Build/e2e/antworten/' . $name . '.json'));
    }

    private function problem(int $status, string $reason, string $detail): Response
    {
        return new Response($status, ['Content-Type' => 'application/problem+json'], (string)json_encode(['status' => $status, 'reason' => $reason, 'detail' => $detail]));
    }

    /**
     * @param array<string, mixed> $ueber
     * @return array<string, Response>
     */
    private function standA(array $ueber = []): array
    {
        return [
            'GET /api/v1/sites/01SITEA' => $this->website('01SITEA', $ueber),
            'GET /api/v1/sites/01SITEA/declaration' => $this->aufnahme('declaration'),
        ];
    }

    private function knoten(string $html, string $pfad): ?\DOMElement
    {
        $treffer = (new \DOMXPath($this->dom($html)))->query($pfad);
        $erstes = $treffer !== false ? $treffer->item(0) : null;

        return $erstes instanceof \DOMElement ? $erstes : null;
    }

    private function zeilen(): int
    {
        return (int)$this->getConnectionPool()->getConnectionForTable(Verbindungen::TABELLE)->count('*', Verbindungen::TABELLE, []);
    }

    private function html(\Psr\Http\Message\ResponseInterface $antwort): string
    {
        self::assertSame(200, $antwort->getStatusCode(), substr((string)$antwort->getBody(), 0, 2000));

        return (string)$antwort->getBody();
    }

    #[Test]
    public function zeigtNurSitesImWebMount(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->verbinde('b', '01SITEB');
        $this->attrappe($this->standA() + ['GET /api/v1/sites/01SITEB' => $this->website('01SITEB', ['base_url' => 'https://gemeinde-b.de'])]);

        // Die Admin sieht alle Sites in der Auswahl.
        $this->anmelden(self::ADMIN);
        $admin = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        self::assertStringContainsString('Gemeinde B', $admin);

        $this->attrappe($this->standA() + ['GET /api/v1/sites/01SITEB' => $this->website('01SITEB', ['base_url' => 'https://gemeinde-b.de'])]);
        $this->anmelden(self::REDAKTEURIN);
        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'b']));

        self::assertStringContainsString('Stadt A', $html);
        self::assertStringNotContainsString('Gemeinde B', $html);
        self::assertStringNotContainsString('gemeinde-b.de', $html);
        self::assertSame([], $this->anfragenAn('01SITEB'));
        self::assertNotSame([], $this->anfragenAn('01SITEA'));

        // Auch eine Aktion mit gebasteltem Parameter löst für B nichts aus.
        foreach (['pruefen', 'bestaetigen', 'aktualisieren'] as $aktion) {
            $antwort = $this->aufrufen(self::ROUTE . '.' . $aktion, [], ['site' => 'b', 'idempotency_key' => '6f1c2d3e-4b5a-4c6d-8e7f-0a1b2c3d4e5f']);
            self::assertSame(403, $antwort->getStatusCode(), $aktion);
        }
        self::assertSame([], $this->anfragenAn('01SITEB'));
    }

    #[Test]
    public function verbindenNurFuerAdmins(): void
    {
        $this->attrappe(['GET /api/v1/sites/01SITEA' => $this->website(), 'GET /api/v1/sites/01SITEA/verification' => $this->aufnahme('verification')]);
        $this->anmelden(self::REDAKTEURIN);

        $antwort = $this->aufrufen(self::ROUTE . '.verbinden', [], ['site' => 'a', 'dienst_url' => self::DIENST, 'site_id' => '01SITEA', 'api_token' => 'tok']);

        self::assertSame(403, $antwort->getStatusCode());
        self::assertSame(0, $this->zeilen());
        self::assertSame([], $this->anfragen);

        // Die Redakteurin bekommt kein Formular, nur den Satz.
        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        self::assertStringNotContainsString('name="api_token"', $html);
        self::assertStringContainsString('Eine Administratorin oder ein Administrator kann sie verbinden', $html);
    }

    #[Test]
    public function verbindenFuehrtZurUebersichtMitRueckmeldung(): void
    {
        $this->attrappe(['GET /api/v1/sites/01SITEA/verification' => $this->aufnahme('verification')] + $this->standA());
        $this->anmelden(self::ADMIN);

        $antwort = $this->aufrufen(self::ROUTE . '.verbinden', [], ['site' => 'a', 'dienst_url' => self::DIENST, 'site_id' => '01SITEA', 'api_token' => 'tok-123']);

        self::assertSame(303, $antwort->getStatusCode(), $antwort->getHeaderLine('Location'));
        self::assertStringContainsString('/typo3/module/barrierepruefung/uebersicht', $antwort->getHeaderLine('Location'));
        self::assertStringContainsString('site=a', $antwort->getHeaderLine('Location'));
        self::assertSame(1, $this->zeilen());

        $erst = $this->html($this->folgen($antwort));
        self::assertStringContainsString('role="status"', $erst);
        self::assertStringContainsString('Verbunden.', $erst);
        self::assertStringContainsString('>verbunden<', $erst);

        $danach = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        self::assertStringNotContainsString('Verbunden.', $danach);
        self::assertStringNotContainsString('role="status"', $danach);
        self::assertStringContainsString('>verbunden<', $danach);
    }

    #[Test]
    public function abweichendeAdresseNenntBeide(): void
    {
        $this->attrappe(['GET /api/v1/sites/01SITEA' => $this->website('01SITEA', ['base_url' => 'https://andere.de'])]);
        $this->anmelden(self::ADMIN);

        $antwort = $this->aufrufen(self::ROUTE . '.verbinden', [], ['site' => 'a', 'dienst_url' => self::DIENST, 'site_id' => '01SITEA', 'api_token' => 'tok-123']);
        $html = $this->html($this->folgen($antwort));

        self::assertSame(0, $this->zeilen());
        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('https://stadt-a.de/', $html);
        self::assertStringContainsString('https://andere.de', $html);
        // Eingaben stehen wieder im Formular, das Token nie.
        self::assertStringContainsString('value="01SITEA"', $html);
        self::assertStringNotContainsString('tok-123', $html);
    }

    #[Test]
    public function fehlerJeFeldMitAriaDescribedby(): void
    {
        $this->attrappe([]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->folgen($this->aufrufen(self::ROUTE . '.verbinden', [], ['site' => 'a', 'dienst_url' => self::DIENST, 'site_id' => '01SITEA', 'api_token' => ''])));

        $dom = $this->dom($html);
        $token = $dom->getElementById('barrierepruefung-token');
        self::assertNotNull($token);
        self::assertSame('true', $token->getAttribute('aria-invalid'));
        $beschrieben = explode(' ', $token->getAttribute('aria-describedby'));
        self::assertContains('barrierepruefung-token-fehler', $beschrieben);
        self::assertStringContainsString('Token', (string)$dom->getElementById('barrierepruefung-token-fehler')?->textContent);
        self::assertNotNull($this->knoten($html, '//label[@for="barrierepruefung-token"]'));
        self::assertSame('', $dom->getElementById('barrierepruefung-site-id')?->getAttribute('aria-invalid'));
    }

    #[Test]
    public function jetztPruefenSendetIdempotencyKeyAusDemFormular(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe($this->standA() + ['POST /api/v1/sites/01SITEA/scans' => $this->aufnahme('scan-start', 202)]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        $feld = $this->knoten($html, '//form[@id="barrierepruefung-pruefen"]//input[@name="idempotency_key"]');
        self::assertInstanceOf(\DOMElement::class, $feld);
        self::assertSame('hidden', $feld->getAttribute('type'));
        $schluessel = $feld->getAttribute('value');
        self::assertMatchesRegularExpression(self::UUID, $schluessel);
        // Der Knopf im Docheader gehört zu diesem Formular.
        self::assertMatchesRegularExpression('/<button[^>]*form="barrierepruefung-pruefen"/', $html);

        $erste = $this->formularAbsenden($html, 'barrierepruefung-pruefen');
        $zweite = $this->formularAbsenden($html, 'barrierepruefung-pruefen');

        self::assertSame(303, $erste->getStatusCode());
        self::assertSame(303, $zweite->getStatusCode());
        $starts = array_values(array_filter($this->anfragen, static fn($a): bool => $a->getMethod() === 'POST'));
        self::assertCount(2, $starts);
        foreach ($starts as $start) {
            self::assertSame('/api/v1/sites/01SITEA/scans', $start->getUri()->getPath());
            self::assertSame($schluessel, $start->getHeaderLine('Idempotency-Key'));
        }
        self::assertStringContainsString('Die Prüfung ist gestartet.', $this->html($this->folgen($zweite)));

        // Jede Anzeige erzeugt einen neuen Schlüssel.
        $neu = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        self::assertStringNotContainsString($schluessel, $neu);
    }

    #[Test]
    public function pruefenOhneGueltigenSchluesselFragtNichtAn(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe($this->standA());
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->folgen($this->aufrufen(self::ROUTE . '.pruefen', [], ['site' => 'a', 'idempotency_key' => 'x'])));

        self::assertSame([], array_filter($this->anfragen, static fn($a): bool => $a->getMethod() === 'POST'));
        self::assertStringContainsString('role="alert"', $html);
    }

    #[Test]
    public function keinAutomatischesNeuladen(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe($this->standA(['running_scan' => ['id' => '01LAUF', 'status' => 'running']]) + [
            'GET /api/v1/scans/01LAUF' => new Response(200, [], (string)json_encode(['data' => ['id' => '01LAUF', 'status' => 'running', 'created_at' => '2026-11-21T09:42:00+00:00']])),
        ]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        self::assertStringNotContainsStringIgnoringCase('http-equiv="refresh"', $html);
        self::assertStringNotContainsString('setInterval', $html);
        self::assertStringNotContainsString('setTimeout', $html);
    }

    #[Test]
    public function laufendePruefungMitStartzeit(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe($this->standA(['running_scan' => ['id' => '01LAUF', 'status' => 'running']]) + [
            'GET /api/v1/scans/01LAUF' => new Response(200, [], (string)json_encode(['data' => ['id' => '01LAUF', 'status' => 'running', 'created_at' => '2026-11-21T09:42:00+00:00']])),
        ]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        $zeit = (new \DateTimeImmutable('2026-11-21T09:42:00+00:00'))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('H:i');
        self::assertMatchesRegularExpression('/class="callout callout-info"[^>]*>.*Prüfung läuft seit [^<]*' . $zeit . '/s', $html);
        self::assertStringContainsString('Status aktualisieren', $html);
    }

    #[Test]
    public function zustaendeAlsWort(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe($this->standA(['verified' => false, 'quota' => ['metric' => 'pages', 'used' => 3, 'limit' => null, 'remaining' => null, 'period_end' => null]]));
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        $abzeichen = (new \DOMXPath($this->dom($html)))->query('//*[contains(concat(" ", normalize-space(@class), " "), " badge ")]');
        self::assertInstanceOf(\DOMNodeList::class, $abzeichen);
        self::assertGreaterThanOrEqual(3, $abzeichen->length);
        foreach ($abzeichen as $element) {
            self::assertNotSame('', trim((string)$element->nodeValue));
        }
        self::assertStringContainsString('nicht bestätigt', $html);
        self::assertStringContainsString('unbegrenzt', $html);
        self::assertStringContainsString('<dl', $html);
    }

    #[Test]
    public function tresorFehlerSagtNeuVerbinden(): void
    {
        $this->verbinde('a', '01SITEA', new Tresor('ein-ganz-anderer-schluessel'));
        $this->attrappe($this->standA());
        $this->anmelden(self::ADMIN);
        $satz = 'Die Verbindung muss neu hergestellt werden';

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        self::assertStringContainsString($satz, $html);
        self::assertStringContainsString('name="api_token"', $html);
        self::assertStringContainsString('>Verbinden<', $html);

        foreach (['bestaetigen', 'pruefen'] as $aktion) {
            $antwort = $this->aufrufen(self::ROUTE . '.' . $aktion, [], ['site' => 'a', 'idempotency_key' => '6f1c2d3e-4b5a-4c6d-8e7f-0a1b2c3d4e5f']);
            self::assertSame(303, $antwort->getStatusCode(), $aktion);
            self::assertStringContainsString($satz, $this->html($this->folgen($antwort)), $aktion);
        }
        self::assertSame([], $this->anfragen);
    }

    #[Test]
    public function fehlerZeigtDetailInBackendSprache(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe(['GET /api/v1/sites/01SITEA' => $this->problem(403, 'recht_erloschen', 'D-vom-Dienst')]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        self::assertStringContainsString('D-vom-Dienst', $html);
        self::assertStringContainsString('die Freigabe im Reiter „Einbindung“ neu zu erteilen', $html);
        self::assertSame('de', $this->anfragen[0]->getHeaderLine('Accept-Language'));
    }

    #[Test]
    public function domainBestaetigen(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe($this->standA(['verified' => false]) + [
            'POST /api/v1/sites/01SITEA/verify' => new Response(200, [], (string)json_encode(['data' => ['verified' => true, 'method' => 'meta_tag', 'failure_reason' => null]])),
        ]);
        $this->anmelden(self::REDAKTEURIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        $antwort = $this->formularAbsenden($html, 'barrierepruefung-bestaetigen');

        self::assertSame('{"method":"meta_tag"}', (string)$this->anfragenAn('/verify')[0]->getBody());
        self::assertStringContainsString('Die Domain ist bestätigt', $this->html($this->folgen($antwort)));
        self::assertNotNull($this->get(Verbindungen::class)->fuerSite('a')?->domainBestaetigtAm);
    }

    #[Test]
    public function domainNichtBestaetigtNenntBeideGruende(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe($this->standA(['verified' => false]) + [
            'POST /api/v1/sites/01SITEA/verify' => new Response(200, [], (string)json_encode(['data' => ['verified' => false, 'method' => 'file', 'failure_reason' => 'token_nicht_gefunden']])),
        ]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->folgen($this->aufrufen(self::ROUTE . '.bestaetigen', [], ['site' => 'a'])));

        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('Meta-Element', $html);
        self::assertStringContainsString('Nachweisdatei', $html);
    }

    #[Test]
    public function siteMitPfadBasisNurUeberMetaElement(): void
    {
        $this->verbinde('c', '01SITEC');
        $this->attrappe(['GET /api/v1/sites/01SITEC' => $this->website('01SITEC', ['verified' => false, 'base_url' => 'https://stadt-a.de/museum/'])]);
        $this->verbinde('a', '01SITEA');
        $this->anmelden(self::ADMIN);

        $mitPfad = $this->html($this->aufrufen(self::ROUTE, ['site' => 'c']));
        self::assertStringContainsString('nur über das Meta-Element der Startseite', $mitPfad);

        $this->attrappe($this->standA(['verified' => false]));
        $ohnePfad = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        self::assertStringNotContainsString('nur über das Meta-Element der Startseite', $ohnePfad);
    }

    #[Test]
    public function trennenNurFuerAdmins(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe($this->standA());

        $this->anmelden(self::REDAKTEURIN);
        self::assertSame(403, $this->aufrufen(self::ROUTE . '.trennen', [], ['site' => 'a'])->getStatusCode());
        self::assertSame(1, $this->zeilen());
        self::assertStringNotContainsString('barrierepruefung-trennen', $this->html($this->aufrufen(self::ROUTE, ['site' => 'a'])));

        $this->anmelden(self::ADMIN);
        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        $html = $this->html($this->folgen($this->formularAbsenden($html, 'barrierepruefung-trennen')));
        self::assertSame(0, $this->zeilen());
        self::assertStringContainsString('Die Verbindung ist getrennt.', $html);
    }
}
