<?php

declare(strict_types=1);

namespace Lubomedia\Barrierepruefung\Tests\Functional\Controller;

use GuzzleHttp\Psr7\Response;
use Lubomedia\Barrierepruefung\Verbindung\Tresor;
use Lubomedia\Barrierepruefung\Verbindung\Verbindung;
use Lubomedia\Barrierepruefung\Verbindung\Verbindungen;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Untermodul „Weg zur Erklärung“: Schritte, Formulare und Fehler kommen vom Dienst; ohne Schreibrecht nur Sätze.
 */
final class WegControllerTest extends FunctionalTestCase
{
    use Modulaufrufe;

    private const ROUTE = 'barrierepruefung_weg';
    private const DIENST = 'https://dienst.example/api/v1';
    private const API = '/api/v1/';
    private const ADMIN = 1;
    private const REDAKTEURIN = 2;
    private const LAUF = '01STUBSCAN0000000000000000';

    protected array $coreExtensionsToLoad = ['fluid_styled_content'];

    protected array $testExtensionsToLoad = ['barrierepruefungde/typo3'];

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

    private function verbinde(string $freigabe = 'erlaubt'): void
    {
        $this->get(Verbindungen::class)->speichern(new Verbindung(
            'a',
            '01SITEA',
            self::DIENST,
            Tresor::ausInstallation()->verschluesseln('tok-a'),
            $this->token($freigabe),
            'meta-a',
            'datei-a',
            1762128000,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function token(string $freigabe): array
    {
        $rechte = ['sites:read', 'scans:read', 'declaration:read'];
        if ($freigabe === 'erlaubt') {
            $rechte = [...$rechte, 'declaration:prepare', 'declaration:publish'];
        }

        return ['abilities' => $rechte, 'publish' => ['state' => $freigabe]];
    }

    /**
     * @param array<mixed> $daten
     */
    private function json(array $daten, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => $status >= 400 ? 'application/problem+json' : 'application/json'], (string)json_encode($daten));
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
     * Die Website mit dem Token-Block, wie GET /sites/{id} ihn liefert.
     */
    private function website(string $freigabe = 'erlaubt'): Response
    {
        $daten = $this->aufnahme('site');
        $daten['data']['token'] = $this->token($freigabe);

        return $this->json($daten);
    }

    /**
     * Ein Weg mit offenen Schritten (Wortlaut wie vom Dienst) und einem Schritt, den die Extension nicht kennt.
     */
    private function weg(): Response
    {
        $daten = $this->aufnahme('path');
        $daten['data']['steps'] = [
            ['key' => 'pruefung', 'title' => 'Prüfung abgeschlossen', 'state' => 'erledigt', 'reasons' => [], 'web_url' => 'https://dienst.example/pruefung/' . self::LAUF],
            ['key' => 'pruefschritte', 'title' => 'Prüfschritte beantworten', 'state' => 'offen', 'reasons' => [
                ['key' => 'pruefschritte_offen', 'text' => 'Ein manueller Prüfschritt ist noch offen: „Ist die Fokusreihenfolge sinnvoll?“'],
            ], 'web_url' => 'https://dienst.example/pruefung/' . self::LAUF . '/pruefschritte?frage=manual-focus-order'],
            ['key' => 'verantwortliche_stelle', 'title' => 'Wer erklärt', 'state' => 'offen', 'reasons' => [
                ['key' => 'land', 'text' => 'Wählen Sie, ob die Stelle zum Bund oder zu einem Land gehört.'],
            ], 'web_url' => 'https://dienst.example/pruefung/' . self::LAUF . '/erklaerung#angaben'],
            ['key' => 'betroffenheit', 'title' => 'Betroffenheits-Check', 'state' => 'erledigt', 'reasons' => [], 'web_url' => 'https://dienst.example/pruefung/' . self::LAUF . '/erklaerung#angaben'],
            ['key' => 'neu_im_dienst', 'title' => 'Barrierefreie PDF-Dokumente nennen', 'state' => 'offen', 'reasons' => [
                ['key' => 'pdf_fehlt', 'text' => 'Nennen Sie die PDF-Dokumente, die nicht barrierefrei sind.'],
            ], 'web_url' => 'https://dienst.example/pruefung/' . self::LAUF . '/pdf'],
            ['key' => 'pflichtangaben', 'title' => 'Pflichtangaben der Erklärung', 'state' => 'spaeter', 'reasons' => [], 'web_url' => 'https://dienst.example/pruefung/' . self::LAUF . '/erklaerung/veroeffentlichen'],
        ];
        $daten['data']['done'] = 2;
        $daten['data']['total'] = 6;
        $daten['data']['next'] = 'pruefschritte';

        return $this->json($daten);
    }

    /**
     * @return array<string, mixed>
     */
    private function pruefschritteDaten(): array
    {
        $daten = $this->aufnahme('manual-checks');
        $daten['data'] = [
            ['id' => 11, 'rule' => 'manual.focus-order', 'title' => 'Ist die Fokusreihenfolge sinnvoll?', 'description' => 'Gehen Sie mit der Tabulatortaste durch die Seite.', 'success_criteria' => ['2.4.3'], 'evidence' => null, 'answer' => 'unanswered', 'note' => null, 'stale' => false, 'inherited' => false, 'answered_at' => null],
            ['id' => 12, 'rule' => 'manual.captions', 'title' => 'Haben aufgezeichnete Videos Untertitel?', 'description' => null, 'success_criteria' => ['1.2.2'], 'evidence' => 'Die Prüfung hat 2 Videos gefunden.', 'answer' => 'unanswered', 'note' => null, 'stale' => false, 'inherited' => false, 'answered_at' => null],
            ['id' => 13, 'rule' => 'manual.sign-language-easy-read', 'title' => 'Gibt es Erläuterungen in Gebärdensprache und Leichter Sprache?', 'description' => null, 'success_criteria' => [], 'evidence' => null, 'answer' => 'passed', 'note' => 'Seite /leichte-sprache', 'stale' => false, 'inherited' => true, 'answered_at' => '2026-11-01T10:00:00+00:00'],
        ];
        $daten['meta']['total'] = 3;
        $daten['meta']['open'] = 2;

        return $daten;
    }

    /**
     * @return array<string, ResponseInterface>
     */
    private function dienst(string $freigabe = 'erlaubt'): array
    {
        $stelle = $this->aufnahme('declaring-party');
        $stelle['data']['subdivision'] = null;
        $stelle['data']['subdivision_required'] = true;

        return [
            'GET ' . self::API . 'sites/01SITEA' => $this->website($freigabe),
            'GET ' . self::API . 'sites/01SITEA/declaration/path' => $this->weg(),
            'GET ' . self::API . 'scans/' . self::LAUF . '/manual-checks' => $this->json($this->pruefschritteDaten()),
            'GET ' . self::API . 'sites/01SITEA/declaring-party' => $this->json($stelle),
            'GET ' . self::API . 'sites/01SITEA/applicability' => $this->json($this->betroffenheit()),
            'GET ' . self::API . 'sites/01SITEA/declaration/draft' => $this->json($this->aufnahme('draft')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function betroffenheit(): array
    {
        return ['data' => [
            'asked' => true,
            'law' => 'BFSG',
            'title' => 'Fällt dieses Angebot unter das Gesetz?',
            'description' => 'Das Gesetz gilt nicht für jede Website, sondern für die dort abschließend aufgezählten Dienstleistungen.',
            'services' => [
                'question' => 'Wird über dieses Angebot eine dieser Dienstleistungen für Verbraucherinnen und Verbraucher erbracht?',
                'hint' => 'Mehrfachauswahl möglich. Katalog nach § 1 Abs. 3 BFSG.',
                'options' => [
                    ['key' => 'e_commerce', 'label' => 'Dienstleistungen im elektronischen Geschäftsverkehr', 'reference' => '§ 1 Abs. 3 Nr. 5 BFSG', 'selected' => false],
                    ['key' => 'banking', 'label' => 'Bankdienstleistungen für Verbraucher', 'reference' => '§ 1 Abs. 3 Nr. 3 BFSG', 'selected' => true],
                ],
            ],
            'micro_enterprise' => ['question' => 'Wir beschäftigen weniger als 10 Personen.', 'hint' => 'Kleinstunternehmen sind ausgenommen.', 'only_with_services' => true, 'value' => false],
            'answered_at' => null,
            'result' => null,
            'consequence' => 'Diese Frage ist noch offen.',
            'disclaimer' => 'Diese Einordnung beruht auf Ihren Angaben und ist keine Rechtsberatung.',
        ]];
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

    /**
     * Nur der Inhalt des Moduls, ohne Docheader.
     */
    private function inhalt(string $html): string
    {
        $koerper = $this->suche($html, '//div[contains(concat(" ", normalize-space(@class), " "), " module-body ")]');
        self::assertCount(1, $koerper, 'genau ein .module-body');

        return (string)$koerper[0]->ownerDocument?->saveHTML($koerper[0]);
    }

    /**
     * @return array<mixed>
     */
    private function gesendet(string $methode, string $pfad): array
    {
        $treffer = array_values(array_filter(
            $this->anfragen,
            static fn(RequestInterface $a): bool => $a->getMethod() === $methode && $a->getUri()->getPath() === self::API . $pfad,
        ));
        self::assertCount(1, $treffer, $methode . ' ' . $pfad . ' genau einmal');
        $rumpf = json_decode((string)$treffer[0]->getBody(), true);
        self::assertIsArray($rumpf);

        return $rumpf;
    }

    #[Test]
    public function uebersichtZeigtSchritteWieVomDienst(): void
    {
        $this->verbinde();
        $this->attrappe($this->dienst());
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        $inhalt = $this->text($this->inhalt($html));

        $zeilen = $this->suche($html, '//table[@id="barrierepruefung-schritte"]//tbody/tr');
        self::assertCount(6, $zeilen, 'jeder Schritt der API eine Zeile, in ihrer Reihenfolge');
        self::assertStringContainsString('Prüfschritte beantworten', $zeilen[1]->textContent);
        self::assertStringContainsString('offen', $zeilen[1]->textContent, 'Zustand als Wort');
        self::assertStringContainsString('Ist die Fokusreihenfolge sinnvoll?', $zeilen[1]->textContent, 'Gründe des Dienstes stehen dabei');
        self::assertStringContainsString('erledigt', $zeilen[0]->textContent);
        self::assertStringContainsString('vor der Freigabe', $zeilen[5]->textContent, '„spaeter“ ist kein Versäumnis');
        self::assertStringContainsString('2 von 6 Schritten erledigt', $inhalt);
        self::assertStringContainsString('Erklärung zur Barrierefreiheit (öffentliche Stelle)', $inhalt, 'Dokumentart aus der API');
        // Der Weiter-Knopf nennt das echte Ziel.
        $weiter = $this->suche($html, '//a[contains(@class,"btn-primary")]');
        self::assertCount(1, $weiter);
        self::assertStringContainsString('Prüfschritte beantworten', $weiter[0]->textContent);
        self::assertStringContainsString('schritt=pruefschritte', html_entity_decode($weiter[0]->getAttribute('href')));
        // Die Fähigkeiten kommen frisch vom Dienst.
        self::assertCount(1, array_filter($this->anfragen, static fn(RequestInterface $a): bool => $a->getUri()->getPath() === self::API . 'sites/01SITEA'), 'GET /sites/{id} beim Anzeigen');
    }

    #[Test]
    public function ohneSchreibrechtKeinFormular(): void
    {
        $this->verbinde();
        // Der Dienst sagt beim Anzeigen: das Recht fehlt (gespeichert war „erlaubt“).
        $this->attrappe($this->dienst('recht_fehlt'));
        $this->anmelden(self::ADMIN);

        foreach (['pruefschritte', 'verantwortliche_stelle', 'betroffenheit', 'pflichtangaben'] as $schritt) {
            $inhalt = $this->inhalt($this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'schritt' => $schritt])));
            self::assertStringNotContainsString('<form', $inhalt, $schritt . ': kein Formular');
            self::assertStringNotContainsString('disabled', $inhalt, $schritt . ': nichts ausgegraut');
            self::assertStringContainsString('Reiter „Einbindung“', $this->text($inhalt), $schritt . ': der Satz nennt den Weg');
        }
        self::assertSame([], $this->anfragenAn('/manual-checks'), 'ohne Recht werden die Fragen gar nicht erst geholt');
        self::assertSame('recht_fehlt', $this->get(Verbindungen::class)->fuerSite('a')?->freigaberecht());
    }

    #[Test]
    public function erloschenSagtNeuErteilen(): void
    {
        $this->verbinde('recht_erloschen');
        $this->attrappe($this->dienst('recht_erloschen'));
        $this->anmelden(self::ADMIN);

        $inhalt = $this->text($this->inhalt($this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'schritt' => 'verantwortliche_stelle']))));

        self::assertStringContainsString('neu erteilen', $inhalt);
        self::assertStringNotContainsString('<form', $inhalt);
    }

