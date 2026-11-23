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

final class BefundeControllerTest extends FunctionalTestCase
{
    use Modulaufrufe;

    private const ROUTE = 'barrierepruefung_befunde';
    private const DIENST = 'https://dienst.example/api/v1';
    private const ADMIN = 1;
    private const REDAKTEURIN = 2;
    private const LAUF = '01STUBSCAN0000000000000000';
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    protected array $coreExtensionsToLoad = ['fluid_styled_content'];

    protected array $testExtensionsToLoad = ['barrierepruefungde/typo3'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');

        $schreiber = $this->get(SiteWriter::class);
        foreach ([['a', 1, 'https://stadt-a.de/', 'Stadt A'], ['b', 2, 'https://gemeinde-b.de/', 'Gemeinde B']] as [$kennung, $wurzel, $basis, $titel]) {
            $schreiber->write($kennung, [
                'rootPageId' => $wurzel,
                'base' => $basis,
                'websiteTitle' => $titel,
                'languages' => [['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/']],
            ]);
        }
    }

    private function verbinde(string $site, string $siteId): void
    {
        $this->get(Verbindungen::class)->speichern(new Verbindung(
            $site,
            $siteId,
            self::DIENST,
            Tresor::ausInstallation()->verschluesseln('tok-' . $site),
            ['abilities' => ['sites:read', 'findings:read'], 'publish' => ['state' => 'recht_fehlt']],
            'meta-' . $site,
            'datei-' . $site,
            1762128000,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function aufnahmeDaten(string $name): array
    {
        $daten = json_decode((string)file_get_contents(__DIR__ . '/../../../Build/e2e/antworten/' . $name . '.json'), true);
        \assert(is_array($daten));

        return $daten;
    }

    /**
     * @param array<string, mixed> $ueber Felder, die `data` der Aufnahme site.json überschreiben
     */
    private function website(array $ueber = []): Response
    {
        $daten = $this->aufnahmeDaten('site');
        \assert(is_array($daten['data']));
        $daten['data'] = array_replace($daten['data'], ['id' => '01SITEA', 'base_url' => 'https://stadt-a.de'], $ueber);

        return new Response(200, ['Content-Type' => 'application/json'], (string)json_encode($daten));
    }

    /**
     * Die Aufnahme der Befundliste, dazu ein Hinweis und ein bewerteter Befund, die der Dienst bei
     * `binding_only=1&state=open` nie lieferte – die Attrappe filtert nicht, das Modul muss es trotzdem.
     */
    private function liste(bool $mitFremden = true): Response
    {
        $daten = $this->aufnahmeDaten('findings');
        \assert(is_array($daten['data']));
        if ($mitFremden) {
            $vorlage = $daten['data'][0];
            $daten['data'][] = ['rule' => 'wcag22.hinweis-regel', 'title' => 'Nur ein Hinweis', 'advisory' => true, 'state' => 'open'] + $vorlage;
            $daten['data'][] = ['rule' => 'axe.bewertet-regel', 'title' => 'Schon bewertet', 'advisory' => false, 'state' => 'false_positive'] + $vorlage;
        }

        return new Response(200, ['Content-Type' => 'application/json'], (string)json_encode($daten));
    }

    private function regel(int $offset = 0, int $gesamt = 267, int $anzahl = 20): Response
    {
        $daten = $this->aufnahmeDaten('findings-regel');
        \assert(is_array($daten['data']) && is_array($daten['meta']));
        $daten['data'] = array_slice(array_merge(...array_fill(0, 3, $daten['data'])), 0, $anzahl);
        $daten['meta']['offset'] = $offset;
        $daten['meta']['total'] = $gesamt;

        return new Response(200, ['Content-Type' => 'application/json'], (string)json_encode($daten));
    }

    /**
     * @return array<string, Response>
     */
    private function standA(): array
    {
        return [
            'GET /api/v1/sites/01SITEA' => $this->website(),
            'GET /api/v1/scans/' . self::LAUF . '/findings' => $this->liste(),
        ];
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

    private function html(\Psr\Http\Message\ResponseInterface $antwort): string
    {
        self::assertSame(200, $antwort->getStatusCode(), substr((string)$antwort->getBody(), 0, 2000));

        return (string)$antwort->getBody();
    }

    #[Test]
    public function zeigtNurVerbindlicheOffeneBefunde(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe($this->standA());
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        self::assertStringContainsString('Zu geringer Kontrast', $html);
        self::assertStringContainsString('Schaltflächen müssen wahrnehmbaren Text enthalten.', $html);
        self::assertStringNotContainsString('Nur ein Hinweis', $html);
        self::assertStringNotContainsString('Schon bewertet', $html);
        self::assertCount(6, $this->suche($html, '//table//tbody/tr'));
        // Der Dienst bekommt dieselbe Auswahl wie im Plugin.
        $anfrage = $this->anfragenAn('/findings')[0];
        self::assertSame('binding_only=1&state=open', $anfrage->getUri()->getQuery());
        // Schweregrad als Wort im badge.
        self::assertCount(4, $this->suche($html, '//table//span[contains(@class,"badge")][normalize-space(.)="Kritisch"]'));
        self::assertCount(1, $this->suche($html, '//table//span[contains(@class,"badge")][normalize-space(.)="Schwerwiegend"]'));
        self::assertCount(1, $this->suche($html, '//table//span[contains(@class,"badge")][normalize-space(.)="Mäßig"]'));
    }

    #[Test]
    public function ohneLaufSagtDasMitWeg(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe(['GET /api/v1/sites/01SITEA' => $this->website(['latest_scan' => null, 'running_scan' => null])]);
        $this->anmelden(self::REDAKTEURIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        self::assertStringContainsString('Für diese Site gibt es noch kein Prüfergebnis', $html);
        self::assertSame([], $this->anfragenAn('/findings'));
        self::assertCount(0, $this->suche($html, '//table'));
        $knopf = $this->suche($html, '//form[@id="barrierepruefung-pruefen"]//button[normalize-space(.)="Jetzt prüfen"]');
        self::assertCount(1, $knopf);
        // Derselbe Weg wie in der Übersicht: Schlüssel aus der Anzeige, danach 303.
        $antwort = $this->formularAbsenden($html, 'barrierepruefung-pruefen');
        self::assertSame(303, $antwort->getStatusCode());
        self::assertSame(1, preg_match(self::UUID, $this->anfragenAn('/scans')[0]->getHeaderLine('Idempotency-Key')));
    }

    #[Test]
    public function laufendePruefungOhneErgebnisBietetKeinenZweitenStartAn(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe(['GET /api/v1/sites/01SITEA' => $this->website(['latest_scan' => null, 'running_scan' => ['id' => '01LAUFEND', 'status' => 'running']])]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        self::assertStringContainsString('Die erste Prüfung läuft', $html);
        self::assertCount(0, $this->suche($html, '//form[@id="barrierepruefung-pruefen"]'));
    }

    #[Test]
    public function sauberePruefungSagtKeineOffenenBefunde(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe(['GET /api/v1/sites/01SITEA' => $this->website(), 'GET /api/v1/scans/' . self::LAUF . '/findings' => new Response(200, [], '{"data":[],"meta":{"total_rules":0,"total_findings":0}}')]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        self::assertStringContainsString('keine offenen Befunde', $html);
        self::assertCount(0, $this->suche($html, '//table'));
    }

    #[Test]
    public function tabelleHatKopfzeilen(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe($this->standA());
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        $kopf = $this->suche($html, '//table/thead/tr/th[@scope="col"]');
        self::assertGreaterThanOrEqual(5, count($kopf));
        self::assertCount(0, $this->suche($html, '//table/thead/tr/th[not(@scope)]'));
        self::assertCount(1, $this->suche($html, '//table/caption'));
        // Jede Zeile beginnt mit einer Zeilenüberschrift.
        self::assertCount(6, $this->suche($html, '//table/tbody/tr/th[@scope="row"]'));
        // „Details“ trägt den Namen der Regel im zugänglichen Namen und führt auf ?regel=.
        $details = $this->suche($html, '//table//a[contains(@href,"regel=axe.color-contrast")]');
        self::assertCount(1, $details);
        $name = $details[0]->textContent;
        self::assertStringContainsString('Details', $name);
        self::assertStringContainsString('Zu geringer Kontrast', $name);
        // Das Wort steht im badge, nie als bloße Farbe.
        foreach ($this->suche($html, '//table//span[contains(@class,"badge")]') as $abzeichen) {
            self::assertNotSame('', trim((string)$abzeichen->textContent));
        }
        self::assertStringContainsString('Kritisch', $html);
    }

    #[Test]
    public function regelansichtPaginiert(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe($this->standA() + ['GET /api/v1/scans/' . self::LAUF . '/findings/axe.color-contrast' => $this->regel(20)]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'regel' => 'axe.color-contrast', 'seite' => '2']));

        $abruf = $this->anfragenAn('/findings/axe.color-contrast');
        self::assertCount(1, $abruf);
        parse_str($abruf[0]->getUri()->getQuery(), $query);
        self::assertSame(['limit' => '20', 'offset' => '20'], $query);
        self::assertStringContainsString('Fundstellen 21 bis 40 von 267', $html);
        // Reparaturhinweis der Regel und ein Rückweg.
        self::assertStringContainsString('Erhöhen Sie den Kontrast', $html);
        // Oben und unten: sonst brauchte „Weitere laden“ per Tastatur vierzig Tab-Drücke.
        $weitere = $this->suche($html, '//a[contains(@href,"seite=3")][contains(normalize-space(.),"Weitere laden")]');
        self::assertCount(2, $weitere);
        self::assertLessThan(strpos($html, '<table'), strpos($html, 'Weitere laden'), 'ein Link vor der Tabelle');
        self::assertCount(1, $this->suche($html, '//a[contains(normalize-space(.),"Zurück zur Liste")]'));
        // Nur die Fundstelle der ersten Seite hat ein Kopfzeilenmuster; Stellen mit Selektor in code.
        self::assertGreaterThan(0, count($this->suche($html, '//table//th[@scope="col"]')));
        // Im Dienst öffnen: nur http(s).
        self::assertCount(1, $this->suche($html, '//a[starts-with(@href,"https://dienst.example/")][contains(normalize-space(.),"Im Dienst öffnen")]'));
    }

    #[Test]
    public function letzteSeiteHatKeinWeitereLaden(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe($this->standA() + ['GET /api/v1/scans/' . self::LAUF . '/findings/axe.color-contrast' => $this->regel(260, 267, 7)]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'regel' => 'axe.color-contrast', 'seite' => '14']));

        self::assertStringContainsString('Fundstellen 261 bis 267 von 267', $html);
        self::assertStringNotContainsString('Weitere laden', $html);
        self::assertCount(2, $this->suche($html, '//a[contains(@href,"seite=13")][contains(normalize-space(.),"Frühere")]'));
    }

    #[Test]
    public function unbekannteRegelGehtNieAnDieApi(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe($this->standA());
        $this->anmelden(self::ADMIN);

        // Eine Regel, die nicht in der Liste der offenen verbindlichen Befunde steht (auch ein Hinweis).
        foreach (['wcag22.hinweis-regel', '../sites', 'axe.gibt-es-nicht'] as $regel) {
            $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'regel' => $regel]));
            self::assertStringContainsString('nicht unter den offenen Befunden', $html, $regel);
            self::assertCount(1, $this->suche($html, '//a[contains(normalize-space(.),"Zurück zur Liste")]'), $regel);
        }
        self::assertCount(0, array_filter($this->anfragen, static fn($a): bool => preg_match('#/findings/#', $a->getUri()->getPath()) === 1));
    }

    #[Test]
    public function fremdeAdressenWerdenNieZumLink(): void
    {
        $this->verbinde('a', '01SITEA');
        $regel = $this->aufnahmeDaten('findings-regel');
        \assert(is_array($regel['data']));
        $regel['data'][0]['page_url'] = 'javascript:alert(1)';
        $this->attrappe($this->standA() + ['GET /api/v1/scans/' . self::LAUF . '/findings/axe.color-contrast' => new Response(200, [], (string)json_encode($regel))]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'regel' => 'axe.color-contrast']));

        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringContainsString('javascript:alert(1)', $html);
    }

    #[Test]
    public function dienstFehlerSagtDetailUndHinweis(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe(array_replace($this->standA(), ['GET /api/v1/scans/' . self::LAUF . '/findings' => new Response(403, ['Content-Type' => 'application/problem+json'], '{"status":403,"reason":"recht_erloschen","detail":"D-vom-Dienst"}')]));
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('D-vom-Dienst', $html);
        self::assertStringContainsString('neu zu erteilen', $html);
        self::assertStringNotContainsString('recht_erloschen', $html);
    }

    #[Test]
    public function ohneVerbindungFuehrtZurUebersicht(): void
    {
        $this->attrappe([]);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        self::assertStringContainsString('nicht mit barrierepruefung.de verbunden', $html);
        self::assertCount(1, $this->suche($html, '//a[contains(@href,"barrierepruefung/uebersicht")][contains(@class,"btn")]'));
        self::assertSame([], $this->anfragen);
    }

    #[Test]
    public function zweiUntermodulesindImMenuErreichbar(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->attrappe($this->standA());
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));

        self::assertStringContainsString('barrierepruefung/uebersicht', $html);
        self::assertStringContainsString('barrierepruefung/befunde', $html);
    }

    #[Test]
    public function sitesAusserhalbDesWebMountsBleibenZu(): void
    {
        $this->verbinde('a', '01SITEA');
        $this->verbinde('b', '01SITEB');
        $this->attrappe($this->standA());
        $this->anmelden(self::REDAKTEURIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'b', 'regel' => 'axe.color-contrast']));

        self::assertStringContainsString('Stadt A', $html);
        self::assertStringNotContainsString('Gemeinde B', $html);
        self::assertSame([], $this->anfragenAn('01SITEB'));
    }
}