    #[Test]
    public function unbekannterSchrittMitWebUrl(): void
    {
        $this->verbinde();
        $this->attrappe($this->dienst());
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'schritt' => 'neu_im_dienst']));
        $inhalt = $this->text($this->inhalt($html));

        self::assertStringContainsString('Barrierefreie PDF-Dokumente nennen', $inhalt);
        self::assertStringContainsString('Nennen Sie die PDF-Dokumente, die nicht barrierefrei sind.', $inhalt);
        self::assertCount(1, $this->suche($html, '//a[@href="https://dienst.example/pruefung/' . self::LAUF . '/pdf"]'));
        self::assertStringNotContainsString('<form', $this->inhalt($html));

        // Auch in der Übersicht steht er und führt zu seiner Ansicht.
        $uebersicht = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a']));
        self::assertNotSame([], array_filter(
            $this->suche($uebersicht, '//table[@id="barrierepruefung-schritte"]//a'),
            static fn(\DOMElement $a): bool => str_contains(html_entity_decode($a->getAttribute('href')), 'schritt=neu_im_dienst'),
        ));
    }

    #[Test]
    public function bekannterOffenerSchrittNenntWebUrl(): void
    {
        $this->verbinde();
        $this->attrappe($this->dienst());
        $this->anmelden(self::ADMIN);

        // „land“ hat hier ein Feld, aber ein Grund ohne Feld muss im Dienst behebbar bleiben.
        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'schritt' => 'verantwortliche_stelle']));
        self::assertCount(1, $this->suche($html, '//a[@href="https://dienst.example/pruefung/' . self::LAUF . '/erklaerung#angaben"]'));
    }

    #[Test]
    public function subdivisionNurWennGezeigt(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['PUT ' . self::API . 'sites/01SITEA/declaring-party'] = $this->json($this->aufnahme('declaring-party'));
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        // Das Formular zeigt das Feld und trägt dazu den versteckten Merker.
        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'schritt' => 'verantwortliche_stelle']));
        self::assertCount(1, $this->suche($html, '//select[@name="subdivision"]'));
        self::assertCount(1, $this->suche($html, '//input[@type="hidden"][@name="subdivision_gezeigt"][@value="1"]'));
        self::assertCount(17, $this->suche($html, '//select[@name="subdivision"]/option[@value!=""]'), 'Länder aus der API');

        // Ohne Merker: der Schlüssel fehlt (beim Dienst „unverändert“).
        $this->aufrufen(self::ROUTE . '.stelle', [], ['site' => 'a', 'name' => 'Stadt A', 'applies_to' => 'public_body']);
        $rumpf = $this->gesendet('PUT', 'sites/01SITEA/declaring-party');
        self::assertArrayNotHasKey('subdivision', $rumpf);
        self::assertSame('Stadt A', $rumpf['name']);
        self::assertSame('public_body', $rumpf['applies_to']);
        self::assertSame('Ada Admin', $rumpf['actor_name']);

        // Mit Merker und leerem Feld: leer mitgeschickt (beim Dienst „leeren“).
        $this->attrappe($antworten);
        $this->aufrufen(self::ROUTE . '.stelle', [], ['site' => 'a', 'name' => 'Stadt A', 'applies_to' => 'public_body', 'subdivision_gezeigt' => '1', 'subdivision' => '']);
        self::assertSame('', $this->gesendet('PUT', 'sites/01SITEA/declaring-party')['subdivision'] ?? 'fehlt');

        // Mit Merker und Land.
        $this->attrappe($antworten);
        $this->aufrufen(self::ROUTE . '.stelle', [], ['site' => 'a', 'name' => '', 'applies_to' => 'public_body', 'subdivision_gezeigt' => '1', 'subdivision' => 'NI']);
        self::assertSame('NI', $this->gesendet('PUT', 'sites/01SITEA/declaring-party')['subdivision']);
    }

    #[Test]
    public function fehlerJeFeldBleibenUndWerteAuch(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['PUT ' . self::API . 'sites/01SITEA/declaration/details'] = $this->json([
            'message' => 'Die Angaben sind unvollständig.',
            'errors' => ['contact' => ['Bitte geben Sie einen Kontakt an.']],
        ], 422);
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'schritt' => 'pflichtangaben']));
        $felder = [];
        foreach ($this->suche($html, '//form[@id="barrierepruefung-angaben"]//*[@name]') as $feld) {
            $felder[$feld->getAttribute('name')] = $feld->tagName === 'textarea' ? $feld->textContent : $feld->getAttribute('value');
        }
        self::assertArrayHasKey('angaben[contact]', $felder);
        self::assertArrayNotHasKey('angaben[dgs_ls_url]', $felder, 'applies = false: nicht im Formular');
        self::assertArrayNotHasKey('angaben[service_description]', $felder);
        $felder['angaben[contact]'] = 'Rufen Sie an: 030 999';

        $antwort = $this->aufrufen(self::ROUTE . '.angaben', [], $this->verschachtelt($felder));
        self::assertSame(303, $antwort->getStatusCode());
        self::assertStringContainsString('schritt=pflichtangaben', $antwort->getHeaderLine('Location'));
        $gesendet = $this->gesendet('PUT', 'sites/01SITEA/declaration/details');
        self::assertSame('Rufen Sie an: 030 999', $gesendet['contact']);
        self::assertArrayHasKey('enforcement_body_name', $gesendet);

        $danach = $this->html($this->folgen($antwort));
        $dom = $this->dom($danach);
        $kontakt = $dom->getElementById('bp-contact');
        self::assertNotNull($kontakt);
        self::assertSame('true', $kontakt->getAttribute('aria-invalid'));
        self::assertContains('bp-contact-fehler', explode(' ', $kontakt->getAttribute('aria-describedby')));
        self::assertSame('Bitte geben Sie einen Kontakt an.', trim((string)$dom->getElementById('bp-contact-fehler')?->textContent));
        self::assertSame('Rufen Sie an: 030 999', trim($kontakt->textContent), 'die Eingabe steht wieder im Feld');
        self::assertTrue($kontakt->hasAttribute('autofocus'));
        self::assertCount(1, $this->suche($danach, '//*[@role="alert"]//a[@href="#bp-contact"]'), 'Fehlerliste verweist aufs Feld');

        // Genau einmal: beim nächsten Aufruf ist alles weg.
        $wieder = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'schritt' => 'pflichtangaben']));
        self::assertNull($this->dom($wieder)->getElementById('bp-contact-fehler'));
        self::assertSame('barrierefreiheit@beispiel.test', trim((string)$this->dom($wieder)->getElementById('bp-contact')?->textContent));
    }

    #[Test]
    public function pruefschritteAlsStapel(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['PUT ' . self::API . 'scans/' . self::LAUF . '/manual-checks'] = $this->json($this->pruefschritteDaten());
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'schritt' => 'pruefschritte']));
        // Antworten genau so, wie der Dienst sie anbietet (ohne „kann ich nicht beurteilen“).
        self::assertSame(['passed', 'failed', 'not_applicable', 'unanswered'], array_map(
            static fn(\DOMElement $e): string => $e->getAttribute('value'),
            $this->suche($html, '//input[@name="checks[11][answer]"]'),
        ));
        $felder = [];
        foreach ($this->suche($html, '//form[@id="barrierepruefung-pruefschritte"]//input[@type="hidden"]') as $feld) {
            $felder[$feld->getAttribute('name')] = $feld->getAttribute('value');
        }
        $felder['checks[11][answer]'] = 'passed';
        $felder['checks[11][note]'] = '';
        $felder['checks[12][answer]'] = 'not_applicable';
        $felder['checks[12][note]'] = 'Keine Videos mit Ton.';
        // Die übernommene Antwort ist geändert (Notiz).
        $felder['checks[13][answer]'] = 'passed';
        $felder['checks[13][note]'] = 'Seite /leichte-sprache und /gebaerdensprache';

        $antwort = $this->aufrufen(self::ROUTE . '.pruefschritte', [], $this->verschachtelt($felder));
        self::assertSame(303, $antwort->getStatusCode());

        self::assertCount(1, array_filter($this->anfragen, static fn(RequestInterface $a): bool => $a->getMethod() === 'PUT'), 'genau ein PUT');
        $rumpf = $this->gesendet('PUT', 'scans/' . self::LAUF . '/manual-checks');
        self::assertIsArray($rumpf['checks']);
        self::assertCount(3, $rumpf['checks']);
        self::assertSame(['id' => 12, 'answer' => 'not_applicable', 'note' => 'Keine Videos mit Ton.'], $rumpf['checks'][1]);
        self::assertSame('Ada Admin', $rumpf['actor_name']);
    }

    #[Test]
    public function unveraenderteAntwortenGehenNichtMit(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['PUT ' . self::API . 'scans/' . self::LAUF . '/manual-checks'] = $this->json($this->pruefschritteDaten());
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'schritt' => 'pruefschritte']));
        $felder = [];
        foreach ($this->suche($html, '//form[@id="barrierepruefung-pruefschritte"]//input[@type="hidden"]') as $feld) {
            $felder[$feld->getAttribute('name')] = $feld->getAttribute('value');
        }
        // Eine Antwort neu, die übernommene bleibt, wie sie war: sie würde sonst zur eben gegebenen.
        $felder += ['checks[11][answer]' => 'failed', 'checks[11][note]' => '', 'checks[12][answer]' => 'unanswered', 'checks[12][note]' => '', 'checks[13][answer]' => 'passed', 'checks[13][note]' => 'Seite /leichte-sprache'];

        $this->aufrufen(self::ROUTE . '.pruefschritte', [], $this->verschachtelt($felder));

        $rumpf = $this->gesendet('PUT', 'scans/' . self::LAUF . '/manual-checks');
        self::assertSame([['id' => 11, 'answer' => 'failed', 'note' => '']], $rumpf['checks']);
    }

    #[Test]
    public function fehlerImStapelLandetAnDerFrage(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        // Der Dienst zählt die Einträge des Stapels; gesendet wird nur die geänderte Frage 12 (Index 0).
        $antworten['PUT ' . self::API . 'scans/' . self::LAUF . '/manual-checks'] = $this->json([
            'message' => 'Die Notiz darf höchstens 2000 Zeichen lang sein.',
            'errors' => ['checks.0.note' => ['Die Notiz darf höchstens 2000 Zeichen lang sein.']],
        ], 422);
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'schritt' => 'pruefschritte']));
        $felder = [];
        foreach ($this->suche($html, '//form[@id="barrierepruefung-pruefschritte"]//input[@type="hidden"]') as $feld) {
            $felder[$feld->getAttribute('name')] = $feld->getAttribute('value');
        }
        $felder += ['checks[11][answer]' => 'unanswered', 'checks[11][note]' => '', 'checks[12][answer]' => 'failed', 'checks[12][note]' => str_repeat('x', 2001), 'checks[13][answer]' => 'passed', 'checks[13][note]' => 'Seite /leichte-sprache'];

        $danach = $this->html($this->folgen($this->aufrufen(self::ROUTE . '.pruefschritte', [], $this->verschachtelt($felder))));
        $dom = $this->dom($danach);
        $notiz = $dom->getElementById('bp-checks-12-note');
        self::assertNotNull($notiz);
        self::assertSame('true', $notiz->getAttribute('aria-invalid'));
        self::assertSame(str_repeat('x', 2001), $notiz->textContent);
        self::assertCount(1, $this->suche($danach, '//input[@name="checks[12][answer]"][@value="failed"][@checked]'), 'die gewählte Antwort bleibt');
    }

    #[Test]
    public function fachtexteAusDerApi(): void
    {
        $this->verbinde();
        $this->attrappe($this->dienst());
        $this->anmelden(self::ADMIN);

        $pruefschritte = $this->text($this->inhalt($this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'schritt' => 'pruefschritte']))));
        foreach (['Prüfschritte beantworten', 'Ist die Fokusreihenfolge sinnvoll?', 'Gehen Sie mit der Tabulatortaste durch die Seite.', 'Die Prüfung hat 2 Videos gefunden.', 'erfüllt', 'trifft nicht zu', 'Notiz — steht im Prüfbericht, nicht in der Erklärung'] as $satz) {
            self::assertStringContainsString($satz, $pruefschritte);
        }

        $stelle = $this->text($this->inhalt($this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'schritt' => 'verantwortliche_stelle']))));
        foreach (['Öffentliche Stelle', 'Privatwirtschaft und alle übrigen', 'Davon hängt ab, welches Dokument entsteht — und nach welchem Recht.', 'Niedersachsen', 'Beispiel GmbH'] as $satz) {
            self::assertStringContainsString($satz, $stelle);
        }

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'schritt' => 'betroffenheit']));
        $betroffen = $this->text($this->inhalt($html));
        foreach (['Wird über dieses Angebot eine dieser Dienstleistungen für Verbraucherinnen und Verbraucher erbracht?', 'Bankdienstleistungen für Verbraucher', '§ 1 Abs. 3 Nr. 3 BFSG', 'Wir beschäftigen weniger als 10 Personen.', 'Diese Frage ist noch offen.', 'keine Rechtsberatung'] as $satz) {
            self::assertStringContainsString($satz, $betroffen);
        }
        self::assertCount(1, $this->suche($html, '//input[@name="services[]"][@value="banking"][@checked]'));
    }

    #[Test]
    public function betroffenheitSendetDieGewaehltenDienstleistungen(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['PUT ' . self::API . 'sites/01SITEA/applicability'] = $this->json($this->betroffenheit());
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $this->aufrufen(self::ROUTE . '.betroffenheit', [], ['site' => 'a', 'services' => ['e_commerce'], 'micro_enterprise' => '1']);

        $rumpf = $this->gesendet('PUT', 'sites/01SITEA/applicability');
        self::assertSame(['e_commerce'], $rumpf['services']);
        self::assertTrue($rumpf['micro_enterprise']);

        $this->attrappe($antworten);
        $this->aufrufen(self::ROUTE . '.betroffenheit', [], ['site' => 'a']);
        $rumpf = $this->gesendet('PUT', 'sites/01SITEA/applicability');
        self::assertSame([], $rumpf['services'], 'nichts angekreuzt ist eine Antwort');
        self::assertFalse($rumpf['micro_enterprise']);
    }

    #[Test]
    public function erfolgFuehrtZumNaechstenSchritt(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['PUT ' . self::API . 'sites/01SITEA/applicability'] = $this->json($this->betroffenheit());
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $antwort = $this->aufrufen(self::ROUTE . '.betroffenheit', [], ['site' => 'a', 'services' => []]);
        self::assertStringContainsString('schritt=pruefschritte', $antwort->getHeaderLine('Location'));
        $html = $this->html($this->folgen($antwort));
        self::assertCount(1, $this->suche($html, '//*[@role="status"][contains(.,"Prüfschritte beantworten")]'));
    }

    #[Test]
    public function rechtErloschenBeimSpeichernAendertDenStand(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $antworten['PUT ' . self::API . 'sites/01SITEA/declaring-party'] = $this->json([
            'message' => 'Das Recht dieses Tokens ist erloschen.',
            'reason' => 'recht_erloschen',
            'url' => 'https://dienst.example/websites/01SITEA/einbindung',
        ], 403);
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $antwort = $this->aufrufen(self::ROUTE . '.stelle', [], ['site' => 'a', 'name' => 'Stadt A', 'applies_to' => 'public_body']);
        $verbindung = $this->get(Verbindungen::class)->fuerSite('a');
        self::assertNotNull($verbindung);
        self::assertSame('recht_erloschen', $verbindung->freigaberecht());
        self::assertFalse($verbindung->darfVorbereiten());

        // Die Anzeige danach fragt den Dienst wieder; der sagt jetzt auch „erloschen“.
        $antworten['GET ' . self::API . 'sites/01SITEA'] = $this->website('recht_erloschen');
        $this->attrappe($antworten);
        $html = $this->html($this->folgen($antwort));
        self::assertStringContainsString('Das Recht dieses Tokens ist erloschen.', $this->text($html));
        self::assertCount(1, $this->suche($html, '//a[@href="https://dienst.example/websites/01SITEA/einbindung"]'));
        self::assertStringNotContainsString('<form', $this->inhalt($html));
    }

    #[Test]
    public function ohneRechtSendetNichts(): void
    {
        $this->verbinde('recht_fehlt');
        $this->attrappe($this->dienst('recht_fehlt'));
        $this->anmelden(self::ADMIN);

        $antwort = $this->aufrufen(self::ROUTE . '.angaben', [], ['site' => 'a', 'angaben' => ['contact' => 'x']]);
        self::assertSame(303, $antwort->getStatusCode());
        self::assertSame([], array_filter($this->anfragen, static fn(RequestInterface $a): bool => $a->getMethod() === 'PUT'));
    }

    #[Test]
    public function nichtBeurteilbarWirdNieAngeboten(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        // Der Dienst liefert „kann ich nicht beurteilen“ mit (wie mit `umfang=alle`); hier stehen nur sperrende Fragen.
        $daten = $this->pruefschritteDaten();
        $daten['meta']['answers'] = ['passed' => 'erfüllt', 'failed' => 'nicht erfüllt', 'not_applicable' => 'trifft nicht zu', 'deferred' => 'kann ich nicht beurteilen', 'unanswered' => 'offen'];
        $antworten['GET ' . self::API . 'scans/' . self::LAUF . '/manual-checks'] = $this->json($daten);
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'schritt' => 'pruefschritte']));

        self::assertSame([], $this->suche($html, '//input[@type="radio"][@value="deferred"]'));
        self::assertStringNotContainsString('kann ich nicht beurteilen', $this->text($this->inhalt($html)));
        self::assertSame(['passed', 'failed', 'not_applicable', 'unanswered'], array_map(
            static fn(\DOMElement $e): string => $e->getAttribute('value'),
            $this->suche($html, '//input[@name="checks[11][answer]"]'),
        ));
    }

    #[Test]
    public function nochOffenePruefschritteBleibenAufDemSchritt(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        // Eine von zwei Fragen beantwortet: der Weg nennt danach weiter „pruefschritte“ als nächsten Schritt.
        $antworten['PUT ' . self::API . 'scans/' . self::LAUF . '/manual-checks'] = $this->json($this->pruefschritteDaten());
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $antwort = $this->aufrufen(self::ROUTE . '.pruefschritte', [], $this->verschachtelt([
            'site' => 'a', 'scan' => self::LAUF,
            'checks[11][answer]' => 'passed', 'checks[11][note]' => '', 'checks[11][vorher_antwort]' => 'unanswered', 'checks[11][vorher_notiz]' => '',
        ]));

        self::assertSame(303, $antwort->getStatusCode());
        self::assertStringContainsString('schritt=pruefschritte', $antwort->getHeaderLine('Location'), 'auf dem Schritt, nicht auf der Übersicht');
        $danach = $this->html($this->folgen($antwort));
        self::assertCount(1, $this->suche($danach, '//*[@role="status"][contains(.,"noch etwas offen")]'));
        self::assertCount(1, $this->suche($danach, '//form[@id="barrierepruefung-pruefschritte"]'));
    }

    #[Test]
    public function unbekannteAngabeStehtMitIhremSchluessel(): void
    {
        $this->verbinde();
        $antworten = $this->dienst();
        $entwurf = $this->aufnahme('draft');
        $entwurf['data']['fields'][] = ['key' => 'pdf_hinweis', 'value' => 'Alte PDFs', 'applies' => true, 'required' => false, 'max' => 255, 'type' => 'text'];
        $antworten['GET ' . self::API . 'sites/01SITEA/declaration/draft'] = $this->json($entwurf);
        $antworten['PUT ' . self::API . 'sites/01SITEA/declaration/details'] = $this->json($entwurf);
        $this->attrappe($antworten);
        $this->anmelden(self::ADMIN);

        $html = $this->html($this->aufrufen(self::ROUTE, ['site' => 'a', 'schritt' => 'pflichtangaben']));
        $feld = $this->dom($html)->getElementById('bp-pdf_hinweis');
        self::assertNotNull($feld, 'ein Feld, das diese Fassung nicht kennt, erscheint trotzdem');
        self::assertSame('Alte PDFs', $feld->getAttribute('value'));
        $beschriftung = $this->suche($html, '//label[@for="bp-pdf_hinweis"]');
        self::assertCount(1, $beschriftung);
        self::assertSame('pdf_hinweis', trim($beschriftung[0]->textContent), 'mit dem Schlüssel als Beschriftung');

        $felder = [];
        foreach ($this->suche($html, '//form[@id="barrierepruefung-angaben"]//*[@name]') as $element) {
            $felder[$element->getAttribute('name')] = $element->tagName === 'textarea' ? $element->textContent : $element->getAttribute('value');
        }
        $felder['angaben[pdf_hinweis]'] = 'Archiv-PDFs vor 2020';
        $this->aufrufen(self::ROUTE . '.angaben', [], $this->verschachtelt($felder));

        self::assertSame('Archiv-PDFs vor 2020', $this->gesendet('PUT', 'sites/01SITEA/declaration/details')['pdf_hinweis'] ?? null, 'und wird gespeichert');
    }

    #[Test]
    public function fremdeSiteWirdAbgewiesen(): void
    {
        $this->verbinde();
        $this->attrappe($this->dienst());
        $this->anmelden(self::REDAKTEURIN);

        // Site b liegt nicht im Web-Mount; eine unbekannte Kennung ebenso wenig.
        foreach (['pruefschritte', 'stelle', 'betroffenheit', 'angaben'] as $aktion) {
            self::assertSame(403, $this->aufrufen(self::ROUTE . '.' . $aktion, [], ['site' => 'gibt-es-nicht'])->getStatusCode(), $aktion);
        }
        self::assertSame([], $this->anfragen);
    }

    /**
     * Formularfelder `a[b][c]` wie PHP sie im Rumpf zerlegt.
     *
     * @param array<string, string> $felder
     * @return array<string, mixed>
     */
    private function verschachtelt(array $felder): array
    {
        parse_str(http_build_query($felder), $rumpf);

        /** @var array<string, mixed> $rumpf */
        return $rumpf;
    }
}
